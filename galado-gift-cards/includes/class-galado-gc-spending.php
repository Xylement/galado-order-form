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
 * 3. Points are left as they are: whoever pays earns on what they pay (the buyer on the card, the
 *    recipient only on what they pay on top of it). A Shopping Credits redemption in a cart that
 *    also buys a card gets its full amount on the other items.
 * 4. REDIS Dynamic Pricing never prices the gift card. Gift lines never count toward its cart
 *    subtotal conditions, and never help meet an item-quantity minimum or an "all items" bulk count
 *    (an item-quantity maximum still counts them: REDIS checks that itself, after this plugin).
 * 5. Free-shipping minimums ignore gift card lines.
 * 6. Codes stay out of customer-facing page text (Clarity), URLs (GA4, Cloudflare) and order notes:
 *    totals label, remove link, notices, notes. (Staff see full codes on the admin order screen.)
 * 7. Discount fees (REDIS cart rules, Club offers, anything added as a negative fee) never pay for
 *    a gift card being bought: together they are capped at what the other lines and shipping cost.
 *    When a gift card trims a Club win-back (this cap, or a card paying for the whole order), the
 *    figure the Club bridge records for the member's balance is corrected to what was given.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Spending {

    const POINTS_PREFIX = 'wc_points_redemption_';
    // The Club bridge's win-back offer: its fee name prefix and the RM it records on the order.
    const CLUB_WINBACK_FEE = 'GALADO Club reward';
    const CLUB_WINBACK_META = '_galado_winback_applied';

    /** Discount fees dropped by cap_discount_fees in the latest cart calculation (cart currency). */
    public static $fees_dropped = 0.0;

    public static function init() {
        add_filter('woocommerce_coupon_get_items_to_apply', [__CLASS__, 'items_to_apply'], 20, 2);
        add_filter('woocommerce_coupon_is_valid', [__CLASS__, 'is_valid'], 20, 3);
        add_filter('woocommerce_apply_individual_use_coupon', [__CLASS__, 'keep_gift_codes'], 20, 3);
        add_filter('woocommerce_apply_with_individual_use_coupon', [__CLASS__, 'allow_with_individual_use'], 20, 2);
        add_filter('viredis_may_be_apply_to_cart', [__CLASS__, 'redis_may_apply'], 20, 6);
        add_filter('viredis_get_current_price', [__CLASS__, 'redis_price'], 20, 4);
        add_filter('viredis_condition_get_cart_subtotal', [__CLASS__, 'redis_cart_subtotal'], 20, 1);
        add_filter('woocommerce_shipping_free_shipping_is_available', [__CLASS__, 'free_shipping'], 20, 3);
        add_filter('viredis_get_product_qty_in_cart', [__CLASS__, 'redis_qty_in_cart'], 20, 3);
        add_filter('woocommerce_coupon_get_discount_amount', [__CLASS__, 'points_share_without_gift_lines'], 20, 5);
        add_filter('woocommerce_cart_totals_coupon_label', [__CLASS__, 'coupon_label'], 20, 2);
        add_filter('woocommerce_cart_totals_coupon_html', [__CLASS__, 'coupon_html'], 20, 2);
        add_filter('woocommerce_new_order_note_data', [__CLASS__, 'mask_order_note']);
        add_filter('woocommerce_coupon_error', [__CLASS__, 'coupon_error'], 99, 3);
        add_filter('woocommerce_coupon_message', [__CLASS__, 'coupon_message'], 99, 3);
        add_action('wp_footer', [__CLASS__, 'mask_coupon_fields']);
        // After every plugin has registered its fee callbacks (REDIS adds its own at PHP_INT_MAX
        // when it loads), so at the same priority this runs last.
        add_action('wp_loaded', function () {
            add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'cap_discount_fees'], PHP_INT_MAX);
        }, 0);
        // After the Club bridge records its win-back (priority 10 on both checkout paths).
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'match_club_winback_record'], 20, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'match_club_winback_record'], 20, 1);
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

    /**
     * 4. REDIS: no pricing rule applies to the gift card product ('check' lets REDIS decide). An
     * item-quantity minimum counts every cart line; recounted without the gift lines, a rule the
     * other items alone do not reach is refused here. (This filter can only refuse, so a maximum is
     * left to REDIS, which still counts the cards.)
     */
    public static function redis_may_apply($check, $rule_id = 0, $conditions = [], $product = null, $product_id = 0, $product_qty = 0) {
        if ($product instanceof WC_Product && Galado_GC_Product::is_gift_card_product($product)) {
            return false;
        }
        $gift_qty = self::cart_gift_qty();
        if ('check' !== $check || $gift_qty <= 0 || !is_array($conditions) || empty($conditions['qty_item']) || !is_array($conditions['qty_item'])) {
            return $check;
        }
        $min = (int) ($conditions['qty_item']['qty_item_min'] ?? 0);
        $qty = WC()->cart->get_cart_contents_count() - $gift_qty + (int) $product_qty;
        return ($min && $min > $qty) ? false : $check;
    }

    /** 4. REDIS bulk pricing "all items in the cart": leave the gift lines out of the count. */
    public static function redis_qty_in_cart($qty, $product_id = 0, $product_qty = 0) {
        $gift_qty = self::cart_gift_qty();
        if ($gift_qty > 0 && function_exists('WC') && WC()->cart && (int) $qty === WC()->cart->get_cart_contents_count() + (int) $product_qty) {
            return max(0, (int) $qty - $gift_qty);
        }
        return $qty;
    }

    /** Number of gift cards in the cart. */
    public static function cart_gift_qty() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }
        $n = 0;
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['data']) && $item['data'] instanceof WC_Product && Galado_GC_Product::is_gift_card_product($item['data'])) {
                $n += (int) $item['quantity'];
            }
        }
        return $n;
    }

    /**
     * 3. Points and Rewards spreads a Shopping Credits redemption over the lines in proportion to
     * the cart subtotal, gift lines included. The gift lines get none of it (rule 1), so without
     * this the other lines would receive only their share and the rest would go unused. Scale each
     * share up to the subtotal without the gift lines, never above what the line can take.
     */
    public static function points_share_without_gift_lines($discount, $discounting_amount, $cart_item = null, $single = false, $coupon = null) {
        if (!$coupon instanceof WC_Coupon || 0 !== strpos(strtolower((string) $coupon->get_code()), self::POINTS_PREFIX)
            || (float) $discount <= 0 || !function_exists('WC') || !WC()->cart) {
            return $discount;
        }
        $incl = wc_prices_include_tax();
        $pr = isset($GLOBALS['wc_points_rewards']) ? $GLOBALS['wc_points_rewards'] : null;
        $pr_discount = ($pr && isset($pr->discount) && is_object($pr->discount)) ? $pr->discount : null;
        // Each gift line's weight in P&R's split: its price less the fixed_product discount P&R counts
        // on it (P&R counts one even though rule 1 never applies it there), so base - gift is exactly
        // what P&R divided the other lines' shares by and the scaled shares add up to the redemption.
        $gift = 0.0;
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['data']) && $item['data'] instanceof WC_Product && Galado_GC_Product::is_gift_card_product($item['data'])) {
                $price = $incl ? wc_get_price_including_tax($item['data']) : wc_get_price_excluding_tax($item['data']);
                $gift += (float) $price * (int) $item['quantity']
                    - (($pr_discount && is_callable([$pr_discount, 'get_cart_item_discount_total'])) ? (float) $pr_discount->get_cart_item_discount_total($item) : 0.0);
            }
        }
        if ($gift <= 0) {
            return $discount;
        }
        // The same base Points and Rewards divides by: the subtotal less fixed_product discounts.
        $existing = ($pr_discount && is_callable([$pr_discount, 'get_discount_total_from_existing_coupons']))
            ? (float) $pr_discount->get_discount_total_from_existing_coupons() : 0.0;
        $cart = WC()->cart; // WC_Cart::$subtotal / $subtotal_ex_tax, as Points and Rewards reads them
        $base = (float) $cart->get_subtotal() + ($incl ? (float) $cart->get_subtotal_tax() : 0.0) - $existing;
        if ($base - $gift <= 0) {
            return $discount;
        }
        return round(min((float) $discount * $base / ($base - $gift), (float) $discounting_amount), wc_get_rounding_precision());
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
                // line_subtotal is only set once WooCommerce has totalled the cart (not yet during
                // the first pass after an add to cart, when REDIS may already ask); the card is not
                // taxable, so its price times quantity is the same figure.
                $sum += isset($item['line_subtotal'])
                    ? (float) $item['line_subtotal'] + ($incl ? (float) ($item['line_subtotal_tax'] ?? 0) : 0.0)
                    : (float) $item['data']->get_price() * (int) $item['quantity'];
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

    /**
     * 6. The [Remove] link beside a card in the totals: WooCommerce puts the code in its address
     * (?remove_coupon=...) and its label. Removal works through data-coupon over AJAX; the address
     * becomes the plain cart or checkout page, so following it without the script shows no code.
     */
    public static function coupon_html($html, $coupon) {
        if (!Galado_GC_Codes::is_gift_coupon($coupon)) {
            return $html;
        }
        $page = (defined('WOOCOMMERCE_CHECKOUT') && WOOCOMMERCE_CHECKOUT) ? wc_get_checkout_url() : wc_get_cart_url();
        $html = preg_replace('/\shref="[^"]*"/', ' href="' . esc_url($page) . '"', (string) $html, 1);
        return preg_replace('/\saria-label="[^"]*"/', ' aria-label="' . esc_attr__('Remove gift card', 'galado-gift-cards') . '"', $html, 1);
    }

    /** 6. Order notes (WooCommerce writes 'Coupon applied: "..."' when staff add a code). */
    public static function mask_order_note($data) {
        if (is_array($data) && isset($data['comment_content'])) {
            $data['comment_content'] = preg_replace_callback(Galado_GC_Codes::FIND_PATTERN, function ($m) {
                /* translators: %s: last four characters of the code */
                return sprintf(__('gift card ending %s', 'galado-gift-cards'), Galado_GC_Codes::tail($m[0]));
            }, (string) $data['comment_content']);
        }
        return $data;
    }

    /**
     * 7. Negative fees are applied after coupons, and WooCommerce only caps them at the cart's
     * items and shipping, gift lines included. So a discount fee could pay for a card being bought
     * (a RM30 offer on a RM20 charm and a RM100 card takes RM10 off the card). Cap them together
     * at what the other lines (after coupons), positive fees and shipping cost. Each cut fee keeps
     * its original amount in galado_gc_original, for the remainder warning; a fee cut to nothing is
     * dropped, so no "RM30 off" line shows RM0 (and no offer is recorded as used for nothing).
     */
    public static function cap_discount_fees($cart) {
        self::$fees_dropped = 0.0;
        if (!$cart instanceof WC_Cart) {
            return;
        }
        $gift = 0.0;
        $room = 0.0;
        foreach ($cart->get_cart() as $item) {
            $line = (float) ($item['line_total'] ?? 0);
            if (!empty($item['data']) && $item['data'] instanceof WC_Product && Galado_GC_Product::is_gift_card_product($item['data'])) {
                $gift += $line;
            } else {
                $room += $line;
            }
        }
        if ($gift <= 0) {
            return;
        }
        $room += (float) $cart->get_shipping_total();
        $fees = $cart->fees_api()->get_fees();
        foreach ($fees as $fee) {
            if ((float) $fee->amount > 0) {
                $room += (float) $fee->amount;
            }
        }
        $keep = [];
        $dropped = false;
        foreach ($fees as $fee) {
            $amount = (float) $fee->amount;
            if ($amount < 0) {
                $take = min(-$amount, max(0.0, $room));
                $room -= $take;
                if ($take < -$amount) {
                    $fee->galado_gc_original = isset($fee->galado_gc_original) ? $fee->galado_gc_original : $amount;
                    $fee->amount = wc_format_decimal(-$take);
                }
                if ($take <= 0) {
                    $dropped = true;
                    self::$fees_dropped += -$amount;
                    continue;
                }
            }
            $keep[] = $fee;
        }
        if ($dropped) {
            $cart->fees_api()->set_fees($keep);
        }
    }

    /**
     * 7. The Club bridge records a win-back figure on the order at checkout (priority 10) and takes
     * it from the member's balance on payment. With a gift card in play the discount actually given
     * can be smaller: this plugin's cap trims or drops the fee when a card is bought, and WooCommerce
     * trims it to nothing when a card pays for the whole order. Make the record match what was given.
     * The bridge's figure depends on its build: up to 0.64.12 (live in Oct 2026) the fee amount after
     * this plugin's cap, in the cart's currency; the money-fixes build (it has $winback_rm) the RM it
     * worked out before any cut. Orders without a gift card are left to the bridge.
     */
    public static function match_club_winback_record($order) {
        if (!$order instanceof WC_Order || !function_exists('WC') || !WC()->cart || !self::cart_involves_gift_card()) {
            return;
        }
        $recorded = (float) $order->get_meta(self::CLUB_WINBACK_META);
        if ($recorded <= 0) {
            return;
        }
        $asked = 0.0;     // what the bridge added, before this plugin's cap
        $after_cap = 0.0; // the fee amount after this plugin's cap
        $given = 0.0;     // what the order really got, after WooCommerce's own cap as well
        foreach (WC()->cart->get_fees() as $fee) {
            if (0 !== strpos((string) $fee->name, self::CLUB_WINBACK_FEE)) {
                continue;
            }
            $amount = abs((float) $fee->amount);
            $after_cap += $amount;
            $asked += isset($fee->galado_gc_original) ? abs((float) $fee->galado_gc_original) : $amount;
            $given += isset($fee->total) ? min($amount, abs((float) $fee->total)) : $amount;
        }
        $base = property_exists('Galado_Club_Bridge', 'winback_rm') ? $asked : $after_cap;
        if ($base <= 0) {
            $right = 0.0; // no win-back fee left (the cap dropped it): the figure is from an earlier pass
        } elseif ($given < $base - 0.005) {
            $right = $recorded * $given / $base;
        } else {
            return; // nothing was held back
        }
        $order->update_meta_data(self::CLUB_WINBACK_META, wc_format_decimal($right, 2));
    }

    /** A gift card line in the cart, or a gift card code applied to it. */
    private static function cart_involves_gift_card() {
        if (self::cart_gift_qty() > 0) {
            return true;
        }
        foreach (WC()->cart->get_applied_coupons() as $code) {
            if (Galado_GC_Codes::is_gift_card_code($code)) {
                return true;
            }
        }
        return false;
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
}
