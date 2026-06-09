<?php
/**
 * Transactional emails for the warranty lifecycle.
 *
 * Layout strategy: a single email_layout() wraps every message in a
 * GALADO-branded table-based template so all messages look consistent in
 * Gmail, Apple Mail, and Outlook. All styles are inline because most
 * email clients strip <style> blocks.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Email {

    // ============================================================
    // Customer-facing
    // ============================================================

    public static function send_approved($row) {
        $user = get_userdata((int) $row->user_id);
        if (!$user) return false;

        $settings    = get_option('gwarr_settings', []);
        $months      = (int) ($settings['warranty_months'] ?? 6);
        $is_expired  = $row->warranty_ends && strtotime($row->warranty_ends) < strtotime(current_time('Y-m-d'));
        $expiry_days = (int) ($settings['coupon_expiry_days'] ?? 90);

        $subject = $is_expired
            ? 'Your warranty registration is confirmed (plus a welcome gift)'
            : "Your warranty is now {$months} months — and here's your welcome gift";

        $product_phrase = !empty($row->product_text)
            ? '<strong>' . esc_html($row->product_text) . '</strong>'
            : 'your purchase';

        $content  = self::heading('Thanks for registering, ' . esc_html(self::first_name($user)) . '.');
        $content .= self::paragraph(
            'Your warranty for ' . $product_phrase . ' (' . esc_html(GWARR_Marketplaces::label($row->marketplace))
            . ' order <code style="font-family:monospace;background:#f6f7f7;padding:2px 6px;border-radius:3px;">' . esc_html($row->order_number) . '</code>) is now on file.'
        );

        if ($is_expired) {
            $content .= self::callout('warning',
                'Your purchase date is more than ' . $months . ' months old, so the extended warranty has already lapsed. '
                . 'We still want to thank you for being a customer — your welcome gift is waiting below.'
            );
        } else {
            $content .= self::callout('success',
                '<strong>Your warranty is now covered until ' . esc_html(mysql2date('F j, Y', $row->warranty_ends)) . '.</strong>'
            );
        }

        if (!empty($row->coupon_code)) {
            $content .= self::coupon_card($row->coupon_code, $expiry_days);
        }

        $content .= self::heading('What\'s covered?', 'h3');
        $content .= self::paragraph(
            'Read the full satisfaction guarantee — what we replace, what we don\'t, and how to claim — on our support page.'
        );
        $content .= self::button('See what\'s covered →', gwarr_coverage_url());

        $content .= self::divider();
        $content .= self::paragraph(
            'You can view your warranty status and coupon any time on your '
            . '<a href="' . esc_url(wc_get_account_endpoint_url('warranties')) . '" style="color:#1a1a1a;font-weight:600;">My Warranties</a> page.'
        );
        $content .= self::signoff();

        return self::send($user->user_email, $subject, self::email_layout($content));
    }

    public static function send_rejected($row) {
        $user = get_userdata((int) $row->user_id);
        if (!$user) return false;

        $product_phrase = !empty($row->product_text)
            ? 'for <strong>' . esc_html($row->product_text) . '</strong>'
            : '(' . esc_html(GWARR_Marketplaces::label($row->marketplace)) . ' order '
              . '<code style="font-family:monospace;background:#f6f7f7;padding:2px 6px;border-radius:3px;">' . esc_html($row->order_number) . '</code>)';

        $subject = 'About your GALADO warranty registration';

        $content  = self::heading('Hi ' . esc_html(self::first_name($user)) . ',');
        $content .= self::paragraph(
            'Thanks for registering your warranty ' . $product_phrase . '.'
        );
        $content .= self::callout('warning',
            'Unfortunately we couldn\'t verify this order against our records.'
            . (!empty($row->admin_note) ? '<br><em>' . esc_html($row->admin_note) . '</em>' : '')
        );
        $content .= self::paragraph(
            'If you think this is a mistake, please reply to this email with your order screenshot or proof of purchase and we\'ll take another look.'
        );
        $content .= self::signoff();

        return self::send($user->user_email, $subject, self::email_layout($content));
    }

    // ============================================================
    // Admin-facing
    // ============================================================

    public static function send_admin_new($row) {
        $user        = get_userdata((int) $row->user_id);
        $admin_email = get_option('admin_email');
        if (!$admin_email) return false;

        $subject = '[GALADO] New warranty registration pending review';

        $rows = [
            'Customer'     => esc_html($user ? $user->display_name : 'unknown') . ' &lt;' . esc_html($user ? $user->user_email : '—') . '&gt;',
            'Marketplace'  => esc_html(GWARR_Marketplaces::label($row->marketplace)),
            'Order number' => '<code style="font-family:monospace;">' . esc_html($row->order_number) . '</code>',
        ];
        if (!empty($row->product_text)) {
            $rows['Product'] = esc_html($row->product_text);
        }

        $content  = self::heading('New warranty registration', 'h3');
        $content .= self::paragraph('A customer just submitted a warranty registration. Review and approve in the admin panel.');
        $content .= self::definition_table($rows);
        $content .= self::button('Review in admin →', admin_url('admin.php?page=galado-warranty'));

        return self::send($admin_email, $subject, self::email_layout($content));
    }

    public static function send_admin_cross_claim_alert($existing, $attempting_user_id) {
        $admin_email = get_option('admin_email');
        if (!$admin_email) return false;

        $existing_user   = get_userdata((int) $existing->user_id);
        $attempting_user = get_userdata((int) $attempting_user_id);

        $subject = '[GALADO] Warranty claim conflict — order already registered';

        $content  = self::heading('Warranty claim conflict', 'h3');
        $content .= self::paragraph(
            'Someone just tried to register a warranty for an order that\'s already on file under a different account. '
            . 'Could be a customer who used a different email and forgot — or an unauthorised claim. Worth a quick look.'
        );

        $content .= self::heading('Order in question', 'h4');
        $content .= self::definition_table([
            'Marketplace'  => esc_html(GWARR_Marketplaces::label($existing->marketplace)),
            'Order number' => '<code style="font-family:monospace;">' . esc_html($existing->order_number) . '</code>',
        ]);

        $content .= self::heading('Already registered by', 'h4');
        $content .= self::definition_table([
            'Name'        => esc_html($existing_user ? $existing_user->display_name : '(deleted user)'),
            'Email'       => esc_html($existing_user ? $existing_user->user_email : '—'),
            'Status'      => esc_html(ucfirst($existing->status)),
            'Registered'  => esc_html($existing->created_at),
        ]);

        $content .= self::heading('New claim attempt by', 'h4');
        $content .= self::definition_table([
            'Name'  => esc_html($attempting_user ? $attempting_user->display_name : '(unknown user)'),
            'Email' => esc_html($attempting_user ? $attempting_user->user_email : '—'),
        ]);

        $content .= self::button('Open Warranty admin →', admin_url('admin.php?page=galado-warranty'));

        return self::send($admin_email, $subject, self::email_layout($content));
    }

    // ============================================================
    // Layout building blocks (inline-styled for email-client safety)
    // ============================================================

    /**
     * Wrap body content in the GALADO email shell.
     */
    private static function email_layout($content) {
        $year         = gmdate('Y');
        $site         = esc_html(get_bloginfo('name'));
        $support_url  = esc_url(home_url('/support/'));
        $home_url     = esc_url(home_url('/'));

        // The wrapper table is wp_mail-friendly + works in clients that strip <style>.
        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f5f5f7;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f7;padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;line-height:1.55;">';
        $html .= '<tr><td align="center">';
        $html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #ececec;">';

        // Header — dark band with the brand wordmark.
        $html .= '<tr><td style="background:#1a1a1a;padding:24px 32px;">';
        $html .= '<div style="color:#ffffff;font-size:22px;font-weight:800;letter-spacing:.12em;">GALADO</div>';
        $html .= '</td></tr>';

        // Body.
        $html .= '<tr><td style="padding:32px;color:#1a1a1a;font-size:15px;line-height:1.6;">' . $content . '</td></tr>';

        // Footer.
        $html .= '<tr><td style="padding:20px 32px;background:#fafafa;border-top:1px solid #eee;font-size:12px;color:#888;text-align:center;">';
        $html .= '<p style="margin:0;">' . $site . ' · Premium phone accessories</p>';
        $html .= '<p style="margin:6px 0 0;">Need help? Reply to this email or visit our <a href="' . $support_url . '" style="color:#666;">support page</a>.</p>';
        $html .= '<p style="margin:10px 0 0;color:#bbb;">© ' . esc_html($year) . ' GALADO · <a href="' . $home_url . '" style="color:#bbb;">galado.com.my</a></p>';
        $html .= '</td></tr>';

        $html .= '</table>';
        $html .= '</td></tr></table>';
        $html .= '</body></html>';

        return $html;
    }

    private static function heading($text, $tag = 'h2') {
        $sizes = ['h2' => '20px', 'h3' => '16px', 'h4' => '14px'];
        $size  = $sizes[$tag] ?? '18px';
        $top   = $tag === 'h2' ? '0' : '24px';
        return '<' . $tag . ' style="margin:' . $top . ' 0 8px;font-size:' . $size . ';font-weight:700;color:#1a1a1a;">' . $text . '</' . $tag . '>';
    }

    private static function paragraph($html) {
        return '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#1a1a1a;">' . $html . '</p>';
    }

    private static function callout($variant, $html) {
        $palettes = [
            'success' => ['bg' => '#e6f4ea', 'border' => '#b6d8c2', 'color' => '#1b5e20'],
            'warning' => ['bg' => '#fff8e7', 'border' => '#d6b656', 'color' => '#876d2e'],
        ];
        $p = $palettes[$variant] ?? $palettes['success'];
        return '<div style="background:' . $p['bg'] . ';border:1px solid ' . $p['border'] . ';border-radius:10px;padding:14px 16px;margin:14px 0;color:' . $p['color'] . ';font-size:14px;line-height:1.5;">' . $html . '</div>';
    }

    /**
     * The branded coupon block — highlighted code + dynamic perk description.
     */
    private static function coupon_card($code, $expiry_days) {
        $perks = esc_html(gwarr_perk_description()); // e.g. "10% off + free shipping"
        return ''
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">'
            . '<tr><td style="background:#fff8e7;border:2px dashed #d6b656;border-radius:14px;padding:24px;text-align:center;">'
            . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#876d2e;font-weight:700;margin-bottom:6px;">Your Welcome Gift</div>'
            . '<div style="font-family:monospace;font-size:28px;font-weight:800;letter-spacing:.04em;color:#1a1a1a;background:#ffffff;display:inline-block;padding:10px 18px;border-radius:8px;border:1px solid #ececec;margin:6px 0;">' . esc_html($code) . '</div>'
            . '<p style="margin:10px 0 4px;font-size:15px;font-weight:600;color:#1a1a1a;">' . $perks . '</p>'
            . '<p style="margin:0;font-size:12px;color:#876d2e;">on your next direct order at galado.com.my · single use · valid ' . (int) $expiry_days . ' days</p>'
            . '</td></tr>'
            . '</table>';
    }

    private static function button($label, $url) {
        return '<p style="margin:18px 0;"><a href="' . esc_url($url) . '" style="display:inline-block;background:#1a1a1a;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-size:14px;font-weight:600;">' . $label . '</a></p>';
    }

    private static function definition_table($pairs) {
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 18px;font-size:14px;line-height:1.6;">';
        foreach ($pairs as $label => $value) {
            $html .= '<tr>';
            $html .= '<td style="padding:4px 16px 4px 0;color:#666;vertical-align:top;">' . esc_html($label) . '</td>';
            $html .= '<td style="padding:4px 0;color:#1a1a1a;">' . $value . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    }

    private static function divider() {
        return '<div style="height:1px;background:#ececec;margin:24px 0;"></div>';
    }

    private static function signoff() {
        return self::paragraph('— The GALADO Team');
    }

    private static function first_name($user) {
        if (!empty($user->first_name)) return $user->first_name;
        if (!empty($user->display_name)) {
            $parts = preg_split('/\s+/', trim($user->display_name));
            if (!empty($parts[0])) return $parts[0];
        }
        return $user->user_login;
    }

    // ============================================================
    // Send mechanics
    // ============================================================

    private static function send($to, $subject, $body_html) {
        $settings   = get_option('gwarr_settings', []);
        $from_name  = $settings['from_name']  ?: get_bloginfo('name');
        $from_email = $settings['from_email'] ?: get_option('admin_email');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        ];

        return wp_mail($to, $subject, $body_html, $headers);
    }
}

/**
 * Functional aliases used elsewhere in the plugin.
 */
function gwarr_send_admin_new_registration_email($row) {
    return GWARR_Email::send_admin_new($row);
}

function gwarr_send_admin_cross_claim_alert($existing, $attempting_user_id) {
    return GWARR_Email::send_admin_cross_claim_alert($existing, $attempting_user_id);
}
