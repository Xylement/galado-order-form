<?php
/**
 * Disables unused codes when the money for them goes back.
 *
 * A code is disabled by setting its coupon to Draft: WooCommerce only looks up published coupons,
 * and clears its code cache whenever a coupon stops being published, so a draft code cannot be
 * applied anywhere (website, app or counter), while the record stays for the books and support
 * (drafts are never purged the way trashed posts are). Used codes are never touched. To bring a
 * card back, publish its coupon again.
 *
 * - Cancelled, refunded or failed order: every unused code of the order.
 * - Refund of some lines: only as many unused codes of each refunded card line as cards refunded.
 * - A refund with no line items cannot be matched to a card: it leaves a note for staff instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Revoke {

    const META_REVOKED = '_galado_gc_revoked';

    public static function init() {
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'revoke_order']);
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'revoke_order']);
        add_action('woocommerce_order_status_failed', [__CLASS__, 'revoke_order']);
        add_action('woocommerce_order_refunded', [__CLASS__, 'on_refund'], 10, 2);
    }

    /** Disable every unused code of an order. @return int[] coupon ids disabled now */
    public static function revoke_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return [];
        }
        $reason = 'order_' . $order->get_status();
        $done = [];
        foreach (Galado_GC_Issuer::coupons_for_order($order) as $item_id => $coupons) {
            foreach ($coupons as $coupon_id) {
                if (self::revoke_coupon($coupon_id, $reason)) {
                    $done[] = $coupon_id;
                }
            }
            self::unschedule_if_nothing_left($order_id, $item_id);
        }
        self::note($order, $done);
        return $done;
    }

    /** After any refund: match refunded card lines to their codes. */
    public static function on_refund($order_id, $refund_id) {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (!$order || !$refund || !Galado_GC_Cart::order_gift_items($order)) {
            return [];
        }
        if ('refunded' === $order->get_status()) {
            return self::revoke_order($order_id); // full refund: the status hook may already have done it
        }
        if (!$refund->get_items('line_item')) {
            if ((float) $refund->get_amount() > 0) {
                $order->add_order_note(__('This refund has no line items, so it cannot be matched to a gift card. If it refunds a card, disable that code by hand (set its coupon to Draft).', 'galado-gift-cards'));
            }
            return [];
        }
        $done = [];
        foreach (Galado_GC_Cart::order_gift_items($order) as $item_id => $item) {
            $refunded = (int) abs($order->get_qty_refunded_for_item($item_id));
            if ($refunded <= 0) {
                continue;
            }
            $done = array_merge($done, self::revoke_for_line($item_id, $refunded, 'refund_' . (int) $refund_id));
            self::unschedule_if_nothing_left($order_id, $item_id);
        }
        self::note($order, $done);
        return $done;
    }

    /**
     * Make sure $refunded cards of this line are disabled, counting the ones already disabled, and
     * never touching a used code. Highest card number first, so the choice is stable.
     *
     * @return int[] coupon ids disabled now
     */
    public static function revoke_for_line($item_id, $refunded, $reason) {
        $coupons = Galado_GC_Issuer::coupons_for_item($item_id);
        $already = 0;
        $unused = [];
        foreach ($coupons as $n => $coupon_id) {
            $coupon = new WC_Coupon($coupon_id);
            if ('publish' !== $coupon->get_status()) {
                $already++;
            } elseif (0 === (int) $coupon->get_usage_count()) {
                $unused[$n] = $coupon_id;
            }
        }
        krsort($unused);
        $need = max(0, (int) $refunded - $already);
        $done = [];
        foreach ($unused as $coupon_id) {
            if (count($done) >= $need) {
                break;
            }
            if (self::revoke_coupon($coupon_id, $reason)) {
                $done[] = $coupon_id;
            }
        }
        return $done;
    }

    /** @return bool true if this call disabled it (false if used, missing or already disabled) */
    public static function revoke_coupon($coupon_id, $reason) {
        $coupon = new WC_Coupon($coupon_id);
        if (!$coupon->get_id() || 'publish' !== $coupon->get_status() || (int) $coupon->get_usage_count() > 0
            || !Galado_GC_Codes::is_gift_coupon($coupon)) {
            return false;
        }
        $coupon->set_status('draft');
        $coupon->update_meta_data(self::META_REVOKED, wp_json_encode(['reason' => $reason, 'at' => time()]));
        $coupon->save();
        return true;
    }

    private static function unschedule_if_nothing_left($order_id, $item_id) {
        if (!Galado_GC_Delivery::active_cards($item_id) && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(Galado_GC_Delivery::HOOK, [(int) $order_id, (int) $item_id], Galado_GC_Delivery::GROUP);
        }
    }

    private static function note($order, array $coupon_ids) {
        if (!$coupon_ids) {
            return;
        }
        $tails = array_map(function ($id) { return Galado_GC_Codes::tail(get_post_field('post_title', $id)); }, $coupon_ids);
        $order->add_order_note(sprintf(
            /* translators: 1: count, 2: code endings */
            _n('Disabled %1$d unused gift card code (ending %2$s).', 'Disabled %1$d unused gift card codes (ending %2$s).', count($coupon_ids), 'galado-gift-cards'),
            count($coupon_ids),
            implode(', ', $tails)
        ));
    }
}
