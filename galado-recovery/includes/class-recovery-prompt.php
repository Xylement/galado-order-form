<?php
/**
 * Phase 2: cart-stage capture (spec FR-9).
 *
 * A dismissible inline card on /cart/, rendered in normal document flow BELOW
 * the Proceed to Checkout button (woocommerce_after_cart_totals). Hard site
 * constraints honoured: no overlay, nothing above or displacing the Proceed
 * button (its dead-first-tap history), no layout shift (server-rendered, not
 * injected), no animation, and the script is deferred.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_Prompt {

    const DISMISS_COOKIE = 'galado_recovery_dismiss';

    public static function init() {
        add_action('woocommerce_after_cart_totals', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue() {
        if (!function_exists('is_cart') || !is_cart()) return;
        if (self::skip()) return;
        wp_enqueue_style('galado-recovery', GALADO_RECOVERY_URL . 'public/recovery.css', [], GALADO_RECOVERY_VERSION);
        GALADO_Recovery_Checkout::enqueue_capture_js('cart');
    }

    /**
     * Logged-in visitors are already identified; dismissals persist 30 days.
     *
     * Also skipped whenever no send channel is selected. The prompt asks for an
     * email in exchange for an emailed link, so running it while sending is
     * dark would be asking customers for their address under a promise we
     * cannot keep. Capture-for-later is fine at checkout, where the email is
     * given for the order anyway; it is not fine here, where the email IS the
     * ask and the link is the whole consideration.
     */
    private static function skip() {
        if (!galado_recovery_sending_enabled()) return true;
        if (is_user_logged_in()) return true;
        if (!empty($_COOKIE[self::DISMISS_COOKIE])) return true;
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) return true;
        return false;
    }

    public static function render() {
        if (self::skip()) return;
        ?>
        <aside class="galado-recovery-prompt" id="galado-recovery-prompt" aria-label="<?php esc_attr_e('Save your cart', 'galado-recovery'); ?>">
            <button type="button" class="grv-dismiss" data-grv-dismiss aria-label="<?php esc_attr_e('Dismiss', 'galado-recovery'); ?>">&times;</button>
            <p class="grv-title"><?php esc_html_e('Not ready to check out?', 'galado-recovery'); ?></p>
            <p class="grv-sub"><?php esc_html_e('Leave your email and we will save your cart, with a link to pick up where you left off.', 'galado-recovery'); ?></p>
            <div class="grv-row">
                <label class="screen-reader-text" for="grv-email"><?php esc_html_e('Email address', 'galado-recovery'); ?></label>
                <input type="email" id="grv-email" class="grv-email" placeholder="<?php esc_attr_e('Email address', 'galado-recovery'); ?>" autocomplete="email" inputmode="email">
                <input type="text" class="grv-hp" name="hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true">
                <button type="button" class="grv-save" data-grv-save><?php esc_html_e('Save my cart', 'galado-recovery'); ?></button>
            </div>
            <p class="grv-note" data-grv-note><?php esc_html_e('One reminder email, unsubscribe anytime.', 'galado-recovery'); ?></p>
        </aside>
        <?php
    }
}
