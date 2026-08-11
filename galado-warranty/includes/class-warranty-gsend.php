<?php
/**
 * Mirror the warranty marketing opt-in into G-Send instead of Klaviyo.
 *
 * Klaviyo is cancelled in September. When the key goes, GWARR_Klaviyo bails at the
 * top and the whole approval flow keeps returning normally while nobody is actually
 * subscribed, the silent success this class exists to remove. So this path is
 * deliberately independent of the Klaviyo key: it reads its own secret and runs on
 * its own.
 *
 * Which channel gets the opt-in is the `subscribe_channel` setting, default
 * `klaviyo`, so deploying this file changes nothing until someone flips it.
 *
 * Best-effort like everything else on the approval path. The customer already has
 * their coupon and warranty dates by the time this runs; a subscribe failure must
 * never surface to them.
 */

if (!defined('ABSPATH')) exit;

class GWARR_GSend {

    const EVENTS_URL = 'https://club.galado.com.my/send/events';

    /** Which channel the marketing opt-in goes to. */
    public static function channel() {
        $settings = get_option('gwarr_settings', []);
        $channel  = strtolower((string) ($settings['subscribe_channel'] ?? 'klaviyo'));
        return $channel === 'gsend' ? 'gsend' : 'klaviyo';
    }

    /**
     * The shared home for EVENTS_SECRET on the WordPress side. The store signup
     * capture uses the same option name, so the secret lives in one row rather
     * than being copied per plugin.
     */
    public static function secret() {
        return (string) get_option('galado_gsend_events_secret', '');
    }

    /**
     * Post `contact_subscribed` for an approved registration. No-op unless the
     * channel is flipped and the customer actually ticked the box.
     */
    public static function maybe_subscribe($row) {
        if (self::channel() !== 'gsend') {
            return;
        }

        // Being a customer is not consent. Only the explicit tick counts.
        if (empty($row->marketing_consent)) {
            return;
        }

        $user = get_userdata((int) $row->user_id);
        if (!$user || !$user->user_email) {
            self::log('skipped: registration ' . (int) $row->id . ' has no customer email');
            return;
        }

        $secret = self::secret();
        if ($secret === '') {
            self::log('channel is gsend but galado_gsend_events_secret is empty, dropping subscribe for registration ' . (int) $row->id);
            return;
        }

        $email = strtolower(trim($user->user_email));

        // Signed over the RAW body, so the exact string signed is the exact string
        // sent. Never re-encode between the two.
        $payload = wp_json_encode([
            'event_id' => self::event_id($email, $row),
            'type'     => 'contact_subscribed',
            'email'    => $email,
            'meta'     => [
                'first_name'   => self::first_name($user),
                'source'       => 'warranty',
                'consent_text' => gwarr_consent_text(),
                'consent_ts'   => self::consent_ts($row),
            ],
        ]);

        $response = wp_remote_post(self::EVENTS_URL, [
            'timeout' => 10,
            'headers' => [
                'content-type'      => 'application/json',
                'x-gsend-signature' => hash_hmac('sha256', $payload, $secret),
            ],
            'body'    => $payload,
        ]);

        if (is_wp_error($response)) {
            self::log('post failed: ' . $response->get_error_message());
            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            self::log('rejected http ' . $status . ': ' . wp_remote_retrieve_body($response));
        }
    }

    /**
     * One key per registration, not per person.
     *
     * G-Send only writes the contacts row when the event INSERT actually inserts,
     * so a key that never changes would silently discard a later opt-in. Approval
     * is a one-shot event per registration, so keying on the registration id makes
     * a retry of the same approval idempotent while a genuine second registration
     * still reaches the ledger.
     */
    private static function event_id($email, $row) {
        return 'warranty_' . hash('sha256', $email) . '_' . (int) $row->id;
    }

    /**
     * When the customer consented, which is when they registered, not now.
     * Approval can be days later, and the ledger has to record the moment they
     * actually ticked the box.
     *
     * created_at is written by PHP in site time (GWARR_DB::insert), so treating it
     * as site-local here is correct by construction rather than by assumption.
     * Rows written before v1.10.1 came from the column's CURRENT_TIMESTAMP default
     * and are only right if the MySQL server runs on site time; Diagnostics reports
     * whether it does.
     */
    private static function consent_ts($row) {
        $created = isset($row->created_at) ? (string) $row->created_at : '';
        if ($created === '' || $created === '0000-00-00 00:00:00') {
            return gmdate('c');
        }
        return gmdate('c', strtotime(get_gmt_from_date($created) . ' UTC'));
    }

    private static function first_name($user) {
        $name = trim((string) ($user->first_name ?: $user->display_name));
        if ($name === '') {
            return null;
        }
        $parts = preg_split('/\s+/', $name, 2);
        return $parts[0] ?: null;
    }

    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[galado-warranty/gsend] ' . $message);
        }
    }
}
