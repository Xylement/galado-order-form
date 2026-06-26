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
        // Tier-aware coverage length — Black Club members get 12, others 6.
        $months      = function_exists('gwarr_months_for_row')
            ? gwarr_months_for_row($row)
            : max(1, (int) ($settings['warranty_months'] ?? 6));
        $is_expired  = $row->warranty_ends && strtotime($row->warranty_ends) < strtotime(current_time('Y-m-d'));

        // Expired registrations (purchase older than the coverage window) keep
        // the plain layout with its honest "already lapsed" warning — the
        // branded happy-path template has no expired variant.
        if ($is_expired) {
            return self::send_approved_expired($user, $row, $months, $settings);
        }

        $is_black = $months >= 12;
        $subject  = $is_black
            ? "Your warranty is now 12 months — and here's your welcome gift ✦"
            : 'Your GALADO warranty is registered ✦';

        $body = self::render_branded_approved($user, $row, $months, $is_black, $settings);

        return self::send($user->user_email, $subject, $body);
    }

    /**
     * Fallback for registrations whose coverage window has already lapsed —
     * uses the generic layout so we can show the honest "expired" warning.
     */
    private static function send_approved_expired($user, $row, $months, $settings) {
        $expiry_days       = (int) ($settings['coupon_expiry_days'] ?? 90);
        $marketplace_label = esc_html(GWARR_Marketplaces::label($row->marketplace));
        $order_code        = '<code style="font-family:monospace;background:#f6f7f7;padding:2px 6px;border-radius:3px;">' . esc_html($row->order_number) . '</code>';

        $content  = self::heading('Thanks for registering, ' . esc_html(self::first_name($user)) . '.');
        if (!empty($row->product_text)) {
            $content .= self::paragraph('We\'ve registered the following from your ' . $marketplace_label . ' order ' . $order_code . ':');
            $content .= '<div style="background:#f6f7f7;border-radius:10px;padding:14px 18px;margin:0 0 18px;">'
                      . gwarr_format_product_email($row->product_text) . '</div>';
        } else {
            $content .= self::paragraph('We\'ve registered your ' . $marketplace_label . ' order ' . $order_code . '.');
        }
        $content .= self::callout('warning',
            'Your purchase date is more than ' . (int) $months . ' months old, so the extended warranty has already lapsed. '
            . 'We still want to thank you for being a customer — your welcome gift is waiting below.'
        );
        if (!empty($row->coupon_code)) {
            $content .= self::coupon_card($row->coupon_code, $expiry_days);
        }
        $content .= self::button('See what\'s covered →', gwarr_coverage_url());
        $content .= self::signoff();

        return self::send($user->user_email, 'Your warranty registration is confirmed (plus a welcome gift)', self::email_layout($content));
    }

    /**
     * The GALADO-brand warranty-registration email (cream bg, Baloo 2 / Nunito,
     * coral CTA). Mirrors WARRANTY-EMAIL-SPEC.md / warranty-email.html — coupon
     * block and Black-tier perk badge are conditional. Primary CTA points to
     * the Club to pull the marketplace buyer in; "see what's covered" is a
     * quiet secondary link.
     */
    private static function render_branded_approved($user, $row, $months, $is_black, $settings) {
        // ---- Resolve + escape every value ----
        $name        = esc_html(self::first_name($user) ?: 'there');
        $marketplace = esc_html(GWARR_Marketplaces::label($row->marketplace));
        $order       = esc_html($row->order_number);
        $until       = esc_html(mysql2date('F j, Y', $row->warranty_ends));
        $months      = (int) $months;
        $product_html = !empty($row->product_text) ? nl2br(esc_html($row->product_text)) : '';

        $club_base = defined('GALADO_CLUB_URL') ? rtrim(GALADO_CLUB_URL, '/') : 'https://club.galado.com.my';
        $club_url   = esc_url($club_base . '/?utm_source=warranty_email');
        $support_url = esc_url(function_exists('gwarr_coverage_url') ? gwarr_coverage_url() : home_url('/'));
        $shop_url    = esc_url(home_url('/'));

        $show_coupon = !empty($row->coupon_code);
        $coupon_code  = esc_html((string) $row->coupon_code);
        $coupon_offer = esc_html(function_exists('gwarr_perk_description') ? gwarr_perk_description() : '');
        $expiry_days  = (int) ($settings['coupon_expiry_days'] ?? 90);
        $coupon_terms = esc_html('on your next direct order at galado.com.my · single use · valid ' . $expiry_days . ' days');

        // ---- Header ----
        $h  = '<!DOCTYPE html><html lang="en"><head>';
        $h .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $h .= '<meta name="color-scheme" content="light only"><title>GALADO Warranty</title>';
        $h .= '<style>';
        $h .= "@import url('https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Nunito:wght@400;600;700;800&display=swap');";
        $h .= 'body{margin:0;padding:0;background:#fff6ee;}';
        $h .= '@media (max-width:520px){.gc-card{padding:30px 22px !important;}}';
        $h .= '</style></head>';
        $h .= '<body style="margin:0;padding:0;background-color:#fff6ee;">';
        $h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#fff6ee;">Your GALADO warranty is registered — coverage details and a welcome gift inside.</div>';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff6ee;">';
        $h .= '<tr><td align="center" style="padding:36px 16px;">';
        $h .= '<table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:480px;max-width:100%;">';

        // GALADO wordmark + WARRANTY pill
        $h .= '<tr><td align="center" style="padding-bottom:22px;">';
        $h .= '<span style="font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:24px;color:#46302e;letter-spacing:0.02em;vertical-align:middle;">GALADO</span>';
        $h .= '<span style="display:inline-block;font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:12px;letter-spacing:0.08em;color:#ffffff;background-color:#f25d6f;background-image:linear-gradient(135deg,#ff7e8a,#f25d6f);padding:4px 10px;border-radius:8px;margin-left:7px;vertical-align:middle;">WARRANTY</span>';
        $h .= '</td></tr>';

        // Card
        $h .= '<tr><td class="gc-card" style="background-color:#ffffff;border:1px solid #f3ddd2;border-radius:28px;padding:38px 34px;text-align:center;box-shadow:0 6px 18px rgba(70,48,46,0.08);">';

        // Hero shield
        $h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 18px;"><tr>';
        $h .= '<td align="center" valign="middle" width="64" height="64" style="width:64px;height:64px;border-radius:50%;background-color:#f5b83d;background-image:radial-gradient(circle at 35% 30%,#fff3cd,#f5b83d 70%);border:3px solid #d99a23;font-size:30px;">&#128737;&#65039;</td>';
        $h .= '</tr></table>';

        $h .= '<h1 style="margin:0 0 12px;font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:24px;line-height:1.15;color:#46302e;">Thanks for registering, ' . $name . '!</h1>';

        if ($product_html !== '') {
            $h .= '<p style="margin:0 0 22px;font-family:\'Nunito\',sans-serif;font-weight:600;font-size:15px;line-height:1.55;color:#8a6f6c;">We\'ve registered the following from your ' . $marketplace . ' order <strong style="color:#46302e;">' . $order . '</strong>:</p>';
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 14px;"><tr>';
            $h .= '<td style="background-color:#fff6ee;border:1px solid #f3ddd2;border-radius:14px;padding:16px 18px;text-align:left;font-family:\'Baloo 2\',sans-serif;font-weight:700;font-size:16px;color:#46302e;">&#128241;&nbsp; ' . $product_html . '</td>';
            $h .= '</tr></table>';
        } else {
            $h .= '<p style="margin:0 0 16px;font-family:\'Nunito\',sans-serif;font-weight:600;font-size:15px;line-height:1.55;color:#8a6f6c;">We\'ve registered your ' . $marketplace . ' order <strong style="color:#46302e;">' . $order . '</strong>.</p>';
        }

        // Coverage callout
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;"><tr>';
        $h .= '<td style="background-color:#eef7f0;border:1px solid #cfe8d6;border-radius:14px;padding:14px 18px;text-align:center;font-family:\'Nunito\',sans-serif;font-weight:800;font-size:15px;color:#3fa66f;">&#128737;&#65039; <strong>' . $months . ' months</strong> of coverage &mdash; until <span style="white-space:nowrap;">' . $until . '</span>.</td>';
        $h .= '</tr></table>';

        // Black-tier perk badge (only for Black members)
        if ($is_black) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;"><tr>';
            $h .= '<td style="background-color:#211f2a;background-image:linear-gradient(135deg,#2b2735,#1a1822);border:1px solid #d9b44a;border-radius:14px;padding:13px 18px;text-align:center;font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:13px;letter-spacing:0.05em;color:#e8c971;">&#128081; BLACK MEMBER PERK &mdash; 12 months, double the standard 6</td>';
            $h .= '</tr></table>';
        } else {
            // keep spacing consistent below the coverage callout
            $h .= '<div style="height:10px;line-height:10px;font-size:0;">&nbsp;</div>';
        }

        // Welcome-gift coupon (optional)
        if ($show_coupon) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;"><tr>';
            $h .= '<td style="background-color:#fffaf0;border:2px dashed #e3b341;border-radius:18px;padding:22px 18px;text-align:center;">';
            $h .= '<div style="font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:12px;letter-spacing:0.12em;color:#f25d6f;margin-bottom:12px;">YOUR WELCOME GIFT</div>';
            $h .= '<div style="display:inline-block;font-family:\'Courier New\',monospace;font-weight:700;font-size:26px;letter-spacing:0.08em;color:#46302e;background-color:#fff6ee;border:1px solid #f3ddd2;border-radius:12px;padding:12px 24px;margin-bottom:14px;">' . $coupon_code . '</div>';
            $h .= '<div style="font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:17px;color:#46302e;margin-bottom:4px;">' . $coupon_offer . '</div>';
            $h .= '<div style="font-family:\'Nunito\',sans-serif;font-weight:600;font-size:12px;color:#b8a6a1;">' . $coupon_terms . '</div>';
            $h .= '</td></tr></table>';
        }

        // Club nudge
        $h .= '<p style="margin:0 0 20px;font-family:\'Nunito\',sans-serif;font-weight:600;font-size:14px;line-height:1.55;color:#8a6f6c;">While you\'re here &mdash; your <strong style="color:#46302e;">GALADO Club</strong> locker has G-Coins, badges and a Buddy to dress up, growing with every order. &#10022;</p>';

        // Primary CTA -> Club
        $h .= '<a href="' . $club_url . '" target="_blank" rel="noopener" style="display:inline-block;font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:16px;line-height:1;color:#ffffff;text-decoration:none;padding:15px 32px;border-radius:999px;background-color:#f25d6f;background-image:linear-gradient(135deg,#ff7e8a,#f25d6f);box-shadow:0 6px 16px rgba(242,93,111,0.35);">Open GALADO Club &rarr;</a>';

        // Secondary quiet link -> what's covered
        $h .= '<p style="margin:18px 0 0;font-family:\'Nunito\',sans-serif;font-weight:600;font-size:13px;color:#8a6f6c;">Need your coverage details? <a href="' . $support_url . '" style="color:#f25d6f;text-decoration:underline;font-weight:700;">See what\'s covered &rarr;</a></p>';

        $h .= '</td></tr>';

        // Footer
        $h .= '<tr><td style="padding:18px 18px 0;text-align:center;font-family:\'Nunito\',sans-serif;font-size:11px;color:#b8a6a1;">GALADO &middot; <a href="' . $shop_url . '" style="color:#b8a6a1;text-decoration:underline;">galado.com.my</a></td></tr>';

        $h .= '</table></td></tr></table></body></html>';

        return $h;
    }

    public static function send_rejected($row) {
        $user = get_userdata((int) $row->user_id);
        if (!$user) return false;

        $marketplace_label = esc_html(GWARR_Marketplaces::label($row->marketplace));
        $order_code        = '<code style="font-family:monospace;background:#f6f7f7;padding:2px 6px;border-radius:3px;">' . esc_html($row->order_number) . '</code>';

        $subject = 'About your GALADO warranty registration';

        $content  = self::heading('Hi ' . esc_html(self::first_name($user)) . ',');
        $content .= self::paragraph(
            'Thanks for registering your warranty for ' . $marketplace_label . ' order ' . $order_code . '.'
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
            // Multi-line product strings render correctly inside the definition table.
            $rows['Product'] = gwarr_format_product_email($row->product_text);
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
    // Warranty claims (Phase 2)
    // ============================================================

    public static function send_claim_received($claim) {
        $user     = get_userdata((int) $claim->user_id);
        $warranty = class_exists('GWARR_DB') ? GWARR_DB::find((int) $claim->warranty_id) : null;
        if (!$user) return false;
        $m = self::render_claim_email('received', self::claim_ctx($user, $warranty, $claim, ''));
        return self::send($user->user_email, $m['subject'], $m['html']);
    }

    public static function send_claim_approved($claim) {
        $user     = get_userdata((int) $claim->user_id);
        $warranty = class_exists('GWARR_DB') ? GWARR_DB::find((int) $claim->warranty_id) : null;
        if (!$user) return false;
        $note = !empty($claim->admin_note) ? esc_html($claim->admin_note) : '';
        $ctx  = self::claim_ctx($user, $warranty, $claim, $note);

        // Replacement-shipping fee → pay-online button (WooCommerce order).
        $fee = isset($claim->shipping_fee) ? (float) $claim->shipping_fee : 0;
        if ($fee > 0) {
            $ctx['shipping_fee'] = $fee;
            if (!empty($claim->shipping_order_id) && function_exists('wc_get_order')) {
                $order = wc_get_order((int) $claim->shipping_order_id);
                if ($order) {
                    $ctx['shipping_paid'] = $order->is_paid();
                    if ($order->needs_payment()) {
                        $ctx['pay_url'] = $order->get_checkout_payment_url();
                    }
                }
            }
        }

        $m = self::render_claim_email('approved', $ctx);
        return self::send($user->user_email, $m['subject'], $m['html']);
    }

    public static function send_claim_rejected($claim) {
        $user     = get_userdata((int) $claim->user_id);
        $warranty = class_exists('GWARR_DB') ? GWARR_DB::find((int) $claim->warranty_id) : null;
        if (!$user) return false;
        $note = !empty($claim->admin_note) ? esc_html($claim->admin_note) : '';
        $m = self::render_claim_email('rejected', self::claim_ctx($user, $warranty, $claim, $note));
        return self::send($user->user_email, $m['subject'], $m['html']);
    }

    /**
     * Alert the warranty inbox that a customer submitted a new claim. Recipient
     * comes from settings (claim_notify_email), defaulting to warranty@galado.com.my.
     */
    public static function send_admin_claim_alert($claim) {
        $to = self::claim_notify_email();
        if (!$to) return false;

        $user     = get_userdata((int) $claim->user_id);
        $warranty = class_exists('GWARR_DB') ? GWARR_DB::find((int) $claim->warranty_id) : null;
        $media    = class_exists('GWARR_Claims') ? GWARR_Claims::media_ids($claim) : [];

        $rows = self::claim_rows($warranty, $claim);
        $rows['Customer'] = esc_html(($user ? $user->display_name : 'unknown') . ' (' . ($user ? $user->user_email : '—') . ')');
        $rows['Issue']    = nl2br(esc_html((string) $claim->issue_description));
        $rows['Media']    = $media ? esc_html(count($media) . ' file(s) attached') : 'none';

        $m = self::render_admin_claim_email($rows);
        return self::send($to, $m['subject'], $m['html']);
    }

    /** Where claim-submission alerts go (settings → fallback warranty@galado.com.my). */
    private static function claim_notify_email() {
        $s = get_option('gwarr_settings', []);
        $e = strtolower(trim((string) ($s['claim_notify_email'] ?? '')));
        return ($e !== '' && is_email($e)) ? $e : 'warranty@galado.com.my';
    }

    private static function claim_ctx($user, $warranty, $claim, $note) {
        return [
            'name'           => self::first_name($user),
            'warranties_url' => self::warranties_url(),
            'meta'           => self::claim_rows($warranty, $claim),
            'note'           => $note,
        ];
    }

    private static function warranties_url() {
        if (function_exists('gwarr_my_warranties_url')) {
            $u = gwarr_my_warranties_url();
            if ($u) return $u;
        }
        if (function_exists('wc_get_page_permalink')) {
            return trailingslashit(wc_get_page_permalink('myaccount')) . 'warranties/';
        }
        return home_url('/my-account/');
    }

    /**
     * Send the three customer claim emails + the admin alert as samples to an
     * address, with placeholder data. Subjects are prefixed [SAMPLE]. Used by
     * the settings "send sample emails" button.
     *
     * @return int emails sent
     */
    public static function send_sample_claim_emails($to) {
        if (!is_email($to)) return 0;

        $meta = [
            'Item'  => esc_html('Mini Wrist Strap'),
            'Where' => esc_html('Website Order'),
            'Order' => '<code style="font-family:monospace;">#12877</code>',
        ];
        $url  = self::warranties_url();
        $sent = 0;

        $variants = [
            'received' => '',
            'approved' => esc_html('We\'ve approved a replacement strap — just the delivery fee below and we\'ll ship it out.'),
            'rejected' => esc_html('The photos show impact damage, which falls outside the satisfaction guarantee.'),
        ];
        foreach ($variants as $state => $note) {
            $ctx = ['name' => 'Sherlyn', 'warranties_url' => $url, 'meta' => $meta, 'note' => $note];
            if ($state === 'approved') { // demo the pay-shipping button
                $ctx['shipping_fee'] = 8.00;
                $ctx['pay_url']      = home_url('/checkout/order-pay/12901/?pay_for_order=true&key=wc_order_SAMPLE');
            }
            $m = self::render_claim_email($state, $ctx);
            if (self::send($to, '[SAMPLE] ' . $m['subject'], $m['html'])) $sent++;
        }

        $rows = $meta;
        $rows['Customer'] = esc_html('Sherlyn Tan (sherlyn@galado.com.my)');
        $rows['Issue']    = esc_html('The strap clip snapped after about 3 weeks of normal use.');
        $rows['Media']    = esc_html('2 file(s) attached');
        $am = self::render_admin_claim_email($rows);
        if (self::send($to, '[SAMPLE] ' . $am['subject'], $am['html'])) $sent++;

        return $sent;
    }

    /**
     * Shared definition rows describing the warranty a claim is against.
     */
    private static function claim_rows($warranty, $claim) {
        if (!$warranty) {
            return ['Claim' => '#' . (int) $claim->id];
        }
        $is_website = isset($warranty->source) && $warranty->source === 'website';
        $order      = ($is_website && !empty($warranty->wc_order_id)) ? '#' . $warranty->wc_order_id : $warranty->order_number;

        $rows = [];
        // When the claim names a specific item (multi-item order), lead with it.
        if (!empty($claim->item_label)) {
            $rows['Item'] = esc_html($claim->item_label);
        } elseif (!empty($warranty->product_text)) {
            $rows['Product'] = gwarr_format_product_email($warranty->product_text);
        }
        $rows['Where']  = esc_html(GWARR_Marketplaces::label($warranty->marketplace));
        $rows['Order']  = '<code style="font-family:monospace;">' . esc_html($order) . '</code>';
        return $rows;
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
    // GALADO Club-styled shell for claim emails (cream/coral, Baloo 2 + Nunito)
    // ============================================================

    /**
     * Build a customer claim email (received | approved | rejected).
     * @return array{subject:string,html:string}
     */
    private static function render_claim_email($state, $ctx) {
        $name = esc_html(($ctx['name'] ?? '') !== '' ? $ctx['name'] : 'there');
        $url  = $ctx['warranties_url'] ?? home_url('/my-account/');
        $note = trim((string) ($ctx['note'] ?? '')); // already escaped by caller
        $meta = $ctx['meta'] ?? [];

        $cta_u     = $url;   // CTA target (approved-with-fee overrides to the pay URL)
        $secondary = '';     // optional quiet link below the CTA

        if ($state === 'approved') {
            $subject = 'Your warranty claim is approved ✦';
            $pre     = 'Good news — your GALADO warranty claim is approved.';
            $icon    = '&#127881;'; $ifrom = '#eef7f0'; $ito = '#bfe6cd'; $ib = '#3fa66f';
            $head    = 'Good news, ' . $name . '!';
            $inner   = self::club_paragraph('Your warranty claim has been <strong style="color:#46302e;">approved</strong>. Our team will reach out with the next steps to get this sorted for you.')
                     . self::club_meta($meta)
                     . ($note !== '' ? self::club_callout('success', $note) : '');

            $cta_l = 'View my warranties';

            // Replacement shipping fee handling.
            $fee = (float) ($ctx['shipping_fee'] ?? 0);
            $pay = (string) ($ctx['pay_url'] ?? '');
            if ($fee > 0) {
                $fee_str = 'RM ' . number_format($fee, 2);
                if ($pay !== '') {
                    $inner .= self::club_callout('info', 'A delivery fee of <strong style="color:#46302e;">' . $fee_str . '</strong> applies for shipping your replacement. Tap below to pay securely — we\'ll ship as soon as it\'s received.');
                    $cta_l = 'Pay ' . $fee_str . ' shipping';
                    $cta_u = $pay;
                    $secondary = '<a href="' . esc_url($url) . '" style="color:#8a6f6c;text-decoration:underline;font-weight:700;">View my warranties</a>';
                } elseif (!empty($ctx['shipping_paid'])) {
                    $inner .= self::club_callout('success', 'Your ' . $fee_str . ' delivery fee is paid — your replacement is on the way. Thank you!');
                } else {
                    $inner .= self::club_callout('info', 'A delivery fee of <strong style="color:#46302e;">' . $fee_str . '</strong> applies for shipping your replacement — we\'ll send you a secure payment link shortly.');
                }
            }
        } elseif ($state === 'rejected') {
            $subject = 'An update on your warranty claim';
            $pre     = 'An update on your GALADO warranty claim.';
            $icon    = '&#9993;&#65039;'; $ifrom = '#fff3cd'; $ito = '#f7d98f'; $ib = '#d99a23';
            $head    = 'An update on your claim';
            $inner   = self::club_paragraph('Hi ' . $name . ', thanks for submitting your warranty claim. After reviewing it, we\'re unable to approve it this time.')
                     . self::club_meta($meta)
                     . self::club_callout('warning', ($note !== '' ? '<strong style="color:#46302e;">Reason:</strong> ' . $note . '<br><br>' : '') . 'Have more details or photos? Just reply to this email, or submit a new claim from My Warranties.');
            $cta_l = 'Go to my warranties';
        } else { // received
            $subject = 'We\'ve received your warranty claim';
            $pre     = 'We\'ve received your GALADO warranty claim.';
            $icon    = '&#128736;&#65039;'; $ifrom = '#fff3cd'; $ito = '#f7d98f'; $ib = '#d99a23';
            $head    = 'Claim received, ' . $name . '!';
            $inner   = self::club_paragraph('Thanks &mdash; we\'ve received your warranty claim and our team will review it shortly.')
                     . self::club_meta($meta)
                     . self::club_callout('info', 'We\'ll email you the moment there\'s an update. You can track the status any time under <strong style="color:#46302e;">My Warranties</strong>.');
            $cta_l = 'Track my claim';
        }

        $html = self::club_shell([
            'pill' => 'WARRANTY', 'preheader' => $pre,
            'icon' => $icon, 'icon_from' => $ifrom, 'icon_to' => $ito, 'icon_border' => $ib,
            'heading' => $head, 'inner' => $inner,
            'cta_label' => $cta_l, 'cta_url' => $cta_u, 'secondary' => $secondary,
        ]);
        return ['subject' => $subject, 'html' => $html];
    }

    private static function render_admin_claim_email($rows) {
        $inner = self::club_paragraph('A customer has submitted a warranty claim for review.')
               . self::club_meta($rows);
        $html = self::club_shell([
            'pill' => 'NEW CLAIM', 'preheader' => 'A new warranty claim is awaiting review.',
            'icon' => '&#128276;', 'icon_from' => '#fff3cd', 'icon_to' => '#f7d98f', 'icon_border' => '#d99a23',
            'heading' => 'New warranty claim', 'inner' => $inner,
            'cta_label' => 'Review claim', 'cta_url' => admin_url('admin.php?page=galado-warranty-claims'),
        ]);
        return ['subject' => '[GALADO] New warranty claim pending review', 'html' => $html];
    }

    private static function club_paragraph($html) {
        return '<p style="margin:0 0 18px;font-family:\'Nunito\',sans-serif;font-weight:600;font-size:15px;line-height:1.6;color:#8a6f6c;">' . $html . '</p>';
    }

    /** Left-aligned cream detail box (label/value rows). Values may be HTML. */
    private static function club_meta($pairs) {
        if (empty($pairs)) return '';
        $rows = '';
        foreach ($pairs as $label => $value) {
            $rows .= '<tr>'
                . '<td style="padding:4px 14px 4px 0;font-family:\'Nunito\',sans-serif;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:#b8a6a1;white-space:nowrap;vertical-align:top;">' . esc_html($label) . '</td>'
                . '<td style="padding:4px 0;font-family:\'Baloo 2\',sans-serif;font-weight:700;font-size:14px;line-height:1.5;color:#46302e;">' . $value . '</td>'
                . '</tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;"><tr>'
            . '<td style="background-color:#fff6ee;border:1px solid #f3ddd2;border-radius:14px;padding:14px 18px;text-align:left;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
            . '</td></tr></table>';
    }

    private static function club_callout($variant, $html) {
        $p = [
            'success' => ['#eef7f0', '#cfe8d6', '#3fa66f'],
            'warning' => ['#fff8ec', '#e3b341', '#9a6a12'],
            'info'    => ['#fff6ee', '#f3ddd2', '#8a6f6c'],
        ];
        $c = $p[$variant] ?? $p['info'];
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;"><tr>'
            . '<td style="background-color:' . $c[0] . ';border:1px solid ' . $c[1] . ';border-radius:14px;padding:14px 18px;text-align:left;font-family:\'Nunito\',sans-serif;font-weight:600;font-size:14px;line-height:1.55;color:' . $c[2] . ';">' . $html . '</td>'
            . '</tr></table>';
    }

    /** The full Club-branded email document (header + card + footer). */
    private static function club_shell($a) {
        $pill   = esc_html($a['pill'] ?? 'WARRANTY');
        $pre    = (string) ($a['preheader'] ?? '');
        $icon   = $a['icon'] ?? '&#128737;&#65039;';
        $ifrom  = $a['icon_from'] ?? '#fff3cd';
        $ito    = $a['icon_to'] ?? '#f5b83d';
        $ib     = $a['icon_border'] ?? '#d99a23';
        $head   = (string) ($a['heading'] ?? '');
        $inner  = (string) ($a['inner'] ?? '');
        $cta_l  = (string) ($a['cta_label'] ?? '');
        $cta_u  = esc_url($a['cta_url'] ?? '');
        $secnd  = (string) ($a['secondary'] ?? '');
        $shop   = esc_url(home_url('/'));

        $h  = '<!DOCTYPE html><html lang="en"><head>';
        $h .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $h .= '<meta name="color-scheme" content="light only"><title>GALADO</title>';
        $h .= '<style>';
        $h .= "@import url('https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Nunito:wght@400;600;700;800&display=swap');";
        $h .= 'body{margin:0;padding:0;background:#fff6ee;}';
        $h .= '@media (max-width:520px){.gc-card{padding:30px 22px !important;}}';
        $h .= '</style></head>';
        $h .= '<body style="margin:0;padding:0;background-color:#fff6ee;">';
        if ($pre !== '') {
            $h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#fff6ee;">' . esc_html($pre) . '</div>';
        }
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff6ee;">';
        $h .= '<tr><td align="center" style="padding:36px 16px;">';
        $h .= '<table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:480px;max-width:100%;">';

        // Wordmark + pill
        $h .= '<tr><td align="center" style="padding-bottom:22px;">';
        $h .= '<span style="font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:24px;color:#46302e;letter-spacing:0.02em;vertical-align:middle;">GALADO</span>';
        $h .= '<span style="display:inline-block;font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:12px;letter-spacing:0.08em;color:#ffffff;background-color:#f25d6f;background-image:linear-gradient(135deg,#ff7e8a,#f25d6f);padding:4px 10px;border-radius:8px;margin-left:7px;vertical-align:middle;">' . $pill . '</span>';
        $h .= '</td></tr>';

        // Card
        $h .= '<tr><td class="gc-card" style="background-color:#ffffff;border:1px solid #f3ddd2;border-radius:28px;padding:38px 34px;text-align:center;box-shadow:0 6px 18px rgba(70,48,46,0.08);">';

        // Icon circle
        $h .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 18px;"><tr>';
        $h .= '<td align="center" valign="middle" width="64" height="64" style="width:64px;height:64px;border-radius:50%;background-color:' . $ito . ';background-image:radial-gradient(circle at 35% 30%,' . $ifrom . ',' . $ito . ' 70%);border:3px solid ' . $ib . ';font-size:30px;">' . $icon . '</td>';
        $h .= '</tr></table>';

        if ($head !== '') {
            $h .= '<h1 style="margin:0 0 16px;font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:23px;line-height:1.15;color:#46302e;">' . $head . '</h1>';
        }

        $h .= $inner;

        if ($cta_l !== '' && $cta_u !== '') {
            $h .= '<a href="' . $cta_u . '" target="_blank" rel="noopener" style="display:inline-block;font-family:\'Baloo 2\',sans-serif;font-weight:800;font-size:16px;line-height:1;color:#ffffff;text-decoration:none;padding:15px 32px;border-radius:999px;background-color:#f25d6f;background-image:linear-gradient(135deg,#ff7e8a,#f25d6f);box-shadow:0 6px 16px rgba(242,93,111,0.35);margin-top:4px;">' . esc_html($cta_l) . ' &rarr;</a>';
        }
        if ($secnd !== '') {
            $h .= '<p style="margin:16px 0 0;font-family:\'Nunito\',sans-serif;font-weight:700;font-size:13px;color:#8a6f6c;">' . $secnd . '</p>';
        }

        $h .= '</td></tr>';

        // Footer
        $h .= '<tr><td style="padding:18px 18px 0;text-align:center;font-family:\'Nunito\',sans-serif;font-size:11px;color:#b8a6a1;">GALADO &middot; <a href="' . $shop . '" style="color:#b8a6a1;text-decoration:underline;">galado.com.my</a></td></tr>';

        $h .= '</table></td></tr></table></body></html>';
        return $h;
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
