<?php
/**
 * Independent UX extras riding in the bundles plugin (ADDON-COMBOS-SPEC
 * sections 5 and 6), each behind its own toggle, neither dependent on the
 * bundles storefront switch:
 *
 *  - WCPA price-summary relabel on PDPs: "Product Price" -> "Case",
 *    "Options Price" -> "Add-ons", hide the add-ons row at RM0.00. Done as a
 *    JS shim (with an observer for WCPA's re-renders) because WCPA prints
 *    those strings from its own JS; the plugin itself is never forked.
 *
 *  - Mobile cart sticky CTA: a fixed Continue-to-Checkout bar at the bottom
 *    of /cart/ on small screens, with the running total, clear of the iOS
 *    safe area. Body padding keeps it from covering the page's own content.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Extras {

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue() {
        $relabel = galado_bundles_wcpa_relabel_enabled() && function_exists('is_product') && is_product();
        $sticky  = galado_bundles_sticky_cart_enabled() && function_exists('is_cart') && is_cart();
        if (!$relabel && !$sticky) return;

        wp_enqueue_style('galado-bundles-extras', GALADO_BUNDLES_URL . 'public/extras.css', [], GALADO_BUNDLES_VERSION);
        wp_enqueue_script('galado-bundles-extras', GALADO_BUNDLES_URL . 'public/extras.js', [], GALADO_BUNDLES_VERSION, true);
        wp_localize_script('galado-bundles-extras', 'GALADO_BUNDLES_EXTRAS', [
            'relabel'      => $relabel ? 1 : 0,
            'sticky'       => $sticky ? 1 : 0,
            'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '/checkout/',
            'i18n'         => [
                'case'     => __('Case:', 'galado-bundles'),
                'addons'   => __('Add-ons:', 'galado-bundles'),
                'continue' => __('Continue to Checkout', 'galado-bundles'),
            ],
        ]);
    }
}
