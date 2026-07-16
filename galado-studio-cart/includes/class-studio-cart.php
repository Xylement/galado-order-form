<?php
/**
 * The validated add-to-cart route plus order meta display.
 *
 * POST /wp-json/galado-studio/v1/cart
 *   { artwork_token, artwork_id, model_id, style_id, name_text? }
 *
 * The artwork_token is the HMAC token studio-api returned at finalize; it
 * binds artwork_id + model_id, so cart items cannot be forged to arbitrary
 * files (spec section 8). The Studio Case variation is resolved by SKU
 * convention: variation SKU = "studio-<model_id>".
 */

if (!defined('ABSPATH')) exit;

class GSTUDIO_Cart {

    public static function init() {
        add_filter('woocommerce_is_purchasable', [__CLASS__, 'force_purchasable'], 10, 2);
        add_filter('woocommerce_variation_is_purchasable', [__CLASS__, 'force_purchasable'], 10, 2);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'cart_item_display'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'order_line_item'], 10, 3);
    }

    public static function routes() {
        register_rest_route('galado-studio/v1', '/cart', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true', // guarded by the HMAC artwork token
            'callback'            => [__CLASS__, 'add_to_cart'],
        ]);
    }

    /** WC frontend singletons are absent on REST requests; boot them (same
     * pattern as the warranty plugin's pay-order fix). */
    /**
     * The Studio Case product stays PRIVATE until launch (silent QA), but
     * WooCommerce refuses to sell private products. The HMAC artwork token
     * is the real purchase gate, so the plugin vouches for its own product;
     * privacy still hides it from the shop, search and feeds.
     */
    public static function force_purchasable($purchasable, $product) {
        if ($purchasable) return $purchasable;
        $pid = (int) gstudio_settings()['product_id'];
        if (!$pid) return $purchasable;
        if ((int) $product->get_id() === $pid || (int) $product->get_parent_id() === $pid) return true;
        return $purchasable;
    }

    private static function boot_wc() {
        if (!function_exists('WC')) return false;
        if (null === WC()->session && class_exists('WC_Session_Handler')) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }
        if (null === WC()->customer && class_exists('WC_Customer')) {
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }
        if (null === WC()->cart && class_exists('WC_Cart')) {
            WC()->cart = new WC_Cart();
            WC()->cart->get_cart_from_session();
        }
        return null !== WC()->cart;
    }

    public static function add_to_cart(WP_REST_Request $req) {
        $token      = (string) $req->get_param('artwork_token');
        $artwork_id = sanitize_text_field((string) $req->get_param('artwork_id'));
        $model_id   = sanitize_title((string) $req->get_param('model_id'));
        $style_id   = sanitize_title((string) $req->get_param('style_id'));
        $name_text  = sanitize_text_field((string) $req->get_param('name_text'));

        $claim = GSTUDIO_Token::verify($token, gstudio_secret());
        if (!$claim || ($claim['t'] ?? '') !== 'artwork'
            || ($claim['artwork_id'] ?? '') !== $artwork_id
            || ($claim['model_id'] ?? '') !== $model_id) {
            return new WP_Error('gstudio_bad_token', 'That design link is not valid.', ['status' => 403]);
        }

        $settings   = gstudio_settings();
        $product_id = (int) $settings['product_id'];
        if (!$product_id || !function_exists('wc_get_product')) {
            return new WP_Error('gstudio_not_ready', 'Studio checkout is not configured yet.', ['status' => 503]);
        }
        $product = wc_get_product($product_id);
        if (!$product || 'variable' !== $product->get_type()) {
            return new WP_Error('gstudio_not_ready', 'Studio checkout is not configured yet.', ['status' => 503]);
        }

        $variation_id = 0;
        $attributes   = [];
        foreach ($product->get_children() as $child_id) {
            $v = wc_get_product($child_id);
            if ($v && $v->get_sku() === 'studio-' . $model_id) {
                $variation_id = $child_id;
                $attributes   = $v->get_variation_attributes();
                break;
            }
        }
        if (!$variation_id) {
            return new WP_Error('gstudio_no_model', 'That phone model is not available right now.', ['status' => 404]);
        }

        if (!self::boot_wc()) {
            return new WP_Error('gstudio_no_cart', 'Cart is unavailable.', ['status' => 500]);
        }

        // A long-lived signed link so ops can pull the print master straight
        // from the order screen (mirrors how photo-case uploads appear).
        $master_sig = GSTUDIO_Token::sign(
            ['t' => 'master', 'artwork_id' => $artwork_id, 'exp' => time() + YEAR_IN_SECONDS],
            gstudio_secret()
        );
        $master_url = gstudio_api_base() . '/v1/artwork-file/' . rawurlencode($artwork_id) . '?s=' . rawurlencode($master_sig);

        $cart_key = WC()->cart->add_to_cart($product_id, 1, $variation_id, $attributes, [
            'galado_studio' => [
                'artwork_id' => $artwork_id,
                'style_id'   => $style_id,
                'model_id'   => $model_id,
                'name_text'  => $name_text,
                'master_url' => $master_url,
            ],
        ]);
        if (!$cart_key) {
            return new WP_Error('gstudio_cart_failed', 'Could not add to cart.', ['status' => 500]);
        }

        return [
            'ok'       => true,
            'cart_url' => wc_get_cart_url(),
            'checkout' => wc_get_checkout_url(),
        ];
    }

    /** Cart page: show the design context under the line item. */
    public static function cart_item_display($item_data, $cart_item) {
        if (empty($cart_item['galado_studio'])) return $item_data;
        $meta = $cart_item['galado_studio'];
        $item_data[] = ['key' => 'Studio design', 'value' => esc_html(ucwords(str_replace('-', ' ', $meta['style_id']))) . ' (designed with you in Studio)'];
        if (!empty($meta['name_text'])) {
            $item_data[] = ['key' => 'Name', 'value' => esc_html($meta['name_text'])];
        }
        return $item_data;
    }

    /** Checkout: persist onto the order line item, exactly where ops look for
     * photo-case files. Visible: style, name, artwork link. Hidden: ids. */
    public static function order_line_item($item, $cart_item_key, $values) {
        if (empty($values['galado_studio'])) return;
        $meta = $values['galado_studio'];
        $item->add_meta_data('Studio design', ucwords(str_replace('-', ' ', $meta['style_id'])) . ' (designed with you in Studio)');
        if (!empty($meta['name_text'])) {
            $item->add_meta_data('Studio name', $meta['name_text']);
        }
        $item->add_meta_data('Studio artwork', $meta['master_url']);
        $item->add_meta_data('_studio_artwork_id', $meta['artwork_id']);
        $item->add_meta_data('_studio_model', $meta['model_id']);
        $item->add_meta_data('_studio_style', $meta['style_id']);
    }
}
