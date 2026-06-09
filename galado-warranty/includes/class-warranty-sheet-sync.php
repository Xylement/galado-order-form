<?php
/**
 * Pulls the Numeris orders sheet into a local cache table.
 *
 * Run hourly via WP-Cron — the form-submit auto-approve path queries the
 * local cache (cheap), never the live Sheets API (slow + rate-limited).
 *
 * Sheet layout (from the user):
 *   - One tab per month (variable count, variable names)
 *   - Column A: marketplace platform name (Shopee / Lazada / TikTok…)
 *   - Column B: product name
 *   - Column E: marketplace order ID
 *   - Column J: order/purchase date
 */

if (!defined('ABSPATH')) exit;

class GWARR_Sheet_Sync {

    const CRON_HOOK   = 'gwarr_sheet_sync';
    const CACHE_TABLE = 'galado_warranty_sheet_cache';
    const COLS_RANGE  = 'A:J';

    /**
     * Schedule the recurring sync if it isn't already scheduled.
     */
    public static function ensure_scheduled() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
    }

    public static function cache_table() {
        global $wpdb;
        return $wpdb->prefix . self::CACHE_TABLE;
    }

    /**
     * Resolve the service account JSON from (in order):
     *   1. wp-config constant GALADO_GSHEETS_SERVICE_ACCOUNT_JSON — either a
     *      filesystem path to the JSON file, or the JSON content inline.
     *   2. Plugin setting `service_account_json` (paste-in textarea).
     */
    public static function get_service_account_json() {
        if (defined('GALADO_GSHEETS_SERVICE_ACCOUNT_JSON')) {
            $val = (string) constant('GALADO_GSHEETS_SERVICE_ACCOUNT_JSON');
            if ($val !== '' && is_file($val) && is_readable($val)) {
                return (string) file_get_contents($val);
            }
            return $val;
        }
        $settings = get_option('gwarr_settings', []);
        return isset($settings['service_account_json']) ? (string) $settings['service_account_json'] : '';
    }

    public static function get_sheet_id() {
        $settings = get_option('gwarr_settings', []);
        return isset($settings['sheet_id']) ? (string) $settings['sheet_id'] : '';
    }

    /**
     * One sync pass: list all tabs, read each, upsert into the cache, sweep
     * pending registrations for newly-cached matches.
     *
     * @param bool $manual True when triggered from the admin "Sync now" button —
     *                     surfaces errors as WP_Error so the UI can show them.
     */
    public static function run($manual = false) {
        $sheet_id = self::get_sheet_id();
        $sa_json  = self::get_service_account_json();

        if ($sheet_id === '' || $sa_json === '') {
            $msg = 'Sheet ID or service account credentials are not configured.';
            self::log($msg);
            return $manual ? new WP_Error('gwarr_not_configured', $msg) : false;
        }

        $api = new GWARR_Sheet_API($sa_json);
        if (!$api->is_configured()) {
            $msg = 'Service account JSON is malformed (missing client_email or private_key).';
            self::log($msg);
            return $manual ? new WP_Error('gwarr_sa_bad', $msg) : false;
        }

        $tabs = $api->list_tabs($sheet_id);
        if (is_wp_error($tabs)) {
            self::log('list_tabs failed: ' . $tabs->get_error_message());
            return $manual ? $tabs : false;
        }

        global $wpdb;
        $table     = self::cache_table();
        $sync_time = current_time('mysql');
        $started   = microtime(true);

        $stats = [
            'tabs_seen'   => count($tabs),
            'rows_seen'   => 0,
            'rows_kept'   => 0,
            'rows_failed' => 0,
            'tab_errors'  => [],
        ];

        foreach ($tabs as $tab) {
            $range = "'" . str_replace("'", "''", $tab) . "'!" . self::COLS_RANGE;
            $rows  = $api->read_range($sheet_id, $range);
            if (is_wp_error($rows)) {
                $stats['tab_errors'][] = $tab . ': ' . $rows->get_error_message();
                self::log("read_range $tab failed: " . $rows->get_error_message());
                continue;
            }

            foreach ($rows as $idx => $row) {
                if (!is_array($row)) continue;
                $stats['rows_seen']++;

                // Skip header rows — typically row 0 with non-numeric values everywhere.
                if ($idx === 0 && self::looks_like_header($row)) {
                    continue;
                }

                $raw_marketplace = isset($row[0]) ? trim((string) $row[0]) : '';
                $product         = isset($row[1]) ? trim((string) $row[1]) : '';
                $order_number    = isset($row[4]) ? trim((string) $row[4]) : '';
                $date_raw        = isset($row[9]) ? trim((string) $row[9]) : '';

                if ($order_number === '' || $raw_marketplace === '') {
                    continue;
                }

                $marketplace = self::normalize_marketplace($raw_marketplace);
                if ($marketplace === '') {
                    // Not one of the marketplaces we register warranties for —
                    // skip silently (e.g. Woo direct orders also sit in the sheet).
                    continue;
                }

                $purchase_date = self::parse_date($date_raw);
                if ($purchase_date === '') {
                    $stats['rows_failed']++;
                    continue;
                }

                $ok = $wpdb->replace(
                    $table,
                    [
                        'marketplace'     => $marketplace,
                        'order_number'    => $order_number,
                        'product_name'    => $product,
                        'purchase_date'   => $purchase_date,
                        'raw_marketplace' => $raw_marketplace,
                        'sheet_tab'       => (string) $tab,
                        'raw_row'         => $idx + 1,
                        'synced_at'       => $sync_time,
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
                );

                if ($ok === false) {
                    $stats['rows_failed']++;
                } else {
                    $stats['rows_kept']++;
                }
            }
        }

        update_option('gwarr_last_sheet_sync', $sync_time, false);
        update_option('gwarr_last_sheet_sync_stats', $stats, false);

        // After cache is fresh, retry pending registrations that may now match.
        if (class_exists('GWARR_Auto_Approve')) {
            GWARR_Auto_Approve::sweep_pending();
        }

        $duration = round(microtime(true) - $started, 2);
        self::log("Sync complete in {$duration}s — tabs:{$stats['tabs_seen']} kept:{$stats['rows_kept']} failed:{$stats['rows_failed']}");

        return $stats;
    }

    /**
     * Convert a raw marketplace string from the sheet into one of our slugs.
     * Returns '' for marketplaces we don't track (e.g. direct Woo orders).
     */
    public static function normalize_marketplace($raw) {
        $raw = strtolower(trim((string) $raw));
        if ($raw === '') return '';

        if (strpos($raw, 'shopee') !== false) return 'shopee';
        if (strpos($raw, 'lazada') !== false) return 'lazada';
        if (strpos($raw, 'tiktok') !== false || strpos($raw, 'tik tok') !== false) return 'tiktok';

        return '';
    }

    /**
     * Best-effort date parser. The sheet may return ISO strings, "9/6/2026",
     * "06/09/2026", "Jun 9, 2026", etc. Falls back to '' on failure.
     */
    public static function parse_date($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return '';

        // Already in MySQL date shape.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        // d/m/y first (Malaysian convention matches the rest of GALADO's setup).
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $raw, $m)) {
            $d = (int) $m[1];
            $month = (int) $m[2];
            $y = (int) $m[3];
            if ($y < 100) $y += 2000;
            if (checkdate($month, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $month, $d);
            }
        }

        $ts = strtotime($raw);
        if ($ts !== false) {
            return gmdate('Y-m-d', $ts);
        }

        return '';
    }

    /**
     * Heuristic: a header row has zero numeric cells in columns we care about
     * (order ID is numeric/alphanumeric on data rows; "Order ID" header is text).
     */
    private static function looks_like_header($row) {
        $candidates = [];
        foreach ([0, 4, 9] as $col) {
            if (isset($row[$col])) {
                $candidates[] = strtolower(trim((string) $row[$col]));
            }
        }
        foreach ($candidates as $c) {
            if ($c === 'order id' || $c === 'order number' || $c === 'marketplace' || $c === 'platform' || $c === 'date' || $c === 'order date') {
                return true;
            }
        }
        return false;
    }

    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[galado-warranty/sheet-sync] ' . $message);
        }
    }
}
