<?php
/**
 * The spending rules, all in code rather than per coupon so they also cover coupons created later.
 *
 * 1. No coupon of any kind discounts the gift card product: promo codes, gift card codes and
 *    Shopping Credits (Points and Rewards redeems through a wc_points_redemption_ coupon).
 *    Hook: woocommerce_coupon_get_items_to_apply (WooCommerce 8.8+). The spec's first idea,
 *    woocommerce_coupon_is_valid_for_product, does not work here: for cart-type coupons
 *    (fixed_cart, percent) WooCommerce ORs it with is_valid_for_cart(), so the gift line would
 *    still be discounted. Removing the gift lines from the items also caps a fixed_cart code at
 *    the other items, and Points and Rewards deducts points from the discount actually applied.
 *    A code on a cart holding only gift cards is rejected with a clear message.
 * 2. A gift card code works alongside any other code, including "individual use only" ones, in
 *    both orders of applying (classic cart and Store API honour the same two filters).
 * 3. Buying a card earns no Points and Rewards points (per line filters). See README: the Club
 *    bridge overrides the order total, so it must leave gift lines out itself.
 * 4. REDIS Dynamic Pricing never prices the gift card, and gift lines never unlock its cart rules.
 * 5. Free-shipping minimums ignore gift card lines.
 * 6. Codes stay out of page text (Clarity) and URLs (GA4, Cloudflare): totals label, notices.
 * 7. Each gift code line on an order records the RM value it paid, for the Club and G-Coins.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Spending {

    const POINTS_PREFIX = 'wc_points_redemption_';
    const ITEM_RM_USED = '_galado_gc_rm_used';

    public static function init() {
        add_filter('woocommerce_coupon_get_items_to_apply', [__CLASS__, 'items_to_apply'], 20, 2);
        add_filter('woocommerce_coupon_is_valid', [__CLASS__, 'is_valid'], 20, 3);
        add_filter('woocommerce_apply_individual_use_coupon', [__CLASS__, 'keep_gift_codes'], 20, 3);
        add_filter('woocommerce_apply_with_individual_use_coupon', [__CLASS__, 'allow_with_individual_use'], 20, 2);
        add_filter('woocommerce_points_earned_for_order_item', [__CLASS__, 'no_points_for_order_item'], 20, 2);
        add_filter('woocommerce_points_earned_for_cart_item', [__CLASS__, 'no_points_for_cart_item'], 20, 3);
        add_filter('viredis_may_be_apply_to_cart', [__CLASS__, 'redis_may_apply'], 20, 4);
        add_filter('viredis_get_current_price', [__CLASS__, 'redis_price'], 20, 4);
        add_filter('viredis_condition_get_cart_subtotal', [__CLASS__, 'redis_cart_subtotal'], 20, 1);
        add_filter('woocommerce_shipping_free_shipping_is_available', [__CLASS__, 'free_shipping'], 20, 3);
        add_filter('woocommerce_cart_totals_coupon_label', [__CLASS__, 'coupon_label'], 20, 2);
        add_filter('woocommerce_coupon_error', [__CLASS__, 'coupon_error'], 99, 3);
        add_filter('woocommerce_coupon_message', [__CLASS__, 'coupon_message'], 99, 3);
        add_action('wp_footer', [__CLASS__, 'mask_coupon_fields']);
        add_action('woocommerce_checkout_create_order_coupon_item', [__CLASS__, 'stamp_coupon_item'], 10, 4);
    }

    /** 1. Gift card lines are never discounted by any coupon. */
    public static function items_to_apply($items) {
        $out = [];
        foreach ((array) $items as $item) {
            if (isset($item->product) && $item->product instanceof WC_Product && Galado_GC_Product::is_gift_card_product($item->product)) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /** 1. A code on a cart of gift cards only has nothing to discount: say so plainly. */
    public static function is_valid($valid, $coupon, $discounts = null) {
        if (!$valid || !$discounts instanceof WC_Discounts) {
            return $valid;
        }
        $items = $discounts->get_items_to_validate();
        if (!$items) {
            return $valid;
        }
        foreach ($items as $item) {
            if (!isset($item->product) || !$item->product instanceof WC_Product || !Galado_GC_Product::is_gift_card_product($item->product)) {
                return $valid; // something else in the cart: the code applies to that
            }
        }
        throw new Exception(self::gift_only_message($coupon), 100);
    }

    public static function gift_only_message($coupon) {
        $code = $coupon instanceof WC_Coupon ? (string) $coupon->get_code() : '';
        if (Galado_GC_Codes::is_gift_coupon($coupon) || Galado_GC_Codes::looks_like_code($code)) {
            return __('A gift card can’t be used to buy another gift card.', 'galado-gift-cards');
        }
        if (0 === strpos($code, self::POINTS_PREFIX)) {
            return __('Shopping Credits can’t be used to buy a gift card.', 'galado-gift-cards');
        }
        return __('Discount codes can’t be used to buy a gift card.', 'galado-gift-cards');
    }

    /** 2. Applying an "individual use only" code keeps the gift card codes already applied. */
    public static function keep_gift_codes($keep, $the_coupon, $applied) {
        $keep = (array) $keep;
        foreach ((array) $applied as $code) {
            // Returned exactly as WooCommerce holds it: it matches with a strict comparison.
            if (Galado_GC_Codes::is_gift_card_code($code) && !in_array($code, $keep, true)) {
                $keep[] = $code;
            }
        }
        return $keep;
    }

    /** 2. A gift card code may join a cart that already has an "individual use only" code. */
    public static function allow_with_individual_use($apply, $the_coupon) {
        return Galado_GC_Codes::is_gift_coupon($the_coupon) ? true : $apply;
    }

    /** 3. Buying a card earns no points (order, at payment). */
    public static function no_points_for_order_item($points, $product) {
        return ($product instanceof WC_Product && Galado_GC_Product::is_gift_card_product($product)) ? 0 : $points;
    }

    /** 3. Buying a card earns no points (the "you will earn" message in cart and checkout). */
    public static function no_points_for_cart_item($points, $item_key, $item) {
        $product = is_array($item) && isset($item['data']) ? $item['data'] : null;
        return ($product instanceof WC_Product && Galado_GC_Product::is_gift_card_product($product)) ? 0 : $points;
    }

    /** 4. REDIS: no pricing rule applies to the gift card product ('check' lets REDIS decide). */
    public static function redis_may_apply($check, $rule_id = 0, $conditions = [], $product = null) {
        return ($product instanceof WC_Product && Galado_GC_Product::is_gift_card_product($product)) ? false : $check;
    }

    /** 4. REDIS: the gift card keeps its own price. */
    public static function redis_price($current_price, $price, $product_id = 0, $product = null) {
        $is_gift = $product instanceof WC_Product ? Galado_GC_Product::is_gift_card_product($product) : Galado_GC_Product::is_gift_card_product($product_id);
        return $is_gift ? $price : $current_price;
    }

    /** 4. REDIS: gift lines never count toward a cart subtotal condition. */
    public static function redis_cart_subtotal($subtotal) {
        return max(0.0, (float) $subtotal - self::cart_gift_subtotal());
    }

    /**
     * 5. Free shipping, recomputed the way WooCommerce's own method does it but without the gift
     * lines. Only ever narrows availability; never grants free shipping WooCommerce refused.
     */
    public static function free_shipping($is_available, $package, $method) {
        if (!$is_available || !function_exists('WC') || !WC()->cart) {
            return $is_available;
        }
        $gift = self::cart_gift_subtotal();
        if ($gift <= 0 || !is_object($method) || !in_array($method->requires, ['min_amount', 'either', 'both'], true)) {
            return $is_available;
        }
        $cart = WC()->cart;
        $has_coupon = false;
        foreach ($cart->get_coupons() as $coupon) {
            if ($coupon->is_valid() && $coupon->get_free_shipping()) {
                $has_coupon = true;
                break;
            }
        }
        $discount = (float) $cart->get_discount_total();
        if ($cart->display_prices_including_tax()) {
            $discount += (float) $cart->get_discount_tax();
        }
        return self::free_shipping_rule(
            (string) $method->requires, (float) $method->min_amount, (string) $method->ignore_discounts,
            (float) $cart->get_displayed_subtotal(), $gift, $discount, $has_coupon, wc_get_price_decimals()
        );
    }

    /** WooCommerce's free-shipping rule with the gift lines taken out of the subtotal. Pure. */
    public static function free_shipping_rule($requires, $min_amount, $ignore_discounts, $displayed_subtotal, $gift_subtotal, $discount, $has_coupon, $decimals = 2) {
        $total = $displayed_subtotal - $gift_subtotal;
        if ('no' === $ignore_discounts) {
            $total -= $discount;
        }
        $met = round($total, (int) $decimals) >= $min_amount;
        switch ($requires) {
            case 'min_amount':
                return $met;
            case 'coupon':
                return $has_coupon;
            case 'both':
                return $met && $has_coupon;
            case 'either':
                return $met || $has_coupon;
            default:
                return true;
        }
    }

    /** Displayed subtotal of the gift lines in the cart, in the cart's currency. */
    public static function cart_gift_subtotal() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0.0;
        }
        $incl = WC()->cart->display_prices_including_tax();
        $sum = 0.0;
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['data']) && $item['data'] instanceof WC_Product && Galado_GC_Product::is_gift_card_product($item['data'])) {
                $sum += (float) $item['line_subtotal'] + ($incl ? (float) $item['line_subtotal_tax'] : 0.0);
            }
        }
        return $sum;
    }

    /** 6. "Gift card ending 9PWA" instead of "Coupon: gift-7kq4-m2xd-9pwa" in the totals. */
    public static function coupon_label($label, $coupon) {
        if (Galado_GC_Codes::is_gift_coupon($coupon)) {
            /* translators: %s: last four characters of the code */
            return sprintf(__('Gift card ending %s', 'galado-gift-cards'), Galado_GC_Codes::tail($coupon->get_code()));
        }
        return $label;
    }

    /** 6. Errors about a gift card in plain words, never repeating the code. */
    public static function coupon_error($message, $code = 0, $coupon = null) {
        $is_gift = Galado_GC_Codes::is_gift_coupon($coupon)
            || ($coupon instanceof WC_Coupon && Galado_GC_Codes::looks_like_code($coupon->get_code()))
            || preg_match(Galado_GC_Codes::FIND_PATTERN, (string) $message);
        if (!$is_gift) {
            return $message;
        }
        switch ((int) $code) {
            case 105: // WC_Coupon::E_WC_COUPON_NOT_EXIST
                return __('We couldn’t find that gift card. Check the code and try again.', 'galado-gift-cards');
            case 106: // WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED
                return __('This gift card has already been used.', 'galado-gift-cards');
            case 107: // WC_Coupon::E_WC_COUPON_EXPIRED
                return __('This gift card has expired.', 'galado-gift-cards');
            case 115: // WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK
            case 116: // WC_Coupon::E_WC_COUPON_USAGE_LIMIT_COUPON_STUCK_GUEST
                // WooCommerce 10.5.3 compares the held-usage count ($wpdb->get_var(), a string) with
                // `0 ===`, so every guest trying a genuinely used coupon lands here. Decide from the
                // real numbers: only a live hold by another checkout means "try again".
                return self::is_held_by_unpaid_order($coupon)
                    ? __('This gift card is in another order that has not been paid yet. Please try again in a few minutes.', 'galado-gift-cards')
                    : __('This gift card has already been used.', 'galado-gift-cards');
        }
        return Galado_GC_Codes::mask_in_text($message);
    }

    /** True when an unpaid checkout currently holds this coupon (WooCommerce's tentative usage). */
    public static function is_held_by_unpaid_order($coupon) {
        if (!$coupon instanceof WC_Coupon || !$coupon->get_id() || !method_exists($coupon, 'get_data_store')) {
            return false;
        }
        $store = $coupon->get_data_store();
        return is_callable([$store, 'get_tentative_usage_count']) && (int) $store->get_tentative_usage_count($coupon->get_id()) > 0;
    }

    public static function coupon_message($message, $code = 0, $coupon = null) {
        return Galado_GC_Codes::mask_in_text($message);
    }

    /**
     * 6. Session recordings (Clarity) never see what is typed into the coupon field. Clarity's
     * default masking already hides input values; this makes it explicit for the code fields.
     */
    public static function mask_coupon_fields() {
        if (!function_exists('is_cart') || !(is_cart() || is_checkout())) {
            return;
        }
        echo "<script>(function(){function m(){var s='input[name=\"coupon_code\"],.cart-discount,[data-coupon]';" .
            "document.querySelectorAll(s).forEach(function(e){e.setAttribute('data-clarity-mask','True');});}" .
            "m();if(window.jQuery){jQuery(document.body).on('updated_cart_totals updated_checkout applied_coupon applied_coupon_in_checkout',m);}})();</script>\n";
    }

    /**
     * 7. At checkout, record on each gift code line the RM value it paid. CURCY converts a
     * fixed_cart coupon on read, so the discount is in the shopper's currency; the share of the
     * card used times its RM value gives the RM paid with no exchange rate needed.
     */
    public static function stamp_coupon_item($item, $code, $coupon, $order = null) {
        if (!Galado_GC_Codes::is_gift_coupon($coupon)) {
            return;
        }
        $applied = (float) $item->get_discount() + (float) $item->get_discount_tax();
        $converted = (float) $coupon->get_amount();          // shopper's currency (CURCY view)
        $rm_value = (float) $coupon->get_amount('edit');     // stored ringgit
        $rm_used = $converted > 0 ? min($rm_value, $rm_value * $applied / $converted) : 0.0;
        $item->add_meta_data(Galado_GC_Codes::META_FLAG, 'yes', true);
        $item->add_meta_data(self::ITEM_RM_USED, wc_format_decimal($rm_used, 2), true);
    }

    /** RM value paid by gift card codes on an order. */
    public static function order_gift_coupon_total($order) {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($order->get_items('coupon') as $item) {
            $flag = 'yes' === $item->get_meta(Galado_GC_Codes::META_FLAG);
            if (!$flag && !Galado_GC_Codes::is_gift_card_code($item->get_code())) {
                continue;
            }
            $stamped = $item->get_meta(self::ITEM_RM_USED);
            if ('' !== (string) $stamped) {
                $total += (float) $stamped;
            } elseif ($order->get_currency() === get_option('woocommerce_currency')) {
                $total += (float) $item->get_discount() + (float) $item->get_discount_tax(); // counter / admin orders in RM
            } else {
                error_log('[galado-gift-cards] gift_coupon_rm_unknown order=' . $order->get_id() . ' currency=' . $order->get_currency());
            }
        }
        return round($total, 2);
    }
}
