<?php
/**
 * Plugin Name: GALADO AI Recommendations
 * Plugin URI: https://galado.com.my
 * Description: AI-powered personalised product recommendations using Claude or GPT. Tracks browsing behaviour and purchase history to suggest the perfect products for each customer.
 * Version: 1.2.0
 * Author: GALADO
 * Author URI: https://galado.com.my
 * License: GPL v2 or later
 * Text Domain: galado-ai-rec
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) exit;

define('GAIR_VERSION', '1.2.0');
define('GAIR_PATH', plugin_dir_path(__FILE__));
define('GAIR_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>GALADO AI Recommendations</strong> requires WooCommerce.</p></div>';
        });
        return;
    }

    require_once GAIR_PATH . 'includes/class-tracker.php';
    require_once GAIR_PATH . 'includes/class-profile-builder.php';
    require_once GAIR_PATH . 'includes/class-ai-engine.php';
    require_once GAIR_PATH . 'includes/class-recommendation-widget.php';

    if (is_admin()) {
        require_once GAIR_PATH . 'admin/settings.php';
    }

    // Initialize components
    GAIR_Tracker::init();
    GAIR_Recommendation_Widget::init();

    // One-time copy migration to Brand v1.0 voice (sentence case). Only
    // rewrites the title if it still equals the old default.
    if (get_option('gair_copy_version') !== '2') {
        $s = get_option('gair_settings', []);
        if (is_array($s) && ($s['widget_title'] ?? '') === 'Recommended for You') {
            $s['widget_title'] = 'Recommended for you';
            update_option('gair_settings', $s);
        }
        update_option('gair_copy_version', '2'); // autoloaded: checked every request
    }
});

// Enqueue frontend assets
add_action('wp_enqueue_scripts', function() {
    if (!is_admin()) {
        wp_enqueue_style('gair-style', GAIR_URL . 'assets/style.css', [], GAIR_VERSION);
        wp_enqueue_script('gair-tracker', GAIR_URL . 'assets/tracker.js', ['jquery'], GAIR_VERSION, true);
        wp_localize_script('gair-tracker', 'gairData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('gair_nonce'),
        ]);
        // One-tap add-to-cart on recommendation cards (reads window.gairData).
        wp_enqueue_script('gair-add-to-cart', GAIR_URL . 'assets/add-to-cart.js', ['jquery', 'gair-tracker'], GAIR_VERSION, true);
    }
});

// Admin menu — under GALADO hub if available
add_action('admin_menu', function() {
    $parent = class_exists('Galado_Admin_Hub') ? 'galado-hub' : 'woocommerce';
    add_submenu_page(
        $parent,
        'AI Recommendations',
        'AI Recommendations',
        'manage_woocommerce',
        'galado-ai-rec',
        'gair_settings_page'
    );
}, 20);

// Admin styles
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'galado-ai-rec') !== false) {
        wp_enqueue_style('gair-admin', GAIR_URL . 'admin/style.css', [], GAIR_VERSION);
    }
});

// AJAX endpoint for tracking
add_action('wp_ajax_gair_track', 'gair_handle_track');
add_action('wp_ajax_nopriv_gair_track', 'gair_handle_track');

function gair_handle_track() {
    check_ajax_referer('gair_nonce', 'nonce');
    GAIR_Tracker::handle_ajax();
    wp_die();
}

// AJAX endpoint for getting recommendations
add_action('wp_ajax_gair_get_recs', 'gair_handle_get_recs');
add_action('wp_ajax_nopriv_gair_get_recs', 'gair_handle_get_recs');

function gair_handle_get_recs() {
    check_ajax_referer('gair_nonce', 'nonce');

    $context = sanitize_text_field($_POST['context'] ?? 'homepage');
    $product_id = absint($_POST['product_id'] ?? 0);

    $recs = GAIR_AI_Engine::get_recommendations($context, $product_id);

    if (is_wp_error($recs)) {
        wp_send_json_error(['message' => $recs->get_error_message()]);
    }

    wp_send_json_success(['html' => $recs]);
}

// AJAX one-tap add-to-cart from recommendation cards (mirrors Smart Cross-Sells)
add_action('wp_ajax_gair_add_to_cart', 'gair_handle_add_to_cart');
add_action('wp_ajax_nopriv_gair_add_to_cart', 'gair_handle_add_to_cart');

function gair_handle_add_to_cart() {
    check_ajax_referer('gair_nonce', 'nonce');

    $product_id = absint($_POST['product_id'] ?? 0);
    $quantity   = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;

    if (!$product_id || !function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => 'Invalid product']);
    }

    $added = WC()->cart->add_to_cart($product_id, $quantity);

    if ($added) {
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        wp_send_json_success([
            'message'    => 'Added to cart',
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_hash'  => WC()->cart->get_cart_hash(),
            'fragments'  => apply_filters('woocommerce_add_to_cart_fragments', [
                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
            ]),
        ]);
    }

    wp_send_json_error(['message' => 'Could not add to cart']);
}

// Create tracking table on activation
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gair_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED DEFAULT 0,
        event_type VARCHAR(50) NOT NULL,
        product_id BIGINT UNSIGNED DEFAULT 0,
        category VARCHAR(100) DEFAULT '',
        event_data TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_session (session_id),
        INDEX idx_user (user_id),
        INDEX idx_created (created_at)
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gair_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(64) NOT NULL UNIQUE,
        user_id BIGINT UNSIGNED DEFAULT 0,
        profile_data LONGTEXT DEFAULT '',
        recommendations_cache LONGTEXT DEFAULT '',
        cache_expires DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_cache (cache_expires)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    dbDelta($sql2);

    // Set defaults
    if (!get_option('gair_settings')) {
        update_option('gair_settings', [
            'enabled'        => 1,
            'provider'       => 'anthropic',
            'anthropic_key'  => '',
            'anthropic_model'=> 'claude-haiku-4-5-20251001',
            'openai_key'     => '',
            'openai_model'   => 'gpt-4o-mini',
            'max_products'   => 4,
            'cache_hours'    => 6,
            'show_homepage'  => 1,
            'show_product'   => 1,
            'show_cart'      => 1,
            'widget_title'   => 'Recommended for you',
            'ranking_mode'   => 'hybrid',
        ]);
    }
});

// Cleanup old events (keep 30 days)
add_action('wp_scheduled_delete', function() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}gair_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
});
