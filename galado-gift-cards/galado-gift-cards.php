<?php
/**
 * Plugin Name: GALADO Gift Cards
 * Description: GALADO e-gift cards as one-time WooCommerce coupons: sold as a normal product, issued once per card when the order is paid, emailed to the recipient on the chosen date, spent in one order (any unused value is lost). Spec: galado-international/HANDOVER-GIFT-CARD-PLUGIN.md.
 * Version: 0.1.0
 * Author: GALADO
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.8
 * WC tested up to: 10.5
 * Text Domain: galado-gift-cards
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GALADO_GC_VERSION', '0.1.0');
define('GALADO_GC_FILE', __FILE__);
define('GALADO_GC_DIR', plugin_dir_path(__FILE__));
define('GALADO_GC_URL', plugin_dir_url(__FILE__));

require_once GALADO_GC_DIR . 'includes/class-galado-gc-config.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-time.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-codes.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-product.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-cart.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-issuer.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-delivery.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-revoke.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-spending.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-remainder.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-checker.php';
require_once GALADO_GC_DIR . 'includes/class-galado-gc-admin.php';

// HPOS: every order and item is read and written through the WooCommerce CRUD API, so the
// plugin works with orders in posts (today) or in the custom order tables.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', GALADO_GC_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    // Everything below hooks WooCommerce. Without it the plugin stays inert rather than fatal.
    if (!class_exists('WooCommerce')) {
        return;
    }
    Galado_GC_Config::init();
    Galado_GC_Product::init();
    Galado_GC_Cart::init();
    Galado_GC_Issuer::init();
    Galado_GC_Delivery::init();
    Galado_GC_Revoke::init();
    Galado_GC_Spending::init();
    Galado_GC_Remainder::init();
    Galado_GC_Checker::init();
    Galado_GC_Admin::init();
    if (defined('WP_CLI') && WP_CLI) {
        require_once GALADO_GC_DIR . 'includes/class-galado-gc-cli.php';
        WP_CLI::add_command('galado-gift-cards', 'Galado_GC_CLI');
    }
});

/*
 * Stable helpers for the other lanes (Club bridge, G-Coins, reports). Names and return values
 * do not change without a version bump. All amounts are ringgit (the store's base currency).
 */

/** True when the product (or the parent of a variation) is the gift card product. */
function galado_gift_cards_is_gift_card_product($product_id) {
    return Galado_GC_Product::is_gift_card_product($product_id);
}

/** True when the coupon code is a gift card code issued by this plugin (any status). */
function galado_gift_cards_is_gift_card_code($code) {
    return Galado_GC_Codes::is_gift_card_code($code);
}

/**
 * RM value of the gift card lines in the current cart (0 when there is no cart). The Club bridge
 * uses it so gift cards never count toward the welcome, referral or win-back minimum spend.
 */
function galado_gift_cards_cart_gift_total() {
    return Galado_GC_Cart::cart_gift_total();
}

/**
 * RM value of the gift card lines bought on an order, so earning (G-Coins, Shopping Credits) can
 * leave the card purchase out: buying a card earns nothing.
 *
 * @param WC_Order|int $order
 */
function galado_gift_cards_order_gift_total($order) {
    return Galado_GC_Cart::order_gift_total($order);
}

/**
 * RM value paid by gift card codes on an order, so earning can treat that part as if it were paid
 * in cash: the order a card is spent on earns as normal.
 *
 * @param WC_Order|int $order
 */
function galado_gift_cards_order_gift_coupon_total($order) {
    return Galado_GC_Spending::order_gift_coupon_total($order);
}
