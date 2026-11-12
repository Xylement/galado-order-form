<?php
/**
 * Delivery, and the two payment safeguards that decide whether a card may go out.
 *
 * - One Action Scheduler job per order line: at once if the chosen date is today or earlier, else
 *   09:00 Malaysian time on that date. It emails the recipient, then tells the buyer it was sent.
 * - 3-D Secure: gift card orders paid by card ask Stripe for 3-D Secure on every payment, through
 *   Payment Plugins for Stripe's own filter wc_stripe_payment_intent_args (create and update).
 * - Risk hold: the same plugin fires wc_stripe_save_order_meta with the Stripe charge before it
 *   marks the order paid; the charge carries outcome.risk_level. Elevated or highest holds
 *   delivery until an admin releases it (Order actions > Release held gift cards).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Delivery {

    const HOOK = 'galado_gc_deliver';
    const GROUP = 'galado-gift-cards';
    const ITEM_SENT_AT = '_galado_gc_sent_at';
    const ORDER_RISK = '_galado_gc_risk_level';
    const ORDER_HOLD = '_galado_gc_hold';
    const ORDER_RELEASED = '_galado_gc_released';
    const HOLD_LEVELS = ['elevated', 'highest'];

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'deliver'], 10, 2);
        add_filter('woocommerce_email_classes', [__CLASS__, 'register_emails']);
        add_filter('wc_stripe_payment_intent_args', [__CLASS__, 'request_three_d_secure'], 20, 2);
        add_action('wc_stripe_save_order_meta', [__CLASS__, 'record_stripe_risk'], 10, 3);
        add_filter('woocommerce_order_actions', [__CLASS__, 'order_actions'], 10, 2);
        add_action('woocommerce_order_action_galado_gc_release', [__CLASS__, 'action_release']);
        add_action('woocommerce_order_action_galado_gc_send_now', [__CLASS__, 'action_send_now']);
    }

    public static function register_emails($emails) {
        require_once GALADO_GC_DIR . 'includes/emails/class-galado-gc-email-recipient.php';
        require_once GALADO_GC_DIR . 'includes/emails/class-galado-gc-email-buyer-sent.php';
        $emails['Galado_GC_Email_Recipient'] = new Galado_GC_Email_Recipient();
        $emails['Galado_GC_Email_Buyer_Sent'] = new Galado_GC_Email_Buyer_Sent();
        return $emails;
    }

    /** Schedule every unsent card line of a paid order. Safe to call any number of times. */
    public static function schedule_for_order($order_id, $send_now = false) {
        $order = wc_get_order($order_id);
        if (!$order || !function_exists('as_schedule_single_action')) {
            return;
        }
        foreach (Galado_GC_Cart::order_gift_items($order) as $item_id => $item) {
            if ($item->get_meta(self::ITEM_SENT_AT)) {
                continue;
            }
            $args = [(int) $order_id, (int) $item_id];
            $when = $send_now ? time() : Galado_GC_Time::delivery_timestamp($item->get_meta(Galado_GC_Cart::META_DATE));
            $next = as_next_scheduled_action(self::HOOK, $args, self::GROUP);
            if ($next === true || (is_int($next) && (!$send_now || $next <= time()))) {
                continue; // already queued (true means running or queued to run now)
            }
            if ($send_now && is_int($next)) {
                as_unschedule_action(self::HOOK, $args, self::GROUP);
            }
            if ($when <= time()) {
                as_enqueue_async_action(self::HOOK, $args, self::GROUP);
            } else {
                as_schedule_single_action($when, self::HOOK, $args, self::GROUP);
            }
        }
    }

    /** Action Scheduler job: email one order line's cards to their recipient, once. */
    public static function deliver($order_id, $item_id) {
        $lock = 'galado_gc_send_' . substr(md5((string) $item_id . '|' . Galado_GC_Issuer::lock_name($order_id)), 0, 20);
        if (!Galado_GC_Issuer::acquire($lock)) {
            return; // another process is sending this line right now
        }
        try {
            clean_post_cache((int) $order_id);
            $order = wc_get_order((int) $order_id);
            if (!$order || !in_array($order->get_status(), ['processing', 'completed'], true)) {
                return;
            }
            $item = $order->get_item((int) $item_id);
            if (!$item || '' === (string) $item->get_meta(Galado_GC_Cart::META_VALUE) || $item->get_meta(self::ITEM_SENT_AT)) {
                return;
            }
            if (self::is_held($order)) {
                if (!$order->get_meta('_galado_gc_hold_noted')) {
                    $order->update_meta_data('_galado_gc_hold_noted', '1');
                    $order->add_order_note(sprintf(
                        /* translators: %s: risk level */
                        __('Gift card delivery is on hold: Stripe rated this payment %s risk. Check the payment, then choose "Release held gift cards" in Order actions.', 'galado-gift-cards'),
                        $order->get_meta(self::ORDER_HOLD)
                    ));
                    $order->save();
                }
                return;
            }
            $cards = self::active_cards($item_id);
            if (!$cards) {
                return;
            }
            $mailer = WC()->mailer();
            $emails = $mailer->get_emails();
            $sent = isset($emails['Galado_GC_Email_Recipient'])
                && $emails['Galado_GC_Email_Recipient']->trigger($order, $item, $cards);
            if (!$sent) {
                error_log('[galado-gift-cards] delivery_failed order=' . (int) $order_id . ' item=' . (int) $item_id);
                $order->add_order_note(__('A gift card email could not be sent. Choose "Send gift card emails now" in Order actions to try again.', 'galado-gift-cards'));
                return;
            }
            $item->update_meta_data(self::ITEM_SENT_AT, (string) time());
            $item->save();
            $tails = array_map(function ($c) { return Galado_GC_Codes::tail($c['code']); }, $cards);
            $order->add_order_note(sprintf(
                /* translators: %s: code endings */
                __('Gift card emailed to the recipient (ending %s).', 'galado-gift-cards'),
                implode(', ', $tails)
            ));
            if (isset($emails['Galado_GC_Email_Buyer_Sent'])) {
                $emails['Galado_GC_Email_Buyer_Sent']->trigger($order, $item, $cards);
            }
        } finally {
            Galado_GC_Issuer::release($lock);
        }
    }

    /**
     * The line's usable cards for an email: published, unused.
     *
     * @return array[] each [code, value (RM float), expires (timestamp)]
     */
    public static function active_cards($item_id) {
        $cards = [];
        foreach (Galado_GC_Issuer::coupons_for_item($item_id) as $coupon_id) {
            $coupon = new WC_Coupon($coupon_id);
            if ('publish' !== $coupon->get_status() || $coupon->get_usage_count() > 0) {
                continue;
            }
            $expires = $coupon->get_date_expires();
            $cards[] = [
                'code'    => Galado_GC_Codes::display($coupon->get_code()),
                'value'   => (float) $coupon->get_amount('edit'),
                'expires' => $expires ? $expires->getTimestamp() : 0,
            ];
        }
        return $cards;
    }

    public static function is_held($order) {
        return '' !== (string) $order->get_meta(self::ORDER_HOLD) && '' === (string) $order->get_meta(self::ORDER_RELEASED);
    }

    /**
     * Ask for 3-D Secure on card payments for any order holding a gift card. Only card intents:
     * GrabPay, PayNow and other Stripe methods are left alone.
     */
    public static function request_three_d_secure($args, $order) {
        if (!$order instanceof WC_Order || !Galado_GC_Cart::order_gift_items($order)) {
            return $args;
        }
        $types = isset($args['payment_method_types']) ? (array) $args['payment_method_types'] : [];
        if (in_array('card', $types, true)) {
            $args['payment_method_options']['card']['request_three_d_secure'] = 'any';
        }
        return $args;
    }

    /** Record Stripe's risk level on gift card orders; hold delivery when it is elevated or highest. */
    public static function record_stripe_risk($order, $gateway, $charge) {
        if (!$order instanceof WC_Order || !Galado_GC_Cart::order_gift_items($order)) {
            return;
        }
        $level = '';
        if (is_object($charge) && isset($charge->outcome) && is_object($charge->outcome) && isset($charge->outcome->risk_level)) {
            $level = (string) $charge->outcome->risk_level;
        } elseif (is_array($charge) && isset($charge['outcome']['risk_level'])) {
            $level = (string) $charge['outcome']['risk_level'];
        }
        if ('' === $level) {
            return;
        }
        $order->update_meta_data(self::ORDER_RISK, sanitize_key($level));
        if (Galado_GC_Config::risk_hold_enabled() && in_array($level, self::HOLD_LEVELS, true)) {
            $order->update_meta_data(self::ORDER_HOLD, sanitize_key($level));
        }
        // Payment Plugins for Stripe saves the order right after this action.
    }

    public static function order_actions($actions, $order = null) {
        if (!$order instanceof WC_Order || !Galado_GC_Cart::order_gift_items($order)) {
            return $actions;
        }
        if (self::is_held($order)) {
            $actions['galado_gc_release'] = __('Release held gift cards', 'galado-gift-cards');
        } elseif (in_array($order->get_status(), ['processing', 'completed'], true)) {
            $actions['galado_gc_send_now'] = __('Send gift card emails now', 'galado-gift-cards');
        }
        return $actions;
    }

    /** Admin: release a risk hold. Cards then go out at their chosen time (now, if it has passed). */
    public static function action_release($order) {
        if (!current_user_can('manage_woocommerce') || !$order instanceof WC_Order) {
            return;
        }
        $order->update_meta_data(self::ORDER_RELEASED, wp_json_encode(['by' => get_current_user_id(), 'at' => time()]));
        $order->add_order_note(__('Gift card hold released. Cards go out at their chosen delivery time.', 'galado-gift-cards'), 0, true);
        $order->save();
        self::schedule_for_order($order->get_id());
    }

    /** Admin: send every unsent card of the order now (after a failed email, for example). */
    public static function action_send_now($order) {
        if (!current_user_can('manage_woocommerce') || !$order instanceof WC_Order || self::is_held($order)) {
            return;
        }
        self::schedule_for_order($order->get_id(), true);
    }
}
