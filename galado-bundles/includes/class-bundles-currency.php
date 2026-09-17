<?php
/**
 * Currency, in one place. The store prices in MYR; CURCY (WooCommerce Multi
 * Currency) converts product prices at READ time through the WooCommerce price
 * filters and rounds the result per line (.50 "beauty" prices on SGD/USD).
 *
 * Three rules follow, and every money line in this plugin keeps them:
 *  1. A price written with set_price() must be a BASE (RM) figure. CURCY converts
 *     it once when the cart reads it back. Writing an already-converted figure
 *     converts it twice (order 423727: S$57.50 charged for an S$88.50 basket).
 *  2. An RM constant (combo_price, addon_price) that is compared with, or shown
 *     next to, a display price goes through convert() first.
 *  3. Anything cached per product must also be cached per currency.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Currency {

    /** CURCY is installed and answering. */
    public static function active() {
        return function_exists('wmc_get_price') && class_exists('WOOMULTI_CURRENCY_Data');
    }

    /** The shopper's currency code for this request (MYR when CURCY is off). */
    public static function code() {
        if (!self::active()) return get_woocommerce_currency();
        return (string) WOOMULTI_CURRENCY_Data::get_ins()->get_current_currency();
    }

    public static function base_code() {
        if (!self::active()) return get_woocommerce_currency();
        return (string) WOOMULTI_CURRENCY_Data::get_ins()->get_default_currency();
    }

    public static function is_base() {
        return self::code() === self::base_code();
    }

    /** Fixed rate for the current currency (1 in the base currency). */
    public static function rate() {
        if (!self::active() || self::is_base()) return 1.0;
        $list = WOOMULTI_CURRENCY_Data::get_ins()->get_list_currencies();
        $code = self::code();
        return isset($list[$code]['rate']) ? (float) $list[$code]['rate'] : 1.0;
    }

    /** A base-currency amount as the shopper sees it: CURCY's rate AND its per-price
     * rounding, so a converted figure here equals what the cart charges for it. */
    public static function convert($base) {
        $base = (float) $base;
        if (!self::active() || self::is_base() || $base <= 0) return round($base, 2);
        return round((float) wmc_get_price($base), 2);
    }

    /**
     * A product's price in the BASE currency with every other price filter still
     * applied (member pricing, sales): CURCY's own filters are lifted for the one
     * read and put back. get_price('edit') would skip member pricing too, and
     * reading through CURCY then dividing by the rate cannot undo its rounding.
     */
    public static function base_price($product) {
        $hooks   = ['woocommerce_product_get_price', 'woocommerce_product_variation_get_price'];
        $removed = [];
        foreach ($hooks as $hook) {
            if (empty($GLOBALS['wp_filter'][$hook]->callbacks[99])) continue;
            foreach ($GLOBALS['wp_filter'][$hook]->callbacks[99] as $cb) {
                $fn = $cb['function'];
                if (is_array($fn) && isset($fn[0]) && is_object($fn[0]) && 'WOOMULTI_CURRENCY_Frontend_Price' === get_class($fn[0])) {
                    remove_filter($hook, $fn, 99);
                    $removed[] = [$hook, $fn, (int) $cb['accepted_args']];
                }
            }
        }
        $price = (float) $product->get_price();
        foreach ($removed as $r) add_filter($r[0], $r[1], 99, $r[2]);
        return $price;
    }

    /**
     * The native app names its currency explicitly (SPEC-SGD-PARITY section 7):
     * `currency=SGD` in the query string of app-page / app-quote, or `currency`
     * in the app-quote JSON body. Honoured by setting the cookie CURCY reads,
     * before CURCY decides at init whether to convert at all. Only these two
     * routes; the web keeps CURCY's own cookie and location logic.
     */
    public static function request_override() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (false === strpos($uri, 'galado-bundles/v1/app-')) return;
        $code = '';
        if (!empty($_GET['currency'])) {
            $code = (string) $_GET['currency'];
        } elseif (false !== strpos($uri, 'app-quote') && 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
            $raw  = (string) file_get_contents('php://input');
            $body = $raw ? json_decode($raw, true) : null;
            if (is_array($body) && !empty($body['currency'])) $code = (string) $body['currency'];
        }
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/', $code)) {
            $_COOKIE['wmc_current_currency'] = $code; // CURCY falls back to the base currency if the code is not one it sells
        }
    }
}
