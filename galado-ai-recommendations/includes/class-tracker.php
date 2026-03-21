<?php
/**
 * Tracks customer browsing behaviour
 * Records page views, product views, add-to-cart, and purchases
 */

if (!defined('ABSPATH')) exit;

class GAIR_Tracker {

    public static function init() {
        // Track WooCommerce events server-side
        add_action('woocommerce_add_to_cart', [__CLASS__, 'track_add_to_cart'], 10, 6);
        add_action('woocommerce_thankyou', [__CLASS__, 'track_purchase'], 10, 1);
    }

    /**
     * Get or create session ID
     */
    public static function get_session_id() {
        if (!isset($_COOKIE['gair_sid'])) {
            $sid = wp_generate_uuid4();
            setcookie('gair_sid', $sid, time() + (86400 * 90), '/'); // 90 days
            $_COOKIE['gair_sid'] = $sid;
        }
        return sanitize_text_field($_COOKIE['gair_sid']);
    }

    /**
     * Record an event
     */
    public static function record_event($event_type, $product_id = 0, $category = '', $data = []) {
        global $wpdb;

        $session_id = self::get_session_id();
        $user_id = get_current_user_id();

        $wpdb->insert(
            $wpdb->prefix . 'gair_events',
            [
                'session_id'  => $session_id,
                'user_id'     => $user_id,
                'event_type'  => $event_type,
                'product_id'  => $product_id,
                'category'    => $category,
                'event_data'  => wp_json_encode($data),
                'created_at'  => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * Handle AJAX tracking from frontend
     */
    public static function handle_ajax() {
        $event_type = sanitize_text_field($_POST['event_type'] ?? '');
        $product_id = absint($_POST['product_id'] ?? 0);
        $category = sanitize_text_field($_POST['category'] ?? '');
        $data = [];

        if (!empty($_POST['event_data'])) {
            $raw = wp_unslash($_POST['event_data']);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_map('sanitize_text_field', $decoded);
            }
        }

        if ($event_type) {
            self::record_event($event_type, $product_id, $category, $data);
        }

        wp_send_json_success();
    }

    /**
     * Track add-to-cart server-side
     */
    public static function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        $product = wc_get_product($product_id);
        $cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);

        self::record_event('add_to_cart', $product_id, implode(',', $cats), [
            'quantity' => $quantity,
            'price' => $product ? $product->get_price() : 0,
            'name' => $product ? $product->get_name() : '',
        ]);
    }

    /**
     * Track completed purchase
     */
    public static function track_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);

            self::record_event('purchase', $product_id, implode(',', $cats), [
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total(),
                'name' => $item->get_name(),
                'order_id' => $order_id,
            ]);
        }
    }

    /**
     * Get recent events for a session/user
     */
    public static function get_recent_events($limit = 50) {
        global $wpdb;

        $session_id = self::get_session_id();
        $user_id = get_current_user_id();

        // Get events by session OR user ID (covers logged-in users across sessions)
        $where = $wpdb->prepare("session_id = %s", $session_id);
        if ($user_id > 0) {
            $where .= $wpdb->prepare(" OR user_id = %d", $user_id);
        }

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}gair_events
             WHERE ($where)
             AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
             ORDER BY created_at DESC
             LIMIT $limit"
        );
    }
}
