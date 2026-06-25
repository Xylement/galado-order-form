<?php
/**
 * Auto-capture WooCommerce orders as per-item warranty rows.
 *
 * When an order reaches Processing or Completed, each line item becomes its
 * own active warranty (source=website), with tier-aware coverage from the
 * order date. Idempotent on the WC line-item id, so re-firing the hook,
 * status changes, and the backfill never duplicate.
 *
 * A one-time backfill (admin-triggered) walks recent orders in the background
 * via WP-Cron, in small batches, so it can't time out or block a request.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Orders {

    const BACKFILL_HOOK   = 'gwarr_backfill_orders';
    const BACKFILL_STATE  = 'gwarr_backfill_state';
    const BACKFILL_MONTHS = 12;   // how far back the recent backfill reaches
    const BACKFILL_BATCH  = 30;   // orders per cron tick

    public static function init() {
        // Capture on the two statuses that mean "the customer has it".
        add_action('woocommerce_order_status_processing', [__CLASS__, 'capture_order']);
        add_action('woocommerce_order_status_completed',  [__CLASS__, 'capture_order']);

        // Background backfill worker.
        add_action(self::BACKFILL_HOOK, [__CLASS__, 'run_backfill_batch']);
    }

    /**
     * Create per-item warranty rows for a WooCommerce order. Safe to call
     * repeatedly — each item is idempotent on its line-item id.
     */
    public static function capture_order($order_id) {
        if (!function_exists('wc_get_order')) {
            return 0;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return 0;
        }

        $user_id = (int) $order->get_user_id(); // 0 for guest checkouts
        $email   = (string) $order->get_billing_email();

        // Tier-aware coverage (Black Club = 12, else configured standard).
        $months = function_exists('galado_warranty_months_for_email')
            ? max(1, (int) galado_warranty_months_for_email($email))
            : max(1, (int) (get_option('gwarr_settings', [])['warranty_months'] ?? 6));

        $created     = $order->get_date_created();
        $purchase_ts = $created ? $created->getTimestamp() : time();
        $purchase    = gmdate('Y-m-d', $purchase_ts);
        $ends        = gmdate('Y-m-d', strtotime('+' . $months . ' months', $purchase_ts));

        $count = 0;
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            // Skip non-physical / virtual items — no warranty on those.
            if ($product && $product->is_virtual()) {
                continue;
            }

            $name = $item->get_name(); // includes variation attributes
            $id   = GWARR_DB::insert_website_item([
                'user_id'       => $user_id,
                'wc_order_id'   => (int) $order_id,
                'wc_item_id'    => (int) $item_id,
                'product_text'  => $name,
                'purchase_date' => $purchase,
                'warranty_ends' => $ends,
            ]);
            if ($id) {
                $count++;
            }
        }
        return $count;
    }

    // -------------------------------------------------------------------------
    // Backfill
    // -------------------------------------------------------------------------

    public static function get_backfill_state() {
        $state = get_option(self::BACKFILL_STATE, []);
        return is_array($state) ? wp_parse_args($state, self::default_backfill_state()) : self::default_backfill_state();
    }

    private static function default_backfill_state() {
        return [
            'status'    => 'idle',   // idle | running | done
            'page'      => 1,
            'processed' => 0,
            'created'   => 0,
            'started'   => '',
            'finished'  => '',
        ];
    }

    /**
     * Kick off (or restart) the recent-orders backfill.
     */
    public static function start_backfill() {
        update_option(self::BACKFILL_STATE, array_merge(self::default_backfill_state(), [
            'status'  => 'running',
            'started' => current_time('mysql'),
        ]), false);

        if (!wp_next_scheduled(self::BACKFILL_HOOK)) {
            wp_schedule_single_event(time(), self::BACKFILL_HOOK);
        }
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Process one batch of recent orders, then reschedule until done. Time-
     * boxed by batch size; each item insert is idempotent so partial runs and
     * retries are harmless.
     */
    public static function run_backfill_batch() {
        if (!function_exists('wc_get_orders')) {
            return;
        }
        $state = self::get_backfill_state();
        if ($state['status'] !== 'running') {
            return;
        }

        $after = gmdate('Y-m-d', strtotime('-' . self::BACKFILL_MONTHS . ' months'));

        $orders = wc_get_orders([
            'limit'        => self::BACKFILL_BATCH,
            'page'         => (int) $state['page'],
            'orderby'      => 'date',
            'order'        => 'ASC',
            'status'       => ['wc-processing', 'wc-completed'],
            'date_created' => '>=' . $after,
            'return'       => 'objects',
        ]);

        if (empty($orders)) {
            $state['status']   = 'done';
            $state['finished'] = current_time('mysql');
            update_option(self::BACKFILL_STATE, $state, false);
            return;
        }

        foreach ($orders as $order) {
            $state['created'] += (int) self::capture_order($order->get_id());
            $state['processed']++;
        }
        $state['page']++;
        update_option(self::BACKFILL_STATE, $state, false);

        // Queue the next batch.
        wp_schedule_single_event(time() + 5, self::BACKFILL_HOOK);
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }
}
