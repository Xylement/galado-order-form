<?php
/**
 * Plugin Name: GALADO Smart Cross-Sells
 * Plugin URI: https://galado.com.my
 * Description: Boost AOV with intelligent cross-sell recommendations on cart page, checkout, and post-purchase. Shows compatible accessories based on cart contents.
 * Version: 1.0.0
 * Author: GALADO
 * Author URI: https://galado.com.my
 * License: GPL v2 or later
 * Text Domain: galado-crosssells
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) exit;

define('GALADO_CS_VERSION', '1.0.0');
define('GALADO_CS_PATH', plugin_dir_path(__FILE__));
define('GALADO_CS_URL', plugin_dir_url(__FILE__));

// Check WooCommerce is active
add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>GALADO Smart Cross-Sells</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    // Load plugin files
    require_once GALADO_CS_PATH . 'includes/class-crosssell-engine.php';
    require_once GALADO_CS_PATH . 'includes/class-cart-crosssells.php';
    require_once GALADO_CS_PATH . 'includes/class-checkout-crosssells.php';
    require_once GALADO_CS_PATH . 'includes/class-thankyou-crosssells.php';

    if (is_admin()) {
        require_once GALADO_CS_PATH . 'admin/settings.php';
    }

    // Initialize
    Galado_Crosssell_Engine::init();
    Galado_Cart_Crosssells::init();
    Galado_Checkout_Crosssells::init();
    Galado_Thankyou_Crosssells::init();
});

// Enqueue frontend assets
add_action('wp_enqueue_scripts', function() {
    if (is_cart() || is_checkout() || is_wc_endpoint_url('order-received')) {
        wp_enqueue_style('galado-crosssells', GALADO_CS_URL . 'assets/style.css', [], GALADO_CS_VERSION);
        wp_enqueue_script('galado-crosssells', GALADO_CS_URL . 'assets/script.js', ['jquery'], GALADO_CS_VERSION, true);
        wp_localize_script('galado-crosssells', 'galadoCS', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('galado_cs_nonce'),
            'currency' => get_woocommerce_currency_symbol(),
            'i18n' => [
                'added' => __('Added to cart!', 'galado-crosssells'),
                'adding' => __('Adding...', 'galado-crosssells'),
                'add' => __('Add', 'galado-crosssells'),
            ]
        ]);
    }
});

// AJAX add to cart handler
add_action('wp_ajax_galado_cs_add_to_cart', 'galado_cs_ajax_add');
add_action('wp_ajax_nopriv_galado_cs_add_to_cart', 'galado_cs_ajax_add');

function galado_cs_ajax_add() {
    check_ajax_referer('galado_cs_nonce', 'nonce');

    $product_id = absint($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;

    if (!$product_id) {
        wp_send_json_error(['message' => 'Invalid product']);
    }

    $added = WC()->cart->add_to_cart($product_id, $quantity);

    if ($added) {
        // Get updated cart fragments
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        wp_send_json_success([
            'message' => 'Added to cart',
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', [
                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>'
            ])
        ]);
    } else {
        wp_send_json_error(['message' => 'Could not add to cart']);
    }
}

// Activation: set defaults
register_activation_hook(__FILE__, function() {
    $defaults = [
        'galado_cs_enable_cart' => 'yes',
        'galado_cs_enable_checkout' => 'yes',
        'galado_cs_enable_thankyou' => 'yes',
        'galado_cs_cart_title' => 'Complete Your Setup',
        'galado_cs_checkout_title' => 'Last Chance to Add',
        'galado_cs_thankyou_title' => 'Customers Also Love',
        'galado_cs_max_products' => 4,
        'galado_cs_smart_matching' => 'yes',
    ];

    foreach ($defaults as $key => $value) {
        if (get_option($key) === false) {
            update_option($key, $value);
        }
    }
});
