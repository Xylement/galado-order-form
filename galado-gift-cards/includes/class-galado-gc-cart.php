<?php
/**
 * From the product page to the order line: validates the buyer's fields, carries them in the
 * cart item, prices the custom amount, enforces the per-card and per-order limits (at add to cart
 * and again at checkout), and writes them onto the order item.
 *
 * Cart item data: $cart_item['galado_gc'] = [value, recipient_name, recipient_email, message,
 * delivery_date], value in RM. Order item meta (hidden, underscore): _galado_gc_value,
 * _galado_gc_recipient_name, _galado_gc_recipient_email, _galado_gc_message,
 * _galado_gc_delivery_date. Customers see them through formatted meta, never the raw keys.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Cart {

    const CART_KEY = 'galado_gc';
    const META_VALUE = '_galado_gc_value';
    const META_NAME = '_galado_gc_recipient_name';
    const META_EMAIL = '_galado_gc_recipient_email';
    const META_MESSAGE = '_galado_gc_message';
    const META_DATE = '_galado_gc_delivery_date';

    public static function init() {
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 5);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'set_prices'], 20);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'cart_item_rows'], 10, 2);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'check_cart_items']);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'create_order_line_item'], 10, 3);
        add_filter('woocommerce_order_item_get_formatted_meta_data', [__CLASS__, 'formatted_meta'], 10, 2);
    }

    /**
     * Parse and validate the product page fields.
     *
     * @return array|WP_Error [value, recipient_name, recipient_email, message, delivery_date]
     */
    public static function parse_fields(array $post, $variation_id, $now = null) {
        $get = function ($key) use ($post) {
            return isset($post[$key]) && is_scalar($post[$key]) ? wp_unslash((string) $post[$key]) : '';
        };

        if (!$variation_id) {
            return new WP_Error('galado_gc', __('Please choose an amount.', 'galado-gift-cards'));
        }
        $max_card = Galado_GC_Config::max_card();
        if (Galado_GC_Product::is_custom_variation($variation_id)) {
            $raw = trim($get('galado_gc_custom_amount'));
            if (!preg_match('/^\d{1,7}$/', $raw)) {
                return new WP_Error('galado_gc', sprintf(
                    /* translators: 1: minimum, 2: maximum */
                    __('Please enter an amount in whole ringgit, from RM%1$d to %2$s.', 'galado-gift-cards'),
                    Galado_GC_Config::CUSTOM_MIN, self::rm($max_card)
                ));
            }
            $value = (float) $raw;
            if ($value < Galado_GC_Config::CUSTOM_MIN || $value > $max_card) {
                return new WP_Error('galado_gc', sprintf(
                    /* translators: 1: minimum, 2: maximum */
                    __('A custom amount can be from RM%1$d to %2$s.', 'galado-gift-cards'),
                    Galado_GC_Config::CUSTOM_MIN, self::rm($max_card)
                ));
            }
        } else {
            $value = Galado_GC_Product::preset_value($variation_id);
            if ($value <= 0) {
                return new WP_Error('galado_gc', __('Please choose an amount.', 'galado-gift-cards'));
            }
            if ($value > $max_card) {
                /* translators: %s: amount */
                return new WP_Error('galado_gc', sprintf(__('One gift card can hold up to %s.', 'galado-gift-cards'), self::rm($max_card)));
            }
        }

        $name = trim(sanitize_text_field($get('galado_gc_recipient_name')));
        if ('' === $name) {
            return new WP_Error('galado_gc', __("Please enter the recipient's name.", 'galado-gift-cards'));
        }
        if (self::length($name) > Galado_GC_Config::NAME_MAX) {
            /* translators: %d: characters */
            return new WP_Error('galado_gc', sprintf(__("The recipient's name can be up to %d characters.", 'galado-gift-cards'), Galado_GC_Config::NAME_MAX));
        }

        $email = trim(sanitize_email($get('galado_gc_recipient_email')));
        if ('' === $email || !is_email($email)) {
            return new WP_Error('galado_gc', __("Please enter the recipient's email address.", 'galado-gift-cards'));
        }

        $message = trim(sanitize_textarea_field($get('galado_gc_message')));
        if (self::length($message) > Galado_GC_Config::MESSAGE_MAX) {
            /* translators: %d: characters */
            return new WP_Error('galado_gc', sprintf(__('The message can be up to %d characters.', 'galado-gift-cards'), Galado_GC_Config::MESSAGE_MAX));
        }

        $date = trim($get('galado_gc_delivery_date'));
        if (!Galado_GC_Time::is_valid_delivery_date($date, $now)) {
            return new WP_Error('galado_gc', __('Please choose a delivery date from today up to one year ahead.', 'galado-gift-cards'));
        }

        return [
            'value'           => $value,
            'recipient_name'  => $name,
            'recipient_email' => $email,
            'message'         => $message,
            'delivery_date'   => $date,
        ];
    }

    public static function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = []) {
        if (!$passed || !Galado_GC_Product::is_gift_card_product($product_id)) {
            return $passed;
        }
        if (self::is_rest_request()) {
            // The app sells cards in a later release; until then it can only spend them.
            wc_add_notice(__('Gift cards can be bought on galado.com.my for now.', 'galado-gift-cards'), 'error');
            return false;
        }
        $fields = self::parse_fields($_POST, (int) $variation_id); // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce add to cart has no nonce
        if (is_wp_error($fields)) {
            wc_add_notice($fields->get_error_message(), 'error');
            return false;
        }
        $in_cart = self::cart_gift_total();
        $max_order = Galado_GC_Config::max_order();
        if ($in_cart + $fields['value'] * max(1, (int) $quantity) > $max_order + 0.001) {
            wc_add_notice(sprintf(
                /* translators: %s: amount */
                __('Gift cards in one order can add up to %s. Please check out what is in your cart first.', 'galado-gift-cards'),
                self::rm($max_order)
            ), 'error');
            return false;
        }
        return true;
    }

    public static function add_cart_item_data($data, $product_id, $variation_id) {
        if (!Galado_GC_Product::is_gift_card_product($product_id) || self::is_rest_request()) {
            return $data;
        }
        $fields = self::parse_fields($_POST, (int) $variation_id); // phpcs:ignore WordPress.Security.NonceVerification
        if (!is_wp_error($fields)) {
            $data[self::CART_KEY] = $fields;
        }
        return $data;
    }

    /**
     * Every gift line is priced from its own RM value. Always from the stored value, never from
     * get_price(): CURCY converts the price on read, so re-reading and setting it would compound the
     * conversion on every recalculation.
     */
    public static function set_prices($cart) {
        if (!$cart || !method_exists($cart, 'get_cart')) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            if (isset($item[self::CART_KEY]['value'], $item['data']) && $item['data'] instanceof WC_Product
                && Galado_GC_Product::is_gift_card_product($item['data'])) {
                $item['data']->set_price((float) $item[self::CART_KEY]['value']);
            }
        }
    }

    public static function cart_item_rows($rows, $cart_item) {
        if (empty($cart_item[self::CART_KEY])) {
            return $rows;
        }
        $f = $cart_item[self::CART_KEY];
        $rows[] = ['key' => __('Card value', 'galado-gift-cards'), 'value' => self::rm($f['value']), 'display' => esc_html(self::rm($f['value']))];
        $rows[] = [
            'key'     => __('To', 'galado-gift-cards'),
            'value'   => $f['recipient_name'],
            // data-clarity-mask keeps the recipient's email out of session recordings.
            'display' => esc_html($f['recipient_name']) . ' <span data-clarity-mask="True">(' . esc_html($f['recipient_email']) . ')</span>',
        ];
        $rows[] = ['key' => __('Send on', 'galado-gift-cards'), 'value' => $f['delivery_date'], 'display' => esc_html(Galado_GC_Time::human_ymd($f['delivery_date']))];
        if ('' !== $f['message']) {
            $rows[] = ['key' => __('Message', 'galado-gift-cards'), 'value' => $f['message'], 'display' => '<span data-clarity-mask="True">' . esc_html($f['message']) . '</span>'];
        }
        return $rows;
    }

    /**
     * The limits again at checkout (classic checkout runs this on submit; the Store API turns the
     * notices into a blocking error), in case the settings changed or the cart was built elsewhere.
     */
    public static function check_cart_items() {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        $total = 0.0;
        $max_card = Galado_GC_Config::max_card();
        foreach (WC()->cart->get_cart() as $item) {
            if (empty($item['data']) || !Galado_GC_Product::is_gift_card_product($item['data'])) {
                continue;
            }
            if (empty($item[self::CART_KEY]['value'])) {
                wc_add_notice(__('Please remove the gift card from your cart and add it again from its page.', 'galado-gift-cards'), 'error');
                continue;
            }
            $value = (float) $item[self::CART_KEY]['value'];
            if ($value > $max_card + 0.001) {
                /* translators: %s: amount */
                wc_add_notice(sprintf(__('One gift card can hold up to %s. Please change the gift card in your cart.', 'galado-gift-cards'), self::rm($max_card)), 'error');
            }
            $total += $value * (int) $item['quantity'];
        }
        $max_order = Galado_GC_Config::max_order();
        if ($total > $max_order + 0.001) {
            /* translators: %s: amount */
            wc_add_notice(sprintf(__('Gift cards in one order can add up to %s. Please remove a gift card.', 'galado-gift-cards'), self::rm($max_order)), 'error');
        }
    }

    public static function create_order_line_item($item, $cart_item_key, $values) {
        if (empty($values[self::CART_KEY])) {
            return;
        }
        $f = $values[self::CART_KEY];
        $item->add_meta_data(self::META_VALUE, wc_format_decimal($f['value'], 2), true);
        $item->add_meta_data(self::META_NAME, $f['recipient_name'], true);
        $item->add_meta_data(self::META_EMAIL, $f['recipient_email'], true);
        $item->add_meta_data(self::META_MESSAGE, $f['message'], true);
        $item->add_meta_data(self::META_DATE, $f['delivery_date'], true);
    }

    /** Readable rows for order emails, My Account and the admin order screen. Never a code. */
    public static function formatted_meta($formatted, $item) {
        if (!$item instanceof WC_Order_Item_Product || '' === (string) $item->get_meta(self::META_VALUE)) {
            return $formatted;
        }
        $rows = [
            'galado_gc_value' => [__('Card value', 'galado-gift-cards'), self::rm((float) $item->get_meta(self::META_VALUE))],
            'galado_gc_to'    => [__('To', 'galado-gift-cards'), $item->get_meta(self::META_NAME) . ' (' . $item->get_meta(self::META_EMAIL) . ')'],
            'galado_gc_date'  => [__('Send on', 'galado-gift-cards'), Galado_GC_Time::human_ymd($item->get_meta(self::META_DATE))],
        ];
        if ('' !== (string) $item->get_meta(self::META_MESSAGE)) {
            $rows['galado_gc_message'] = [__('Message', 'galado-gift-cards'), (string) $item->get_meta(self::META_MESSAGE)];
        }
        foreach ($rows as $key => $row) {
            $formatted[$key] = (object) [
                'key'           => $key,
                'value'         => $row[1],
                'display_key'   => $row[0],
                'display_value' => wpautop(esc_html($row[1])),
            ];
        }
        return $formatted;
    }

    /** RM value of the gift card lines in the current cart. */
    public static function cart_gift_total() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0.0;
        }
        $total = 0.0;
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item[self::CART_KEY]['value'])) {
                $total += (float) $item[self::CART_KEY]['value'] * (int) $item['quantity'];
            }
        }
        return round($total, 2);
    }

    /** @param WC_Order|int $order */
    public static function order_gift_total($order) {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            $value = $item->get_meta(self::META_VALUE);
            if ('' !== (string) $value) {
                $total += (float) $value * (int) $item->get_quantity();
            }
        }
        return round($total, 2);
    }

    /** Gift card lines of an order, keyed by item id. */
    public static function order_gift_items($order) {
        $out = [];
        foreach ($order->get_items('line_item') as $item_id => $item) {
            if ('' !== (string) $item->get_meta(self::META_VALUE)) {
                $out[$item_id] = $item;
            }
        }
        return $out;
    }

    /** "RM100" for whole ringgit, "RM100.50" otherwise. For our own messages, always ringgit. */
    public static function rm($amount) {
        $amount = (float) $amount;
        $whole = abs($amount - round($amount)) < 0.005;
        return 'RM' . number_format($amount, $whole ? 0 : 2, '.', ',');
    }

    private static function length($s) {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private static function is_rest_request() {
        return (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('WC') && method_exists(WC(), 'is_rest_api_request') && WC()->is_rest_api_request());
    }
}
