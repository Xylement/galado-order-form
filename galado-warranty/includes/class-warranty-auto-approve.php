<?php
/**
 * Auto-approval glue: connects the local sheet cache to the approval pipeline.
 *
 * Two trigger points:
 *   1. Immediately after a customer submits the form (synchronous look-up
 *      against the local cache — never against the live Sheets API)
 *   2. After every WP-Cron sheet sync — sweep any still-pending registrations
 *      that might now match a freshly-cached order
 */

if (!defined('ABSPATH')) exit;

class GWARR_Auto_Approve {

    /**
     * Attempt to auto-approve a registration. Returns true on success.
     */
    public static function try_for($registration_id) {
        $registration_id = (int) $registration_id;
        if (!$registration_id) return false;

        $row = GWARR_DB::find($registration_id);
        if (!$row || $row->status !== 'pending') {
            return false;
        }

        $hit = self::lookup_cache($row->marketplace, $row->order_number);
        if (!$hit) return false;

        // Backfill product_text from the sheet if we don't have one yet
        // (we removed the customer-facing product field in v1.0.2).
        if (empty($row->product_text) && !empty($hit->product_name)) {
            global $wpdb;
            $wpdb->update(
                GWARR_DB::table(),
                ['product_text' => (string) $hit->product_name],
                ['id' => $registration_id],
                ['%s'],
                ['%d']
            );
            $row = GWARR_DB::find($registration_id); // re-read with product filled in
        }

        $note = sprintf(
            'Auto-approved from sheet (%s row %d)',
            $hit->sheet_tab ?: 'unknown tab',
            (int) $hit->raw_row
        );

        $result = GWARR_Approval::approve($registration_id, $hit->purchase_date, $note);
        if (is_wp_error($result)) {
            self::log('approve() failed for #' . $registration_id . ': ' . $result->get_error_message());
            return false;
        }

        return true;
    }

    /**
     * After a fresh sheet sync, retry any pending registrations that may now
     * have a matching cache entry. Bounded by a sensible row cap so a wedged
     * sync doesn't blow through hundreds of approvals at once.
     */
    public static function sweep_pending($limit = 100) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id FROM ' . GWARR_DB::table() . " WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
            (int) $limit
        ));

        $approved = 0;
        foreach ((array) $rows as $r) {
            if (self::try_for((int) $r->id)) {
                $approved++;
            }
        }

        if ($approved > 0) {
            self::log("Swept pending → auto-approved {$approved} registration(s).");
        }

        return $approved;
    }

    private static function lookup_cache($marketplace, $order_number) {
        global $wpdb;
        $cache = GWARR_Sheet_Sync::cache_table();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$cache} WHERE marketplace = %s AND order_number = %s LIMIT 1",
            (string) $marketplace,
            (string) $order_number
        ));
    }

    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[galado-warranty/auto-approve] ' . $message);
        }
    }
}
