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

    /** True only while our token-checked REST route is adding to the cart.
     * The Studio Case must never enter a cart without a verified design, so
     * native ?add-to-cart= requests for it are rejected outright. */
    private static $vouching = false;

    public static function init() {
        add_filter('woocommerce_is_purchasable', [__CLASS__, 'force_purchasable'], 10, 2);
        add_filter('woocommerce_variation_is_purchasable', [__CLASS__, 'force_purchasable'], 10, 2);
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'block_native_add'], 10, 4);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'cart_item_display'], 10, 2);
        add_filter('woocommerce_cart_item_thumbnail', [__CLASS__, 'cart_item_thumbnail'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'order_line_item'], 10, 3);
        add_action('woocommerce_after_order_itemmeta', [__CLASS__, 'admin_item_link'], 10, 2);
        add_filter('woocommerce_order_item_get_formatted_meta_data', [__CLASS__, 'hide_raw_master_meta'], 10, 1);
        add_filter('woocommerce_order_item_thumbnail', [__CLASS__, 'order_item_thumbnail'], 10, 2);
        add_action('woocommerce_email_after_order_table', [__CLASS__, 'admin_email_links'], 10, 4);
    }

    /** Admin order email: a compact download link per Studio item (short
     * anchor text so the 300-char signed href cannot break the layout).
     * Customer emails never carry it. */
    public static function admin_email_links($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if (!$sent_to_admin || !is_callable([$order, 'get_items'])) return;
        foreach ($order->get_items() as $item) {
            $url = self::master_url_for($item);
            if (!$url) continue;
            if ($plain_text) {
                echo "\nStudio print file (" . $item->get_name() . "): " . esc_url_raw($url) . "\n";
            } else {
                echo '<p style="margin:12px 0 0"><strong>Studio print file:</strong> '
                   . '<a href="' . esc_url($url) . '">Download PNG</a> (' . esc_html($item->get_name()) . ')</p>';
            }
        }
    }

    public static function routes() {
        register_rest_route('galado-studio/v1', '/cart', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true', // guarded by the HMAC artwork token
            'callback'            => [__CLASS__, 'add_to_cart'],
        ]);
        register_rest_route('galado-studio/v1', '/resend-emails', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true', // guarded by a signed ops token
            'callback'            => [__CLASS__, 'resend_emails'],
        ]);
    }

    /** Ops QA helper: re-fire the admin New Order and customer Processing
     * emails for one order. Needs a short-lived signed token (t=resend)
     * minted with the shared studio secret; no WP login involved. */
    public static function resend_emails(WP_REST_Request $req) {
        $token    = (string) $req->get_param('token');
        $order_id = (int) $req->get_param('order_id');
        $claim = GSTUDIO_Token::verify($token, gstudio_secret());
        if (!$claim || ($claim['t'] ?? '') !== 'resend' || (int) ($claim['order_id'] ?? 0) !== $order_id) {
            return new WP_Error('gstudio_bad_token', 'Not allowed.', ['status' => 403]);
        }
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return new WP_Error('gstudio_no_order', 'Order not found.', ['status' => 404]);
        }
        $emails = WC()->mailer()->get_emails();
        $sent = [];
        foreach (['WC_Email_New_Order', 'WC_Email_Customer_Processing_Order'] as $cls) {
            if (isset($emails[$cls])) {
                $emails[$cls]->trigger($order_id);
                $sent[] = $cls;
            }
        }
        return ['ok' => true, 'sent' => $sent];
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

    /** Reject any add of the Studio product that is not our vouched route:
     * without an artwork there is nothing to print. */
    public static function block_native_add($passed, $product_id, $quantity, $variation_id = 0) {
        if (self::$vouching || !$passed) return $passed;
        $pid = (int) gstudio_settings()['product_id'];
        if (!$pid) return $passed;
        $hit = ((int) $product_id === $pid);
        if (!$hit && $variation_id) {
            $v = wc_get_product($variation_id);
            $hit = $v && (int) $v->get_parent_id() === $pid;
        }
        if (!$hit) {
            $p = wc_get_product($product_id);
            $hit = $p && (int) $p->get_parent_id() === $pid;
        }
        if ($hit) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice('Studio cases are created in the Studio designer.', 'error');
            }
            return false;
        }
        return $passed;
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

        self::$vouching = true;
        $cart_key = WC()->cart->add_to_cart($product_id, 1, $variation_id, $attributes, [
            'galado_studio' => [
                'artwork_id' => $artwork_id,
                'style_id'   => $style_id,
                'model_id'   => $model_id,
                'name_text'  => $name_text,
                'master_url' => $master_url,
                // Small inline render of the actual design for cart/mini-cart
                // thumbnails (same signed link, api serves a cached resize).
                'preview_url' => $master_url . '&w=480',
            ],
        ]);
        self::$vouching = false;
        if (!$cart_key) {
            return new WP_Error('gstudio_cart_failed', 'Could not add to cart.', ['status' => 500]);
        }

        return [
            'ok'       => true,
            'cart_url' => wc_get_cart_url(),
            'checkout' => wc_get_checkout_url(),
        ];
    }

    /** Cart + mini-cart: the line item image is the customer's own design,
     * not the generic product photo. Older cart sessions without a stored
     * preview_url fall back to the master link with the resize hint. */
    public static function cart_item_thumbnail($thumbnail, $cart_item) {
        if (empty($cart_item['galado_studio'])) return $thumbnail;
        $meta = $cart_item['galado_studio'];
        $url  = !empty($meta['preview_url']) ? $meta['preview_url']
              : (!empty($meta['master_url']) ? $meta['master_url'] . '&w=480' : '');
        if (!$url) return $thumbnail;
        return '<img src="' . esc_url($url) . '" alt="Your Studio design"'
             . ' style="width:100%;max-width:96px;height:auto;border-radius:10px;background:#F5F5F3;padding:6px;box-sizing:border-box;" />';
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

    /** Signed master link for an order item; tolerates legacy orders that
     * stored it as visible "Studio artwork" meta (pre 0.7.3). */
    private static function master_url_for($item) {
        if (!is_callable([$item, 'get_meta'])) return '';
        $url = (string) $item->get_meta('_studio_master_url');
        if (!$url) $url = (string) $item->get_meta('Studio artwork');
        return $url;
    }

    /** Admin order screen: a clean download button instead of a raw token URL
     * (the 300-char signed link broke email and table layouts, 17 Jul). */
    public static function admin_item_link($item_id, $item) {
        $url = self::master_url_for($item);
        if (!$url) return;
        echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url($url) . '">Download print file (PNG)</a></p>';
    }

    /** Hide the raw signed URL from every rendered meta list (emails, order
     * screens, My Account). Storage is untouched; the admin button and the
     * hidden meta carry the link. */
    public static function hide_raw_master_meta($formatted_meta) {
        if (!is_array($formatted_meta)) return $formatted_meta;
        foreach ($formatted_meta as $k => $m) {
            if (isset($m->display_key) && 'Studio artwork' === $m->display_key) unset($formatted_meta[$k]);
        }
        return $formatted_meta;
    }

    /** Order emails and order pages show the customer's design, not the bare
     * product placeholder (mirrors the cart thumbnail). */
    public static function order_item_thumbnail($image, $item) {
        $url = self::master_url_for($item);
        if (!$url) return $image;
        return '<img src="' . esc_url($url . '&w=480') . '" alt="Your Studio design"'
             . ' style="width:96px;max-width:96px;height:auto;border-radius:10px;background:#F5F5F3;padding:6px;box-sizing:border-box;" />';
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
        $item->add_meta_data('_studio_master_url', $meta['master_url']);
        $item->add_meta_data('_studio_artwork_id', $meta['artwork_id']);
        $item->add_meta_data('_studio_model', $meta['model_id']);
        $item->add_meta_data('_studio_style', $meta['style_id']);
    }
}
