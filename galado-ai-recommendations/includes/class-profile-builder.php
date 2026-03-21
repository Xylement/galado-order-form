<?php
/**
 * Builds customer profiles from tracked events
 * Analyses browsing patterns, colour preferences, price range, etc.
 */

if (!defined('ABSPATH')) exit;

class GAIR_Profile_Builder {

    /**
     * Build or retrieve customer profile
     */
    public static function get_profile() {
        global $wpdb;

        $session_id = GAIR_Tracker::get_session_id();

        // Check for cached profile
        $cached = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT profile_data FROM {$wpdb->prefix}gair_profiles
                 WHERE session_id = %s AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                $session_id
            )
        );

        if ($cached && !empty($cached->profile_data)) {
            return json_decode($cached->profile_data, true);
        }

        // Build fresh profile
        $events = GAIR_Tracker::get_recent_events(100);
        $profile = self::build_from_events($events);

        // Enrich with WooCommerce order history if logged in
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $profile = self::enrich_with_orders($profile, $user_id);
        }

        // Save profile
        $wpdb->replace(
            $wpdb->prefix . 'gair_profiles',
            [
                'session_id'   => $session_id,
                'user_id'      => $user_id,
                'profile_data' => wp_json_encode($profile),
                'updated_at'   => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s']
        );

        return $profile;
    }

    /**
     * Build profile from tracked events
     */
    private static function build_from_events($events) {
        $profile = [
            'viewed_products'   => [],
            'viewed_categories' => [],
            'carted_products'   => [],
            'purchased_products'=> [],
            'colour_preferences'=> [],
            'device_models'     => [],
            'price_range'       => ['min' => 999, 'max' => 0],
            'total_views'       => 0,
            'total_purchases'   => 0,
            'avg_order_value'   => 0,
            'style_preferences' => [],
        ];

        $prices = [];

        foreach ($events as $event) {
            $data = json_decode($event->event_data, true) ?: [];

            switch ($event->event_type) {
                case 'product_view':
                    $profile['viewed_products'][] = $event->product_id;
                    $profile['total_views']++;
                    if ($event->category) {
                        foreach (explode(',', $event->category) as $cat) {
                            $profile['viewed_categories'][] = trim($cat);
                        }
                    }
                    break;

                case 'add_to_cart':
                    $profile['carted_products'][] = $event->product_id;
                    if (!empty($data['price'])) {
                        $prices[] = floatval($data['price']);
                    }
                    break;

                case 'purchase':
                    $profile['purchased_products'][] = $event->product_id;
                    $profile['total_purchases']++;
                    if (!empty($data['total'])) {
                        $prices[] = floatval($data['total']);
                    }
                    break;
            }

            // Extract product attributes for preferences
            if ($event->product_id > 0) {
                self::extract_product_preferences($event->product_id, $profile);
            }
        }

        // Calculate price range
        if (!empty($prices)) {
            $profile['price_range']['min'] = min($prices);
            $profile['price_range']['max'] = max($prices);
            $profile['avg_order_value'] = round(array_sum($prices) / count($prices), 2);
        }

        // Count and sort preferences
        $profile['viewed_categories'] = self::count_and_sort($profile['viewed_categories']);
        $profile['colour_preferences'] = self::count_and_sort($profile['colour_preferences']);
        $profile['device_models'] = self::count_and_sort($profile['device_models']);
        $profile['style_preferences'] = self::count_and_sort($profile['style_preferences']);

        // Deduplicate product lists
        $profile['viewed_products'] = array_unique($profile['viewed_products']);
        $profile['carted_products'] = array_unique($profile['carted_products']);
        $profile['purchased_products'] = array_unique($profile['purchased_products']);

        return $profile;
    }

    /**
     * Extract colour, device, and style preferences from a product
     */
    private static function extract_product_preferences($product_id, &$profile) {
        $product = wc_get_product($product_id);
        if (!$product) return;

        $name = strtolower($product->get_name());
        $cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);

        // Detect colours from product name
        $colours = ['black', 'white', 'pink', 'blue', 'red', 'green', 'gold', 'silver',
                     'rose', 'clear', 'purple', 'yellow', 'orange', 'grey', 'gray', 'navy',
                     'teal', 'coral', 'lavender', 'mint', 'beige', 'brown'];
        foreach ($colours as $colour) {
            if (strpos($name, $colour) !== false) {
                $profile['colour_preferences'][] = $colour;
            }
        }

        // Detect device models from categories
        foreach ($cats as $cat) {
            if (preg_match('/^(iphone|galaxy|airpods|macbook|apple-watch)/i', $cat)) {
                $profile['device_models'][] = $cat;
            }
        }

        // Detect style preferences from product name/tags
        $styles = [
            'minimalist' => ['slim', 'minimalist', 'minimal', 'simple', 'clean'],
            'bold'       => ['bold', 'vibrant', 'colorful', 'neon', 'bright'],
            'cute'       => ['cute', 'kawaii', 'charm', 'adorable', 'sweet'],
            'luxury'     => ['premium', 'luxury', 'gold', 'elegant', 'sophisticated'],
            'protective' => ['guard', 'protect', 'impact', 'rugged', 'tough'],
            'custom'     => ['custom', 'name', 'personalise', 'personalize', 'monogram'],
            'artistic'   => ['art', 'artist', 'collab', 'illustration', 'design'],
        ];

        foreach ($styles as $style => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($name, $kw) !== false) {
                    $profile['style_preferences'][] = $style;
                    break;
                }
            }
        }
    }

    /**
     * Enrich profile with WooCommerce order history
     */
    private static function enrich_with_orders($profile, $user_id) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => 10,
            'status'      => ['completed', 'processing'],
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $pid = $item->get_product_id();
                $profile['purchased_products'][] = $pid;
                self::extract_product_preferences($pid, $profile);
            }
        }

        $profile['purchased_products'] = array_unique($profile['purchased_products']);
        $profile['total_purchases'] = count($profile['purchased_products']);

        return $profile;
    }

    /**
     * Count occurrences and sort by frequency
     */
    private static function count_and_sort($items) {
        $counts = array_count_values(array_filter($items));
        arsort($counts);
        return $counts;
    }

    /**
     * Get a human-readable summary for AI prompt
     */
    public static function get_profile_summary() {
        $profile = self::get_profile();
        $summary = [];

        if (!empty($profile['device_models'])) {
            $top_devices = array_slice(array_keys($profile['device_models']), 0, 3);
            $summary[] = "Devices browsed: " . implode(', ', $top_devices);
        }

        if (!empty($profile['colour_preferences'])) {
            $top_colours = array_slice(array_keys($profile['colour_preferences']), 0, 4);
            $summary[] = "Colour preferences: " . implode(', ', $top_colours);
        }

        if (!empty($profile['style_preferences'])) {
            $top_styles = array_slice(array_keys($profile['style_preferences']), 0, 3);
            $summary[] = "Style preferences: " . implode(', ', $top_styles);
        }

        if (!empty($profile['viewed_categories'])) {
            $top_cats = array_slice(array_keys($profile['viewed_categories']), 0, 5);
            $summary[] = "Categories browsed: " . implode(', ', $top_cats);
        }

        if ($profile['total_purchases'] > 0) {
            $summary[] = "Previous purchases: " . $profile['total_purchases'] . " items";
            $summary[] = "Avg spend: RM" . $profile['avg_order_value'];
        }

        if ($profile['price_range']['max'] > 0) {
            $summary[] = "Price range: RM" . $profile['price_range']['min'] . " - RM" . $profile['price_range']['max'];
        }

        $summary[] = "Products viewed: " . count($profile['viewed_products']);

        return implode("\n", $summary);
    }
}
