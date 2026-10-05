<?php
/**
 * The remainder warning. A card is spent in one order and whatever it cannot cover is lost, so
 * when an applied card is worth more than it covers (the items after other discounts; a gift card
 * never pays for shipping) the cart and checkout say so before payment:
 *   "Your gift card is worth RM100 and this order is RM60. The RM40 left will be lost. Add more items?"
 * Amounts are in the shopper's currency (CURCY converts the card's value like any fixed coupon).
 *
 * The app gets the same figures in the Store API cart response, under extensions.galado-gift-cards:
 *   { remainder_lost: "40.00", currency: "MYR", gift_card_value: "100.00", order_amount: "60.00",
 *     message: "Your gift card is worth RM100 ..." }   (remainder_lost "0.00" and message null when none)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Remainder {

    const STORE_API_NAMESPACE = 'galado-gift-cards';

    public static function init() {
        add_action('woocommerce_cart_totals_before_order_total', [__CLASS__, 'render_row']);
        add_action('woocommerce_review_order_before_order_total', [__CLASS__, 'render_row']);
        // WooCommerce may fire woocommerce_blocks_loaded before our plugins_loaded callback runs.
        if (did_action('woocommerce_blocks_loaded')) {
            self::register_store_api();
        } else {
            add_action('woocommerce_blocks_loaded', [__CLASS__, 'register_store_api']);
        }
    }

    /**
     * Pure maths. $cards: list of [value, applied], both in the same currency. $fees_lost: other
     * discounts the order could not use because the cards had already covered the items (discount
     * fees are applied after coupons, so the card pays first); the cards really covered that much
     * less, since "the order" is the items after other discounts.
     *
     * @return array{value: float, covered: float, remainder: float, count: int}
     */
    public static function compute(array $cards, $decimals = 2, $fees_lost = 0.0) {
        $value = 0.0;
        $covered = 0.0;
        foreach ($cards as $card) {
            $value += (float) $card['value'];
            $covered += min((float) $card['applied'], (float) $card['value']);
        }
        $covered = max(0.0, $covered - max(0.0, (float) $fees_lost));
        $value = round($value, (int) $decimals);
        $covered = round($covered, (int) $decimals);
        $remainder = round(max(0.0, $value - $covered), (int) $decimals);
        return ['value' => $value, 'covered' => $covered, 'remainder' => $remainder, 'count' => count($cards)];
    }

    /** The applied gift cards in a cart, with what each actually covers. */
    public static function cart_cards($cart = null) {
        $cart = $cart ?: (function_exists('WC') ? WC()->cart : null);
        if (!$cart) {
            return [];
        }
        $cards = [];
        foreach ($cart->get_coupons() as $code => $coupon) {
            if (!Galado_GC_Codes::is_gift_coupon($coupon)) {
                continue;
            }
            $cards[] = [
                'value'   => (float) $coupon->get_amount(),                    // shopper's currency
                'applied' => (float) $cart->get_coupon_discount_amount($code, false), // incl. any tax part
            ];
        }
        return $cards;
    }

    /**
     * Discount fees (REDIS cart rules, Club offers) cut short because nothing was left to take them
     * from: by WooCommerce (fee total below its amount) or by Galado_GC_Spending::cap_discount_fees.
     */
    public static function fees_lost($cart = null) {
        $cart = $cart ?: (function_exists('WC') ? WC()->cart : null);
        if (!$cart) {
            return 0.0;
        }
        $lost = (float) Galado_GC_Spending::$fees_dropped; // cut to nothing and removed from the cart
        foreach ($cart->get_fees() as $fee) {
            $asked = isset($fee->galado_gc_original) ? (float) $fee->galado_gc_original : (float) $fee->amount;
            $got = isset($fee->total) ? (float) $fee->total : (float) $fee->amount;
            if ($asked < 0 && $got > $asked) {
                $lost += min(-$asked, $got - $asked);
            }
        }
        return $lost;
    }

    /** The figures for the current cart (null when no gift card is applied). */
    public static function summary($cart = null) {
        $cards = self::cart_cards($cart);
        return $cards ? self::compute($cards, wc_get_price_decimals(), self::fees_lost($cart)) : null;
    }

    /** The warning for the current cart, or null when nothing will be lost. */
    public static function current($cart = null) {
        $r = self::summary($cart);
        return $r && $r['remainder'] > 0 ? $r : null;
    }

    /** The customer-facing sentence (plain text). $format turns an amount into "RM100" etc. */
    public static function message(array $r, $format = null) {
        $format = $format ?: [__CLASS__, 'money'];
        $template = 1 === (int) $r['count']
            /* translators: 1: card value, 2: order amount, 3: amount lost */
            ? __('Your gift card is worth %1$s and this order is %2$s. The %3$s left will be lost. Add more items?', 'galado-gift-cards')
            /* translators: 1: total card value, 2: order amount, 3: amount lost */
            : __('Your gift cards are worth %1$s and this order is %2$s. The %3$s left will be lost. Add more items?', 'galado-gift-cards');
        return sprintf($template, call_user_func($format, $r['value']), call_user_func($format, $r['covered']), call_user_func($format, $r['remainder']));
    }

    /** "RM100" or "RM60.50" in the shopper's currency: WooCommerce's symbol, whole amounts without decimals. */
    public static function money($amount) {
        $amount = (float) $amount;
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : wc_get_price_decimals();
        return html_entity_decode(wp_strip_all_tags(wc_price($amount, ['decimals' => $decimals])), ENT_QUOTES, 'UTF-8');
    }

    /** A row in the cart totals and the checkout review table (both refresh over AJAX). */
    public static function render_row() {
        $r = self::current();
        if (!$r) {
            return;
        }
        $text = self::message($r);
        $question = __('Add more items?', 'galado-gift-cards');
        $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
        $html = esc_html(substr($text, 0, -strlen($question))) . '<a href="' . esc_url($shop) . '">' . esc_html($question) . '</a>';
        echo '<tr class="galado-gc-remainder"><td colspan="2" style="color:#b45309;font-weight:600;">' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
    }

    public static function register_store_api() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint'        => 'cart',
            'namespace'       => self::STORE_API_NAMESPACE,
            'data_callback'   => [__CLASS__, 'store_api_data'],
            'schema_callback' => [__CLASS__, 'store_api_schema'],
            'schema_type'     => ARRAY_A,
        ]);
    }

    public static function store_api_data() {
        $d = wc_get_price_decimals();
        $r = self::summary() ?: self::compute([], $d);
        $lost = $r['remainder'] > 0;
        return [
            'remainder_lost'  => number_format($lost ? $r['remainder'] : 0, $d, '.', ''),
            'currency'        => get_woocommerce_currency(),
            'gift_card_value' => number_format($r['value'], $d, '.', ''),
            'order_amount'    => number_format($r['covered'], $d, '.', ''),
            'message'         => $lost ? self::message($r) : null,
        ];
    }

    public static function store_api_schema() {
        $money = ['type' => 'string', 'context' => ['view', 'edit'], 'readonly' => true];
        return [
            'remainder_lost'  => ['description' => 'Gift card value that this order will lose, in the cart currency.'] + $money,
            'currency'        => ['description' => 'Cart currency code.'] + $money,
            'gift_card_value' => ['description' => 'Total value of the applied gift cards, in the cart currency.'] + $money,
            'order_amount'    => ['description' => 'What the applied gift cards cover in this order.'] + $money,
            'message'         => ['description' => 'The warning to show, or null.', 'type' => ['string', 'null'], 'context' => ['view', 'edit'], 'readonly' => true],
        ];
    }
}
