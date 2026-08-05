<?php
/**
 * Bundles extras (ADDON-COMBOS-SPEC section 5): the mobile cart sticky
 * Continue-to-Checkout bar, behind its own toggle, independent of the bundles
 * storefront switch.
 *
 * NOTE the WCPA price-summary relabel is deliberately NOT here: marketing
 * shipped it as Code Snippet #182 (2026-08-04) and the spec forbids the plugin
 * relabelling the same DOM a second time. If the combos module ever replaces
 * that summary wholesale, deactivate snippet #182 in the same release.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Extras {

    /**
     * Cache-busting version for a front-end asset, taken from the file's own timestamp.
     *
     * Was GALADO_BUNDLES_VERSION, a hand-bumped constant in the root plugin file. That is
     * one edit away from shipping fixed JS behind an unchanged ?ver=, which is exactly what
     * happened on 2026-08-05: corrected combos.js sat on the server while every returning
     * shopper kept running the cached old copy. A timestamp cannot be forgotten.
     */
    public static function asset_ver($rel) {
        $file = GALADO_BUNDLES_PATH . ltrim($rel, '/');
        $time = file_exists($file) ? filemtime($file) : 0;
        return $time ? (string) $time : GALADO_BUNDLES_VERSION;
    }

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue() {
        // Store-wide PDP chrome (owner r13), dark-gated per request: on EVERY
        // product page, mobile hides the in-page qty + Buy Now (the sticky
        // bar's observer then keeps the floating Buy Now permanently visible,
        // same mechanism as module pages) and the credits earn line goes.
        // Customers see none of it until the storefront master flips.
        if (function_exists('is_product') && is_product() && galado_bundles_can_transact()) {
            wp_enqueue_style('galado-pdp-chrome', GALADO_BUNDLES_URL . 'public/pdp-chrome.css', [], GALADO_Bundles_Extras::asset_ver('public/pdp-chrome.css'));
        }

        if (!galado_bundles_sticky_cart_enabled()) return;
        if (!function_exists('is_cart') || !is_cart()) return;

        wp_enqueue_style('galado-bundles-extras', GALADO_BUNDLES_URL . 'public/extras.css', [], GALADO_Bundles_Extras::asset_ver('public/extras.css'));
        wp_enqueue_script('galado-bundles-extras', GALADO_BUNDLES_URL . 'public/extras.js', [], GALADO_Bundles_Extras::asset_ver('public/extras.js'), true);
        wp_localize_script('galado-bundles-extras', 'GALADO_BUNDLES_EXTRAS', [
            'sticky'       => 1,
            'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '/checkout/',
            'i18n'         => [
                'continue_label' => __('Continue to Checkout', 'galado-bundles'),
                'case_confirm'   => __('Removing your case also removes the PWP add-ons with it. Remove everything?', 'galado-bundles'),
            ],
        ]);
    }
}
