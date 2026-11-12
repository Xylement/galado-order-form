<?php
/**
 * Issues one WooCommerce coupon per card when an order is paid, exactly once.
 *
 * Both status hooks can fire (processing, then completed), payment gateways and webhooks can race,
 * and Action Scheduler retries. So issuing:
 *  1. takes a MySQL named lock per order (GET_LOCK), which serialises every PHP process;
 *  2. re-reads the order from the database and asks the database which cards already have a
 *     coupon, by each coupon's own meta (order item id + card number), not by a flag on the order
 *     that a crashed request might have failed to write;
 *  3. creates only the missing ones.
 * If the lock cannot be taken in time, or issuing fails, it tries again later through Action
 * Scheduler (up to MAX_ATTEMPTS, further apart each time), then leaves staff a note.
 *
 * Coupon: fixed_cart, amount = the card's RM value, usage limit 1, no email restriction (that is
 * also what keeps Coreem Coupon Reminder away: it skips coupons with no allowed emails), not
 * individual use, expiry at 23:59:59 Malaysian time three years after payment, published.
 * The gift card product is kept out of every coupon's discount in code (Galado_GC_Spending), not by
 * the coupon's excluded products list: for a fixed_cart coupon WooCommerce rejects the whole code
 * when the cart holds an excluded product, which would stop a card being spent on a cart that also
 * buys a new card.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Issuer {

    const META_ORDER = '_galado_gc_order_id';
    const META_ITEM = '_galado_gc_order_item_id';
    const META_INDEX = '_galado_gc_index';
    const META_COUPON_VALUE = '_galado_gc_value';
    const ITEM_COUPON_IDS = '_galado_gc_coupon_ids';
    const RETRY_HOOK = 'galado_gc_issue_retry';
    const GROUP = 'galado-gift-cards';
    const LOCK_WAIT = 10; // seconds
    const ORDER_ATTEMPTS = '_galado_gc_issue_attempts';
    const MAX_ATTEMPTS = 5;

    public static function init() {
        add_action('woocommerce_order_status_processing', [__CLASS__, 'on_paid'], 20);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'on_paid'], 20);
        add_action(self::RETRY_HOOK, [__CLASS__, 'on_paid']);
    }

    public static function on_paid($order_id) {
        try {
            self::issue_for_order((int) $order_id);
        } catch (Throwable $e) {
            // Never break the payment request that fired the hook. No code is ever logged.
            error_log('[galado-gift-cards] issue_failed order=' . (int) $order_id . ' error=' . get_class($e) . ':' . $e->getMessage());
            self::schedule_retry((int) $order_id);
        }
    }

    /**
     * @return int[] ids of the coupons created in this call (empty when nothing was missing)
     */
    public static function issue_for_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::is_paid($order) || !Galado_GC_Cart::order_gift_items($order)) {
            return [];
        }

        $lock = self::lock_name($order_id);
        if (!self::acquire($lock)) {
            error_log('[galado-gift-cards] issue_lock_busy order=' . (int) $order_id);
            self::schedule_retry($order_id);
            return [];
        }

        $created = [];
        try {
            // Fresh from the database: another process may have issued while we waited for the lock.
            self::forget_order_cache($order_id);
            $order = wc_get_order($order_id);
            if (!$order || !self::is_paid($order)) {
                return [];
            }
            $paid_ts = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp()
                : ($order->get_date_created() ? $order->get_date_created()->getTimestamp() : time());
            $expires = Galado_GC_Time::expiry_timestamp($paid_ts);

            foreach (Galado_GC_Cart::order_gift_items($order) as $item_id => $item) {
                $value = (float) $item->get_meta(Galado_GC_Cart::META_VALUE);
                if ($value <= 0) {
                    $order->add_order_note(sprintf(
                        /* translators: %d: item id */
                        __('Gift card line %d has no value, so no code was issued. Check the order item.', 'galado-gift-cards'),
                        $item_id
                    ));
                    continue;
                }
                $existing = self::coupons_for_item($item_id);
                $qty = max(0, (int) $item->get_quantity());
                for ($n = 1; $n <= $qty; $n++) {
                    if (isset($existing[$n])) {
                        continue;
                    }
                    $existing[$n] = self::create_coupon($order, $item_id, $n, $value, $expires);
                    $created[] = $existing[$n];
                }
                ksort($existing);
                $item->update_meta_data(self::ITEM_COUPON_IDS, array_values($existing));
                $item->save();
            }

            if ($created) {
                $tails = [];
                foreach ($created as $coupon_id) {
                    $tails[] = Galado_GC_Codes::tail(get_post_field('post_title', $coupon_id));
                }
                $order->add_order_note(sprintf(
                    /* translators: 1: count, 2: code endings */
                    _n('Issued %1$d gift card code (ending %2$s).', 'Issued %1$d gift card codes (ending %2$s).', count($created), 'galado-gift-cards'),
                    count($created),
                    implode(', ', $tails)
                ));
            }
            if ('' !== (string) $order->get_meta(self::ORDER_ATTEMPTS)) {
                $order->delete_meta_data(self::ORDER_ATTEMPTS); // issued: earlier failures no longer count
                $order->save_meta_data();
            }
        } finally {
            self::release($lock);
        }

        // Outside the lock: scheduling is idempotent on its own.
        Galado_GC_Delivery::schedule_for_order($order_id);
        return $created;
    }

    /**
     * Existing coupons for one order line, any status, keyed by card number.
     *
     * @return array<int,int> card number => coupon id
     */
    public static function coupons_for_item($item_id) {
        global $wpdb;
        // Straight from the database, not WP_Query: this is the race guard, so no cached answer.
        // Every status counts, trash included, so a disabled (draft) or binned card is never
        // silently issued again under a new code.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS id, idx.meta_value AS n
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} item ON item.post_id = p.ID AND item.meta_key = %s AND item.meta_value = %s
               JOIN {$wpdb->postmeta} idx ON idx.post_id = p.ID AND idx.meta_key = %s
              WHERE p.post_type = 'shop_coupon'
              ORDER BY p.ID ASC",
            self::META_ITEM, (string) (int) $item_id, self::META_INDEX
        ));
        $out = [];
        foreach ((array) $rows as $row) {
            $n = (int) $row->n;
            if ($n > 0 && !isset($out[$n])) {
                $out[$n] = (int) $row->id;
            }
        }
        ksort($out);
        return $out;
    }

    /** Coupons for every gift line of an order, as [item_id => [n => coupon_id]]. */
    public static function coupons_for_order($order) {
        $out = [];
        foreach (Galado_GC_Cart::order_gift_items($order) as $item_id => $item) {
            $out[$item_id] = self::coupons_for_item($item_id);
        }
        return $out;
    }

    private static function create_coupon($order, $item_id, $n, $value, $expires) {
        $code = Galado_GC_Codes::unique();
        // Not `new WC_Coupon()`: with Points and Rewards 1.6.13 active and no redemption in the
        // session, its woocommerce_get_shop_coupon_data filter matches the empty code ('' == null)
        // and turns the object into a virtual coupon that save() never writes.
        $coupon = new WC_Coupon(0);
        $coupon->set_code($code);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount(wc_format_decimal($value, 2));
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(0);
        $coupon->set_individual_use(false);
        $coupon->set_email_restrictions([]);
        $coupon->set_free_shipping(false);
        $coupon->set_date_expires($expires);
        $coupon->set_status('publish');
        $coupon->set_description(sprintf(
            /* translators: 1: order number, 2: card number */
            __('GALADO gift card from order #%1$s (card %2$d).', 'galado-gift-cards'),
            $order->get_order_number(), $n
        ));
        $coupon->update_meta_data(Galado_GC_Codes::META_FLAG, 'yes');
        $coupon->update_meta_data(self::META_ORDER, (string) $order->get_id());
        $coupon->update_meta_data(self::META_ITEM, (string) (int) $item_id);
        $coupon->update_meta_data(self::META_INDEX, (string) (int) $n);
        $coupon->update_meta_data(self::META_COUPON_VALUE, wc_format_decimal($value, 2));
        $id = (int) $coupon->save();
        if ($id <= 0 || 'shop_coupon' !== get_post_type($id)) {
            // Fail loudly (retry, then a staff note) rather than record a card that does not exist.
            throw new RuntimeException('coupon_not_saved');
        }
        return $id;
    }

    private static function is_paid($order) {
        return in_array($order->get_status(), ['processing', 'completed'], true);
    }

    /** MySQL lock names are global to the database server: include this site's prefix and database. */
    public static function lock_name($order_id) {
        global $wpdb;
        $site = substr(md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix), 0, 12);
        return 'galado_gc_' . $site . '_' . (int) $order_id; // well under MySQL's 64 characters
    }

    public static function acquire($name) {
        global $wpdb;
        return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT));
    }

    public static function release($name) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * Try again later, unless a try is already waiting. Only a pending try counts: inside the retry
     * job itself Action Scheduler still reports that (running) job as scheduled.
     */
    private static function schedule_retry($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !function_exists('as_schedule_single_action')) {
            return;
        }
        $attempts = (int) $order->get_meta(self::ORDER_ATTEMPTS) + 1;
        $order->update_meta_data(self::ORDER_ATTEMPTS, (string) $attempts);
        $order->save_meta_data();
        if ($attempts > self::MAX_ATTEMPTS) {
            $order->add_order_note(__('Gift card codes could not be created for this order after several tries. Change the order status (for example to Completed) to try again, or contact the developer.', 'galado-gift-cards'));
            return;
        }
        $waiting = as_get_scheduled_actions([
            'hook' => self::RETRY_HOOK, 'args' => [(int) $order_id], 'group' => self::GROUP,
            'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1,
        ], 'ids');
        if (!$waiting) {
            as_schedule_single_action(time() + 120 * $attempts, self::RETRY_HOOK, [(int) $order_id], self::GROUP);
        }
    }

    private static function forget_order_cache($order_id) {
        clean_post_cache($order_id);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($order_id, 'orders');
            wp_cache_delete('order-items-' . $order_id, 'orders');
        }
    }
}
