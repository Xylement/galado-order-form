<?php
/**
 * Admin only: the launch-day "Create the gift card product" action (WooCommerce > Settings >
 * Products > Gift cards), on the order screen each card's code and state for support, and the
 * coupon editor keeping a card's expiry at the end of its day. Every action checks a nonce and
 * the manage_woocommerce capability.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Admin {

    const CREATE_ACTION = 'galado_gc_create_product';

    public static function init() {
        add_filter('woocommerce_get_settings_products', [__CLASS__, 'add_product_field'], 20, 2);
        add_action('woocommerce_admin_field_galado_gc_product', [__CLASS__, 'render_product_field']);
        add_action('admin_post_' . self::CREATE_ACTION, [__CLASS__, 'handle_create']);
        add_action('woocommerce_after_order_itemmeta', [__CLASS__, 'render_item_cards'], 10, 2);
        add_action('woocommerce_coupon_options_save', [__CLASS__, 'keep_end_of_day_expiry'], 20, 2);
    }

    /**
     * WooCommerce's coupon editor saves the expiry as a bare date, which it reads as 00:00. For a
     * gift card (for example when staff publish a disabled card again) put it back to 23:59:59
     * Malaysian time on the date shown, so the card still works all of its last day.
     */
    public static function keep_end_of_day_expiry($post_id, $coupon = null) {
        $coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon((int) $post_id);
        $expires = $coupon->get_date_expires();
        if (!$expires || !Galado_GC_Codes::is_gift_coupon($coupon)) {
            return;
        }
        $day = (new DateTimeImmutable('@' . $expires->getTimestamp()))->setTimezone(Galado_GC_Time::tz())->format('Y-m-d');
        $end = (new DateTimeImmutable($day . ' 23:59:59', Galado_GC_Time::tz()))->getTimestamp();
        if ($expires->getTimestamp() !== $end) {
            $coupon->set_date_expires($end);
            $coupon->save();
        }
    }

    public static function add_product_field($settings, $section) {
        if (Galado_GC_Config::SETTINGS_SECTION !== $section) {
            return $settings;
        }
        $end = array_pop($settings); // keep the sectionend last
        $settings[] = ['type' => 'galado_gc_product', 'id' => 'galado_gc_product'];
        $settings[] = $end;
        return $settings;
    }

    public static function render_product_field() {
        $id = Galado_GC_Product::find_product_id();
        echo '<tr><th scope="row">' . esc_html__('Gift card product', 'galado-gift-cards') . '</th><td>';
        if ($id) {
            printf(
                /* translators: 1: link, 2: status */
                esc_html__('%1$s (status: %2$s)', 'galado-gift-cards'),
                '<a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html(get_the_title($id)) . '</a>',
                esc_html(get_post_status($id))
            );
        } else {
            $url = wp_nonce_url(admin_url('admin-post.php?action=' . self::CREATE_ACTION), self::CREATE_ACTION);
            echo '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Create the gift card product', 'galado-gift-cards') . '</a>';
            echo '<p class="description">' . esc_html__('Creates it as Private, so only staff can see it. Publish it on launch day.', 'galado-gift-cards') . '</p>';
        }
        echo '</td></tr>';
    }

    public static function handle_create() {
        check_admin_referer(self::CREATE_ACTION);
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do this.', 'galado-gift-cards'), 403);
        }
        $id = Galado_GC_Product::create_product();
        wp_safe_redirect(get_edit_post_link($id, 'raw'));
        exit;
    }

    /** On the admin order screen only: every card of a gift line with its code and state. */
    public static function render_item_cards($item_id, $item) {
        if (!is_admin() || !current_user_can('manage_woocommerce') || !$item instanceof WC_Order_Item_Product
            || '' === (string) $item->get_meta(Galado_GC_Cart::META_VALUE)) {
            return;
        }
        $coupons = Galado_GC_Issuer::coupons_for_item($item_id);
        echo '<div class="galado-gc-admin-cards" style="margin-top:6px">';
        if (!$coupons) {
            echo '<em>' . esc_html__('No gift card code yet (issued when the order is paid).', 'galado-gift-cards') . '</em>';
        }
        foreach ($coupons as $n => $coupon_id) {
            $coupon = new WC_Coupon($coupon_id);
            if ('publish' !== $coupon->get_status()) {
                $state = __('disabled', 'galado-gift-cards');
            } elseif ($coupon->get_usage_count() > 0) {
                $state = __('used', 'galado-gift-cards');
            } elseif ($coupon->get_date_expires() && time() > $coupon->get_date_expires()->getTimestamp()) {
                $state = __('expired', 'galado-gift-cards');
            } else {
                $state = __('active', 'galado-gift-cards');
            }
            printf(
                '<div>%s <a href="%s"><code>%s</code></a> %s, %s</div>',
                esc_html(sprintf(/* translators: %d: card number */ __('Card %d:', 'galado-gift-cards'), $n)),
                esc_url(get_edit_post_link($coupon_id)),
                esc_html(Galado_GC_Codes::display($coupon->get_code())),
                esc_html(Galado_GC_Cart::rm((float) $coupon->get_amount('edit'))),
                esc_html($state)
            );
        }
        $sent = $item->get_meta(Galado_GC_Delivery::ITEM_SENT_AT);
        echo '<div>' . esc_html($sent
            /* translators: %s: date */
            ? sprintf(__('Emailed on %s', 'galado-gift-cards'), Galado_GC_Time::human_date((int) $sent))
            : __('Not emailed yet', 'galado-gift-cards')) . '</div>';
        echo '</div>';
    }
}
