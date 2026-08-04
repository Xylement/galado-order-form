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

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue() {
        if (!galado_bundles_sticky_cart_enabled()) return;
        if (!function_exists('is_cart') || !is_cart()) return;

        wp_enqueue_style('galado-bundles-extras', GALADO_BUNDLES_URL . 'public/extras.css', [], GALADO_BUNDLES_VERSION);
        wp_enqueue_script('galado-bundles-extras', GALADO_BUNDLES_URL . 'public/extras.js', [], GALADO_BUNDLES_VERSION, true);
        wp_localize_script('galado-bundles-extras', 'GALADO_BUNDLES_EXTRAS', [
            'sticky'       => 1,
            'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '/checkout/',
            'i18n'         => [
                'continue_label' => __('Continue to Checkout', 'galado-bundles'),
            ],
        ]);
    }
}
