<?php
/**
 * Transactional emails for warranty registration lifecycle.
 *
 * Plain wp_mail() with a minimal HTML wrapper — these are functional notifications,
 * not marketing. Klaviyo handles marketing flows separately.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Email {

    /** Notify customer their registration was approved + deliver coupon. */
    public static function send_approved($row) {
        $user = get_userdata((int) $row->user_id);
        if (!$user) return false;

        $settings  = get_option('gwarr_settings', []);
        $months    = (int) ($settings['warranty_months'] ?? 6);
        $is_expired = $row->warranty_ends && strtotime($row->warranty_ends) < strtotime(current_time('Y-m-d'));

        $subject = $is_expired
            ? 'Your warranty registration is confirmed (plus a welcome coupon)'
            : "Your warranty is extended to {$months} months — and here's your welcome gift";

        $product_phrase = !empty($row->product_text)
            ? '<strong>' . esc_html($row->product_text) . '</strong> '
            : 'your purchase ';

        $body  = '<p>Hi ' . esc_html($user->display_name ?: $user->user_login) . ',</p>';
        $body .= '<p>Thanks for registering ' . $product_phrase . '('
              . esc_html(GWARR_Marketplaces::label($row->marketplace)) . ' order '
              . esc_html($row->order_number) . ').</p>';

        if ($is_expired) {
            $body .= '<p>Your purchase date is more than ' . $months . ' months old, so the extended warranty period has already lapsed — but we still want to thank you for being a customer.</p>';
        } else {
            $body .= '<p><strong>Your warranty is covered until ' . esc_html(mysql2date('F j, Y', $row->warranty_ends)) . '.</strong></p>';
        }

        if (!empty($row->coupon_code)) {
            $expiry_days = (int) ($settings['coupon_expiry_days'] ?? 90);
            $amount      = (int) ($settings['coupon_amount'] ?? 10);
            $body .= '<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:20px 0;">'
                   . '<p style="margin:0 0 4px;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.05em;">Your welcome coupon</p>'
                   . '<p style="margin:0 0 8px;font-size:22px;font-weight:700;font-family:monospace;">' . esc_html($row->coupon_code) . '</p>'
                   . '<p style="margin:0;font-size:13px;color:#444;">' . esc_html($amount) . '% off your next direct purchase on <a href="' . esc_url(home_url('/')) . '">galado.com.my</a>. Single use, valid for ' . esc_html($expiry_days) . ' days.</p>'
                   . '</div>';
        }

        $body .= '<p>You can view your warranty status any time on your <a href="' . esc_url(wc_get_account_endpoint_url('warranties')) . '">My Warranties</a> page.</p>';
        $body .= '<p>— The GALADO Team</p>';

        return self::send($user->user_email, $subject, $body);
    }

    /** Notify customer their registration was rejected, with reason. */
    public static function send_rejected($row) {
        $user = get_userdata((int) $row->user_id);
        if (!$user) return false;

        $subject = 'About your warranty registration';

        $product_phrase = !empty($row->product_text)
            ? 'for <strong>' . esc_html($row->product_text) . '</strong>'
            : '(' . esc_html(GWARR_Marketplaces::label($row->marketplace)) . ' order ' . esc_html($row->order_number) . ')';

        $body  = '<p>Hi ' . esc_html($user->display_name ?: $user->user_login) . ',</p>';
        $body .= '<p>Thanks for registering your warranty ' . $product_phrase . '.</p>';
        $body .= '<p>Unfortunately we couldn\'t verify this order against our records.';
        if (!empty($row->admin_note)) {
            $body .= '<br><em>' . esc_html($row->admin_note) . '</em>';
        }
        $body .= '</p>';
        $body .= '<p>If you think this is a mistake, please reply to this email with your order screenshot or proof of purchase and we\'ll take another look.</p>';
        $body .= '<p>— The GALADO Team</p>';

        return self::send($user->user_email, $subject, $body);
    }

    /** Notify admins a new pending registration arrived. */
    public static function send_admin_new($row) {
        $user        = get_userdata((int) $row->user_id);
        $admin_email = get_option('admin_email');
        if (!$admin_email) return false;

        $subject = '[GALADO] New warranty registration pending review';

        $body  = '<p>A new warranty registration is waiting for review.</p>';
        $body .= '<ul>';
        $body .= '<li><strong>Customer:</strong> ' . esc_html($user ? $user->display_name : 'unknown') . ' (' . esc_html($user ? $user->user_email : '—') . ')</li>';
        $body .= '<li><strong>Marketplace:</strong> ' . esc_html(GWARR_Marketplaces::label($row->marketplace)) . '</li>';
        $body .= '<li><strong>Order number:</strong> ' . esc_html($row->order_number) . '</li>';
        if (!empty($row->product_text)) {
            $body .= '<li><strong>Product:</strong> ' . esc_html($row->product_text) . '</li>';
        }
        $body .= '</ul>';
        $body .= '<p><a href="' . esc_url(admin_url('admin.php?page=galado-warranty&view=' . (int) $row->id)) . '">Review in admin →</a></p>';

        return self::send($admin_email, $subject, $body);
    }

    /**
     * Alert admin when a customer tries to register an order that's already
     * claimed by a *different* account. Could be a customer with two email
     * accounts who forgot — or a fraud attempt. Either way, you need to see it.
     */
    public static function send_admin_cross_claim_alert($existing, $attempting_user_id) {
        $admin_email = get_option('admin_email');
        if (!$admin_email) return false;

        $existing_user   = get_userdata((int) $existing->user_id);
        $attempting_user = get_userdata((int) $attempting_user_id);

        $subject = '[GALADO] Warranty claim conflict — order already registered by another account';

        $body  = '<p>Someone just tried to register a warranty for an order that\'s already on file under a different account.</p>';
        $body .= '<p>This could be the same customer who used a different email address (and forgot which one they registered with), or it could be an unauthorised claim. Worth a quick look.</p>';

        $body .= '<h3 style="margin-bottom:4px;">Order</h3><ul style="margin-top:0;">';
        $body .= '<li><strong>Marketplace:</strong> ' . esc_html(GWARR_Marketplaces::label($existing->marketplace)) . '</li>';
        $body .= '<li><strong>Order number:</strong> <code>' . esc_html($existing->order_number) . '</code></li>';
        $body .= '</ul>';

        $body .= '<h3 style="margin-bottom:4px;">Already registered by</h3><ul style="margin-top:0;">';
        $body .= '<li><strong>' . esc_html($existing_user ? $existing_user->display_name : '(deleted user)') . '</strong> &lt;' . esc_html($existing_user ? $existing_user->user_email : '—') . '&gt;</li>';
        $body .= '<li><strong>Status:</strong> ' . esc_html(ucfirst($existing->status)) . '</li>';
        $body .= '<li><strong>Registered on:</strong> ' . esc_html($existing->created_at) . '</li>';
        $body .= '</ul>';

        $body .= '<h3 style="margin-bottom:4px;">New claim attempt by</h3><ul style="margin-top:0;">';
        $body .= '<li><strong>' . esc_html($attempting_user ? $attempting_user->display_name : '(unknown user)') . '</strong> &lt;' . esc_html($attempting_user ? $attempting_user->user_email : '—') . '&gt;</li>';
        $body .= '</ul>';

        $body .= '<p style="margin-top:24px;"><a href="' . esc_url(admin_url('admin.php?page=galado-warranty')) . '">Open Warranty admin →</a></p>';

        return self::send($admin_email, $subject, $body);
    }

    /** Shared mail helper — wraps the HTML and sets sane From headers. */
    private static function send($to, $subject, $body_html) {
        $settings   = get_option('gwarr_settings', []);
        $from_name  = $settings['from_name']  ?: get_bloginfo('name');
        $from_email = $settings['from_email'] ?: get_option('admin_email');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        ];

        $template = '<div style="font-family:Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;color:#1a1a1a;line-height:1.5;">'
                  . $body_html
                  . '</div>';

        return wp_mail($to, $subject, $template, $headers);
    }
}

/**
 * Functional aliases used by the registration form.
 */
function gwarr_send_admin_new_registration_email($row) {
    return GWARR_Email::send_admin_new($row);
}

function gwarr_send_admin_cross_claim_alert($existing, $attempting_user_id) {
    return GWARR_Email::send_admin_cross_claim_alert($existing, $attempting_user_id);
}
