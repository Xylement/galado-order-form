<?php
/**
 * Recovery link handling (spec FR-7, FR-10) and the purchase close (FR-6).
 *
 * https://galado.com.my/?galado_recover=<64 hex chars>
 *
 * The link restores a cart and nothing else. It never authenticates anyone,
 * never sets auth cookies, never resolves to a user account, and prefills
 * nothing beyond the cart. Tokens are looked up by SHA-256 hash, expire after
 * 7 days, and stay reusable inside that window.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_Restore {

    public static function init() {
        add_action('wp_loaded', [__CLASS__, 'maybe_restore'], 20);

        // FR-6: close rows the moment an order exists, before any queued send.
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'close_on_order'], 10, 1);
        add_action('woocommerce_payment_complete', [__CLASS__, 'close_on_order'], 10, 1);
        add_action('woocommerce_thankyou', [__CLASS__, 'close_on_order'], 10, 1);
    }

    public static function maybe_restore() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        if (empty($_GET['galado_recover'])) return;

        $raw = (string) wp_unslash($_GET['galado_recover']);

        // Tampered or malformed token: friendly notice, no error page (FR-7).
        if (!preg_match('/^[a-f0-9]{64}$/i', $raw)) {
            self::bounce(__('That recovery link is not valid. Your cart is here if you would like to start again.', 'galado-recovery'));
        }

        $row = GALADO_Recovery_DB::get_by_token_hash(hash('sha256', strtolower($raw)));
        if (!$row) {
            self::bounce(__('That recovery link is not valid. Your cart is here if you would like to start again.', 'galado-recovery'));
        }

        if (GALADO_Recovery_DB::STATUS_PURCHASED === $row->status) {
            self::bounce(__('Good news: that order was already completed. Feel free to keep browsing.', 'galado-recovery'));
        }

        $expired = !$row->token_expires_at || strtotime($row->token_expires_at . ' UTC') < time();
        if ($expired) {
            GALADO_Recovery_DB::set_status($row->id, GALADO_Recovery_DB::STATUS_EXPIRED);
            self::bounce(__('That recovery link has expired. Your items may still be available in the store.', 'galado-recovery'));
        }

        self::restore_cart($row);
    }

    private static function restore_cart($row) {
        if (!function_exists('WC') || !WC()->cart) return;

        $snapshot = json_decode((string) $row->cart_contents, true);
        $items    = is_array($snapshot) ? ($snapshot['items'] ?? []) : [];
        if (!$items) {
            self::bounce(__('We could not find that saved cart. It may have expired.', 'galado-recovery'));
        }

        WC()->cart->empty_cart();

        $restored = 0;
        $missed   = [];
        foreach ($items as $it) {
            $product_id   = (int) ($it['product_id'] ?? 0);
            $variation_id = (int) ($it['variation_id'] ?? 0);
            $quantity     = max(1, (int) ($it['quantity'] ?? 1));
            $variation    = is_array($it['variation'] ?? null) ? $it['variation'] : [];
            $custom       = is_array($it['custom'] ?? null) ? $it['custom'] : [];
            $name         = (string) ($it['name'] ?? ('#' . $product_id));

            $added = false;
            try {
                $key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation, $custom);
                $added = (bool) $key;
            } catch (Exception $e) {
                $added = false;
            }

            if ($added) {
                $restored++;
            } else {
                $missed[] = $name;
            }
        }

        // FR-10: the token IS the inbound identity. Re-attach the row to this
        // visitor's fresh session so the purchase close can match it, and mark
        // it recovered. No Klaviyo event fires on inbound visits; the visitor
        // is already inside the flow that brought them here.
        $session_key = (WC()->session && method_exists(WC()->session, 'get_customer_id'))
            ? (string) WC()->session->get_customer_id() : '';
        if ('' !== $session_key) {
            GALADO_Recovery_DB::set_session($row->id, $session_key);
        }
        GALADO_Recovery_DB::set_status($row->id, GALADO_Recovery_DB::STATUS_RECOVERED);

        // Channel spec v2: tell G-Send the token was used so it cancels any
        // still-pending recovery email for this address. Queued, so the
        // redirect never waits on it. No-op on other channels.
        if ('galado_send' === galado_recovery_send_channel()) {
            GALADO_Recovery_GSend::queue_recovered($row->id);
        }

        // Phase 3 (FR-10): the token proved who this is, server-side and
        // unforgeably. Hold that for the session so if they now change their
        // cart and leave again, we capture the NEW cart without asking them to
        // type an address we already know.
        GALADO_Recovery_Identity::set($row->email, 'inbound');
        GALADO_Recovery_Identity::note_hash(GALADO_Recovery_DB::cart_hash(WC()->cart));

        if ($restored > 0 && function_exists('wc_add_notice')) {
            wc_add_notice(__('Welcome back. Your cart is just as you left it.', 'galado-recovery'), 'success');
        }
        if ($missed && function_exists('wc_add_notice')) {
            wc_add_notice(sprintf(
                /* translators: %s: comma-separated product names */
                __('We could not restore: %s (no longer available or out of stock).', 'galado-recovery'),
                implode(', ', array_map('esc_html', $missed))
            ), 'notice');
        }

        // Spec FR-7: land on checkout. If nothing at all could be restored,
        // checkout would bounce an empty cart to the cart page anyway, which
        // is where the notices will show.
        wp_safe_redirect($restored > 0 ? wc_get_checkout_url() : wc_get_cart_url());
        exit;
    }

    /** Friendly dead-end: notice on the cart page, never a stack trace. */
    private static function bounce($message) {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'notice');
        }
        wp_safe_redirect(function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/'));
        exit;
    }

    /**
     * FR-6: flip matching rows to purchased. Fires on order processed (before
     * payment redirect), payment complete (may be webhook context with no
     * session) and thank-you, and is idempotent across all three. The Klaviyo
     * sender re-checks row status right before sending, so a purchase always
     * wins the race against a queued push.
     */
    public static function close_on_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $session_key = (function_exists('WC') && WC()->session && method_exists(WC()->session, 'get_customer_id'))
            ? (string) WC()->session->get_customer_id() : '';
        $email = (string) $order->get_billing_email();

        // Ordered products corroborate an email-only match (see mark_purchased).
        $product_ids = [];
        foreach ($order->get_items() as $item) {
            if (method_exists($item, 'get_product_id')) $product_ids[] = (int) $item->get_product_id();
            if (method_exists($item, 'get_variation_id')) $product_ids[] = (int) $item->get_variation_id();
        }

        GALADO_Recovery_DB::mark_purchased($session_key, $email, array_filter(array_unique($product_ids)));

        // Phase 3: drop the held identity too. Woo empties the cart after an
        // order, but if the buyer starts a fresh cart in the same session we
        // want that treated as new intent, not a silent re-capture against the
        // order they just completed.
        GALADO_Recovery_Identity::forget();
    }
}
