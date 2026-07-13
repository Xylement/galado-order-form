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
        // Never auto-register warranties for our OWN internal shipping-fee
        // orders (created for warranty-claim replacement shipping). They now
        // carry a "Replacement shipping" product line item — needed so the
        // Razer/Molpay pay page renders — which would otherwise be captured as
        // a bogus warranty when the order is paid.
        if (method_exists($order, 'get_created_via') && 'galado-warranty' === $order->get_created_via()) {
            return 0;
        }

        $email   = strtolower(trim((string) $order->get_billing_email()));
        $user_id = (int) $order->get_user_id(); // 0 for guest checkouts

        // Guest checkout? Attach to an existing account with the same email so
        // the warranty isn't orphaned (a logged-in/registered customer then
        // sees it). If no account yet, it stays user_id=0 and is linked later
        // on login/registration via GWARR_DB::link_website_orphans().
        if ($user_id === 0 && $email !== '' && function_exists('get_user_by')) {
            $u = get_user_by('email', $email);
            if ($u) {
                $user_id = (int) $u->ID;
            }
        }

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
            // Isolate each line item: a single malformed item/product can't
            // abort the order (which, during backfill, would otherwise discard
            // the whole batch's progress and retry the same failing one).
            try {
                $product = $item->get_product();
                // Skip non-physical / virtual items — no warranty on those.
                if ($product && $product->is_virtual()) {
                    continue;
                }

                $parts  = self::components($item);
                $common = [
                    'user_id'       => $user_id,
                    'wc_order_id'   => (int) $order_id,
                    'wc_item_id'    => (int) $item_id,
                    'billing_email' => $email,
                    'purchase_date' => $purchase,
                    'warranty_ends' => $ends,
                ];

                // The base product is its own warranty row …
                if (GWARR_DB::insert_website_item($common + ['product_text' => $parts['base']])) {
                    $count++;
                }
                // … and each paid add-on product gets its own independently-
                // claimable row, distinguished by a 'a{n}' order_number suffix.
                foreach ($parts['addons'] as $i => $addon) {
                    if (GWARR_DB::insert_website_item($common + ['suffix' => 'a' . ($i + 1), 'product_text' => $addon])) {
                        $count++;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[galado-warranty] capture item ' . $item_id . ' of order ' . $order_id . ' failed: ' . $e->getMessage());
            }
        }
        return $count;
    }

    /**
     * Split a line item into its claimable components:
     *   - 'base'   : the product name plus any free option meta appended inline
     *                (variation attributes, "Colour: …", customization fields),
     *                e.g. "Clear MagSafe - iPhone 12 Pro Max (Colour: Midnight Blue)".
     *   - 'addons' : names of any PAID add-on products (meta values carrying an
     *                "RM…" price, e.g. a "Mini Wrist Strap (RM59.00)" sold via
     *                Product Add-Ons) — each becomes its own warranty so it can
     *                be claimed independently.
     *
     * Values are tag-stripped, newline-flattened and length-capped so a long
     * custom text or a file-upload URL can't bloat the record.
     */
    private static function components($item) {
        $name        = (string) $item->get_name();
        $descriptors = [];
        $addons      = [];

        if (method_exists($item, 'get_formatted_meta_data')) {
            foreach ($item->get_formatted_meta_data('_', false) as $meta) {
                $key = trim(wp_strip_all_tags((string) $meta->display_key));
                $val = trim(wp_strip_all_tags((string) $meta->display_value));
                if ($key === '' || $val === '') {
                    continue;
                }
                $val = trim(preg_replace('/\s+/', ' ', $val)); // flatten newlines

                // A price marker ("RM59.00", "+RM59", "(RM 59.00)") signals a
                // paid add-on product → its own claimable warranty.
                if (preg_match('/\+?\s*RM\s?[\d][\d.,]*/i', $val)) {
                    $clean = trim(preg_replace('/\(?\s*\+?\s*RM\s?[\d][\d.,]*\s*\)?/i', '', $val));
                    $clean = trim($clean, " -–—:()");
                    if (function_exists('mb_strlen') ? mb_strlen($clean) < 2 : strlen($clean) < 2) {
                        $clean = $key; // value was essentially just the price / "Yes"
                    }
                    $addons[] = self::cap($clean);
                } else {
                    $descriptors[] = $key . ': ' . self::cap($val);
                    if (count($descriptors) >= 6) { // cap noise from option-heavy products
                        break;
                    }
                }
            }
        }

        $base = $name;
        if ($descriptors) {
            $base .= ' (' . implode(', ', $descriptors) . ')';
        }
        return [
            'base'   => trim(preg_replace('/\s+/', ' ', $base)),
            'addons' => $addons,
        ];
    }

    /** Length-cap a label fragment so one field can't bloat the record. */
    private static function cap($s) {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s) > 80 ? mb_substr($s, 0, 77) . '…' : $s;
        }
        return strlen($s) > 80 ? substr($s, 0, 77) . '…' : $s;
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
     * Kick off (or restart) the recent-orders backfill from a clean slate.
     *
     * The backfill is driven by the settings page itself (one batch per auto-
     * refresh — see run_backfill_batch) rather than WP-Cron, because shared
     * hosts often don't fire scheduled events reliably, which would leave the
     * run stuck "running". We still try a cron tick as a bonus, but the page
     * keeps it moving regardless. Any stale scheduled event is cleared first.
     */
    public static function start_backfill() {
        wp_clear_scheduled_hook(self::BACKFILL_HOOK);

        update_option(self::BACKFILL_STATE, array_merge(self::default_backfill_state(), [
            'status'  => 'running',
            'started' => current_time('mysql'),
        ]), false);
    }

    /**
     * Process a single batch of recent orders and advance the cursor. Called
     * once per settings-page load while the backfill is running (the page auto-
     * refreshes), so progress never depends on WP-Cron. Time-boxed by batch
     * size; each item insert is idempotent so partial runs and retries — and
     * the occasional double-call — are harmless.
     *
     * @return bool true while more batches remain, false once done/idle.
     */
    public static function run_backfill_batch() {
        if (!function_exists('wc_get_orders')) {
            return false;
        }
        $state = self::get_backfill_state();
        if ($state['status'] !== 'running') {
            return false;
        }

        $after = gmdate('Y-m-d', strtotime('-' . self::BACKFILL_MONTHS . ' months'));

        try {
            $orders = wc_get_orders([
                'limit'        => self::BACKFILL_BATCH,
                'page'         => (int) $state['page'],
                'orderby'      => 'date',
                'order'        => 'ASC',
                'status'       => ['wc-processing', 'wc-completed'],
                'date_created' => '>=' . $after,
                'return'       => 'objects',
            ]);
        } catch (\Throwable $e) {
            // Don't get stuck on a page that won't load — log, skip past it.
            error_log('[galado-warranty] backfill page ' . $state['page'] . ' query failed: ' . $e->getMessage());
            $orders = [];
            $state['page']++;
            update_option(self::BACKFILL_STATE, $state, false);
            return true;
        }

        if (empty($orders)) {
            $state['status']   = 'done';
            $state['finished'] = current_time('mysql');
            update_option(self::BACKFILL_STATE, $state, false);
            return false;
        }

        foreach ($orders as $order) {
            // capture_order is already item-isolated, but guard the order level
            // too so one unreadable order can never stall the whole backfill.
            try {
                $state['created'] += (int) self::capture_order($order->get_id());
            } catch (\Throwable $e) {
                error_log('[galado-warranty] backfill order ' . $order->get_id() . ' failed: ' . $e->getMessage());
            }
            $state['processed']++;
        }
        $state['page']++;
        update_option(self::BACKFILL_STATE, $state, false);
        return true;
    }
}
