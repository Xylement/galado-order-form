<?php
/**
 * Checkout-side capture (spec FR-1, FR-2).
 *
 * Classic checkout only (verified: /checkout/ renders the
 * [woocommerce_checkout] shortcode). The billing email field moves to the top
 * of the form with a visible consent line, and a small deferred script captures
 * it on change/blur. Logged-in users are skipped entirely: the official Klaviyo
 * plugin already identifies them, and double-firing Started Checkout could
 * re-trigger the flow.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_Checkout {

    public static function init() {
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'reorder_email'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    /**
     * FR-1: billing_email above every other billing field. WooCommerce billing
     * priorities start at 10 (first name), so 4 puts email first. Validation
     * and required-ness are untouched.
     */
    public static function reorder_email($fields) {
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['priority'] = 4;
            $fields['billing']['billing_email']['class']    = ['form-row-wide'];
            $fields['billing']['billing_email']['label']    = __('Email address', 'galado-recovery');
            // PDPA notice (spec section 5): visible and adjacent, but deliberately
            // one quiet line. Checkout v2 hides labels and uses placeholder pills,
            // and marketing opt-in is already handled by the separate Klaviyo
            // checkbox, so this only has to cover the recovery reminder. Styled
            // small and muted in recovery.css so it cannot dominate the field.
            $fields['billing']['billing_email']['description'] = apply_filters(
                'galado_recovery_email_note',
                __('Order confirmation goes here, plus a reminder if you do not finish.', 'galado-recovery')
            );
        }
        return $fields;
    }

    public static function enqueue() {
        if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url('order-received')) return;
        if (is_user_logged_in()) return;
        wp_enqueue_style('galado-recovery', GALADO_RECOVERY_URL . 'public/recovery.css', [], GALADO_RECOVERY_VERSION);
        self::enqueue_capture_js('checkout');
    }

    /**
     * Shared by checkout and the cart prompt. Deferred, tiny, and fire-and-forget
     * so it can never sit in the checkout critical path (site constraint 3).
     */
    public static function enqueue_capture_js($source) {
        wp_enqueue_script(
            'galado-recovery',
            GALADO_RECOVERY_URL . 'public/recovery-capture.js',
            [],
            GALADO_RECOVERY_VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );

        $settings  = galado_recovery_settings();
        $blocklist = array_filter(array_map('trim', explode("\n", strtolower((string) $settings['blocklist']))));
        $hash      = (function_exists('WC') && WC()->cart) ? GALADO_Recovery_DB::cart_hash(WC()->cart) : '';

        wp_localize_script('galado-recovery', 'GALADO_RECOVERY', [
            'url'       => esc_url_raw(rest_url('galado-recovery/v1/capture')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'cart_hash' => $hash,
            'blocklist' => array_values($blocklist),
            'source'    => $source,
        ]);
    }
}
