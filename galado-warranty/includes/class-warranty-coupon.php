<?php
/**
 * Generates the unique welcome coupon a customer receives on approval.
 *
 * Uses WooCommerce's native coupon system so it appears in the standard
 * Coupons admin screen and the customer can apply it at checkout exactly
 * like any other coupon.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Coupon {

    /**
     * Create a single-use, customer-restricted coupon for the given registration.
     *
     * @return string|WP_Error Coupon code on success, WP_Error on failure.
     */
    public static function create_for_registration($row) {
        if (!class_exists('WC_Coupon')) {
            return new WP_Error('gwarr_no_wc', 'WooCommerce is not active.');
        }

        $settings      = get_option('gwarr_settings', []);
        $amount        = max(0, (float) ($settings['coupon_amount'] ?? 10));
        $min_spend     = max(0, (float) ($settings['coupon_min_spend'] ?? 0));
        $expiry_days   = max(1, (int) ($settings['coupon_expiry_days'] ?? 90));
        $free_shipping = !empty($settings['coupon_free_shipping']);

        $user_email = self::user_email($row->user_id);
        if (!$user_email) {
            return new WP_Error('gwarr_no_email', 'Customer has no email on file.');
        }

        $code = self::unique_code();

        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount($amount);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions([$user_email]);
        $coupon->set_minimum_amount($min_spend);
        $coupon->set_date_expires(time() + ($expiry_days * DAY_IN_SECONDS));
        // Free shipping flag only takes effect if a WooCommerce shipping zone
        // is configured with the "Free shipping requires a valid free
        // shipping coupon" option enabled — see WC → Settings → Shipping.
        $coupon->set_free_shipping($free_shipping);
        $coupon->set_description(sprintf(
            'GALADO warranty welcome coupon — registration #%d (%s order %s)',
            (int) $row->id,
            GWARR_Marketplaces::label($row->marketplace),
            $row->order_number
        ));

        try {
            $coupon->save();
        } catch (Exception $e) {
            return new WP_Error('gwarr_coupon_save', $e->getMessage());
        }

        return $code;
    }

    /**
     * W-XXXXXX style code, collision-checked against existing coupons.
     * Old WARRANTY-XXXXXX codes from earlier versions continue to work since
     * we never disable existing coupons; only newly-issued ones use W-.
     */
    private static function unique_code() {
        for ($i = 0; $i < 8; $i++) {
            $suffix = strtoupper(wp_generate_password(6, false, false));
            $code   = 'W-' . $suffix;
            if (!wc_get_coupon_id_by_code($code)) {
                return $code;
            }
        }
        // Extremely unlikely fallback — uniqid is high-entropy enough.
        return 'W-' . strtoupper(uniqid());
    }

    private static function user_email($user_id) {
        $user = get_userdata((int) $user_id);
        return $user ? $user->user_email : '';
    }
}
