<?php
/**
 * Phase 3: inbound identity (spec FR-10).
 *
 * The spec framed this as "recognise visitors arriving from our own emails".
 * The part that actually pays is narrower and entirely ours: once we know who
 * someone is, we should not need them to type their address again.
 *
 * Today a row is only written when an email is typed into a form. So a visitor
 * who clicks a recovery link, lands with their cart restored, adds one more
 * item and leaves again is invisible for that second cart, even though we knew
 * exactly who they were the whole time.
 *
 * This holds the identity in the WooCommerce session (server side, never a
 * cookie carrying the address) and re-captures automatically whenever the cart
 * genuinely changes. Sources of identity:
 *   inbound   they arrived on one of our tokenised recovery links
 *   checkout  they typed it into checkout this session
 *   cart      they gave it to the cart prompt
 *   external  another GALADO plugin asserted it via galado_recovery_identify()
 *
 * Deliberately NOT a source: anything in a query string. Spec FR-10 is explicit
 * that a raw email in a URL is forgeable and must never be treated as proof of
 * identity, so arriving with ?email=someone@example.com does nothing at all.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_Identity {

    const SESSION_KEY = 'galado_recovery_identity';
    const LAST_HASH   = 'galado_recovery_last_hash';

    public static function init() {
        // Re-capture after the cart settles. woocommerce_cart_updated fires on
        // add, remove and quantity change (including the AJAX paths), and the
        // cart-hash guard below means a repeat call with an unchanged cart
        // costs one comparison, not a write.
        add_action('woocommerce_cart_updated', [__CLASS__, 'maybe_recapture'], 20);
    }

    /** Remember who this visitor is for the rest of their session. */
    public static function set($email, $source = 'external') {
        $email = sanitize_email((string) $email);
        if (!$email || !is_email($email)) return false;
        if (!function_exists('WC') || !WC()->session) return false;

        WC()->session->set(self::SESSION_KEY, ['email' => $email, 'source' => $source]);
        return true;
    }

    /** The known email for this session, or '' if we do not know them. */
    public static function email() {
        if (!function_exists('WC') || !WC()->session) return '';
        $id = WC()->session->get(self::SESSION_KEY);
        return is_array($id) && !empty($id['email']) ? (string) $id['email'] : '';
    }

    public static function source() {
        if (!function_exists('WC') || !WC()->session) return '';
        $id = WC()->session->get(self::SESSION_KEY);
        return is_array($id) && !empty($id['source']) ? (string) $id['source'] : '';
    }

    public static function forget() {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_KEY, null);
            WC()->session->set(self::LAST_HASH, null);
        }
    }

    /** Record the cart we last persisted, so an unchanged cart is a no-op. */
    public static function note_hash($hash) {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::LAST_HASH, (string) $hash);
        }
    }

    /**
     * Persist the current cart against a known identity, without asking.
     *
     * Runs only when: capture is on, we know the visitor, the cart has items,
     * and the cart differs from whatever we last stored for them. Purchases are
     * excluded because the order hooks close rows and the send path re-checks
     * status immediately before sending.
     */
    public static function maybe_recapture() {
        if (!galado_recovery_enabled()) return;
        if (is_admin() && !wp_doing_ajax()) return;

        $email = self::email();
        if ('' === $email) return;

        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) return;
        $cart = WC()->cart;

        $hash = GALADO_Recovery_DB::cart_hash($cart);
        $last = WC()->session ? (string) WC()->session->get(self::LAST_HASH) : '';
        if ($hash === $last) return; // nothing changed since we last stored it

        $snapshot = GALADO_Recovery_REST::snapshot_cart($cart);
        if (empty($snapshot['items'])) return;

        $session_key = (WC()->session && method_exists(WC()->session, 'get_customer_id'))
            ? (string) WC()->session->get_customer_id() : '';

        $row_id = GALADO_Recovery_DB::upsert(
            $email,
            $session_key,
            wp_json_encode($snapshot),
            $snapshot['hash'],
            $snapshot['total'],
            $snapshot['currency'],
            true,                       // consent carried from the original capture
            self::source() ?: 'inbound'
        );

        self::note_hash($hash);

        if ($row_id && galado_recovery_sending_enabled()
            && !GALADO_Recovery_Klaviyo::recently_pushed($email)) {
            GALADO_Recovery_Klaviyo::queue($row_id, $snapshot['hash'] . '_' . time(), 0);
        }
    }
}

/**
 * Public helper for the rest of the GALADO stack.
 *
 * GALADO Send (Resend) will append its own opaque token to email links; when it
 * resolves one, it calls this so the visitor is known for the rest of the
 * session and their cart keeps being captured without them typing anything.
 * Club SSO and the POS can use it the same way.
 *
 * Pass an email you have ALREADY verified server-side. Never pass something
 * lifted straight from a query string.
 */
function galado_recovery_identify($email, $source = 'external') {
    return GALADO_Recovery_Identity::set($email, $source);
}
