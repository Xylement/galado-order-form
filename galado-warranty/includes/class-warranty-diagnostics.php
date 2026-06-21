<?php
/**
 * One-click diagnostics for the registration pipeline.
 *
 * Times the external calls a registration makes (Club coverage lookup, Club
 * webhook, wp_mail, Klaviyo) and reports environment facts (deployed plugin
 * version, PHP-FPM fastcgi_finish_request, OPcache timestamp validation) so
 * we can pinpoint why a submit is slow without digging through server logs.
 *
 * Read-only / side-effect-free: the webhook test sends an intentionally
 * invalid body to get a fast 400 (measures latency, grants nothing), and the
 * coverage lookup is a read. The only real write is one test email to the
 * site admin.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Diagnostics {

    public static function run() {
        $settings    = get_option('gwarr_settings', []);
        $admin_email = (string) get_option('admin_email');

        $env     = [];
        $timings = [];
        $notes   = [];

        // ---- Environment facts ----
        $env['Deployed plugin version'] = defined('GWARR_VERSION') ? GWARR_VERSION : 'unknown';
        $env['PHP version']             = PHP_VERSION;
        $env['fastcgi_finish_request']  = function_exists('fastcgi_finish_request') ? 'available' : 'NOT available';

        if (!function_exists('fastcgi_finish_request')) {
            $notes[] = 'fastcgi_finish_request is unavailable, so the email/Klaviyo deferral runs inline — those calls block the customer. (Usually available on PHP-FPM.)';
        }

        if (function_exists('opcache_get_configuration')) {
            $cfg      = @opcache_get_configuration();
            $status   = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
            $enabled  = is_array($status) && !empty($status['opcache_enabled']);
            $validate = (is_array($cfg) && isset($cfg['directives']['opcache.validate_timestamps']))
                ? (bool) $cfg['directives']['opcache.validate_timestamps'] : null;
            $freq = (is_array($cfg) && isset($cfg['directives']['opcache.revalidate_freq']))
                ? (int) $cfg['directives']['opcache.revalidate_freq'] : null;

            $env['OPcache'] = ($enabled ? 'enabled' : 'disabled')
                . ' · validate_timestamps=' . ($validate === null ? '?' : ($validate ? 'on' : 'OFF'))
                . ' · revalidate_freq=' . ($freq === null ? '?' : $freq . 's');

            if ($enabled && $validate === false) {
                $notes[] = 'OPcache validate_timestamps is OFF — deployed PHP changes do NOT take effect until OPcache is flushed / PHP-FPM is restarted. If "Deployed plugin version" above is older than expected, this is why.';
            }
        } else {
            $env['OPcache'] = 'not present';
        }

        $env['GALADO_CLUB_URL']           = defined('GALADO_CLUB_URL') ? 'defined' : 'NOT defined';
        $env['GALADO_CLUB_BRIDGE_SECRET'] = defined('GALADO_CLUB_BRIDGE_SECRET') ? 'defined' : 'NOT defined';
        $env['Klaviyo API key']           = !empty($settings['klaviyo_api_key']) ? 'set' : 'empty';
        $env['Auto-approve']              = !empty($settings['auto_approve']) ? 'on' : 'off';

        // ---- Timed checks ----
        global $wpdb;

        $timings[] = self::time('DB read (warranties table)', function () use ($wpdb) {
            $wpdb->get_var('SELECT COUNT(*) FROM ' . GWARR_DB::table());
            return 'ok';
        });

        if (class_exists('GWARR_Sheet_Sync')) {
            $timings[] = self::time('Sheet cache lookup (local)', function () use ($wpdb) {
                $n = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . GWARR_Sheet_Sync::cache_table());
                return $n . ' rows cached';
            });
        }

        if (defined('GALADO_CLUB_URL') && defined('GALADO_CLUB_BRIDGE_SECRET')) {
            // Coverage lookup is what blocks the customer during auto-approval.
            $timings[] = self::time('Club coverage API (blocking call)', function () use ($admin_email) {
                if (function_exists('galado_warranty_months_for_email')) {
                    delete_transient('gwarr_months_' . md5(strtolower(trim($admin_email)))); // force a live call
                    return 'months=' . galado_warranty_months_for_email($admin_email);
                }
                return 'helper missing';
            });

            // Webhook reachability — invalid body → fast 400, grants nothing.
            $timings[] = self::time('Club webhook round-trip', function () {
                $resp = wp_remote_post(rtrim(GALADO_CLUB_URL, '/') . '/webhooks/warranty', [
                    'timeout'  => 20,
                    'blocking' => true,
                    'headers'  => [
                        'content-type'         => 'application/json',
                        'x-club-bridge-secret' => GALADO_CLUB_BRIDGE_SECRET,
                    ],
                    'body'     => '{}', // missing required fields on purpose
                ]);
                if (is_wp_error($resp)) {
                    return 'ERROR: ' . $resp->get_error_message();
                }
                return 'HTTP ' . wp_remote_retrieve_response_code($resp) . ' (400 expected — latency only)';
            });
        }

        // wp_mail — prime suspect for multi-second/minute hangs (slow SMTP).
        $timings[] = self::time('wp_mail (test email to admin)', function () use ($admin_email) {
            $ok = wp_mail(
                $admin_email,
                '[GALADO] Warranty diagnostics test',
                'This is a timing test from the warranty plugin diagnostics. Safe to ignore.'
            );
            return $ok ? 'sent ok' : 'wp_mail returned false';
        });

        if (!empty($settings['klaviyo_api_key'])) {
            $timings[] = self::time('Klaviyo API round-trip', function () use ($settings) {
                $resp = wp_remote_get('https://a.klaviyo.com/api/accounts/', [
                    'timeout' => 20,
                    'headers' => [
                        'Authorization' => 'Klaviyo-API-Key ' . $settings['klaviyo_api_key'],
                        'revision'      => '2024-10-15',
                        'accept'        => 'application/json',
                    ],
                ]);
                if (is_wp_error($resp)) {
                    return 'ERROR: ' . $resp->get_error_message();
                }
                return 'HTTP ' . wp_remote_retrieve_response_code($resp);
            });
        }

        // ---- Verdict: flag the slowest call ----
        $slowest = null;
        foreach ($timings as $t) {
            if ($slowest === null || $t['ms'] > $slowest['ms']) {
                $slowest = $t;
            }
        }
        if ($slowest && $slowest['ms'] >= 3000) {
            $notes[] = 'Slowest step: "' . $slowest['label'] . '" at ' . self::fmt($slowest['ms'])
                . '. If this is wp_mail, your SMTP is slow/misconfigured; if it is a Club/Klaviyo call, that API is slow. '
                . 'Whichever it is should be deferred (v1.3.2+) so it never blocks the customer — confirm the deployed version above is current.';
        }

        return ['env' => $env, 'timings' => $timings, 'notes' => $notes];
    }

    private static function time($label, $fn) {
        $start  = microtime(true);
        $result = '';
        try {
            $result = (string) $fn();
        } catch (\Throwable $e) {
            $result = 'EXCEPTION: ' . $e->getMessage();
        }
        return [
            'label'  => $label,
            'ms'     => (microtime(true) - $start) * 1000,
            'result' => $result,
        ];
    }

    public static function fmt($ms) {
        if ($ms >= 1000) {
            return number_format($ms / 1000, 2) . ' s';
        }
        return number_format($ms, 0) . ' ms';
    }
}
