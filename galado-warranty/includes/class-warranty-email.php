<?php
/**
 * Transactional emails for the warranty lifecycle.
 *
 * GALADO Brand v1.0: one core-brand shell (brand_shell) wraps every message.
 * White #F5F5F3 canvas, hosted galado wordmark header, white 20px card, Archivo
 * display + Inter body, Ink #111111 pill CTAs, Red #E4002B as accent only, no
 * gradients, GALADO. red-dot footer. All styles inline for email-client safety;
 * fonts via @import (clients that strip it fall back to Arial Black / system).
 *
 * Copy rule: no em-dashes (reads as AI-written per the guidelines); use commas,
 * colons or full stops. Warm, short, never salesy.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Email {

    /** Hosted galado wordmark (transparent PNG on the Klaviyo CDN, 360px 2x). */
    const WORDMARK = 'https://d3k81ch9hvuctc.cloudfront.net/company/SyPuBG/images/e6350915-daf0-4a66-9a94-ace488455a07.png';

    private static function afont() { return "'Archivo','Arial Black','Helvetica Neue',Arial,sans-serif"; }
    private static function bfont() { return "'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif"; }

    // ============================================================
    // Customer-facing
    // ============================================================

    public static function send_approved($row) {
        $user = get_userdata((int) $row->user_id);
        if (!$user) return false;

        $settings   = get_option('gwarr_settings', []);
        // Tier-aware coverage length: Black Club members get 12, others 6.
        $months     = function_exists('gwarr_months_for_row')
            ? gwarr_months_for_row($row)
            : max(1, (int) ($settings['warranty_months'] ?? 6));
        $is_expired = $row->warranty_ends && strtotime($row->warranty_ends) < strtotime(current_time('Y-m-d'));

        if ($is_expired) {
            return self::send_approved_expired($user, $row, $months, $settings);
        }

        $is_black = $months >= 12;
        $subject  = $is_black
            ? 'Your warranty is now 12 months, plus a welcome gift ✦'
            : 'Your GALADO warranty is registered ✦';

        return self::send($user->user_email, $subject, self::render_approved($user, $row, $months, $is_black, $settings));
    }

    private static function render_approved($user, $row, $months, $is_black, $settings) {
        $name        = esc_html(self::first_name($user) ?: 'there');
        $marketplace = esc_html(GWARR_Marketplaces::label($row->marketplace));
        $months      = (int) $months;
        $until       = esc_html(mysql2date('F j, Y', $row->warranty_ends));

        $meta = [];
        if (!empty($row->product_text)) {
            $meta['Item'] = gwarr_format_product_email($row->product_text);
        }
        $meta['Where']    = $marketplace;
        $meta['Order']    = self::mono(esc_html($row->order_number));
        $meta['Coverage'] = esc_html($months . ' months, until ' . mysql2date('F j, Y', $row->warranty_ends));

        $inner  = self::bp('We\'ve registered your ' . $marketplace . ' order and your extended warranty is now active. Here are the details:');
        $inner .= self::bmeta($meta);

        if ($is_black) {
            $inner .= self::bcallout('neutral', '<strong style="color:#111111;">Black member perk:</strong> 12 months of coverage, double the standard 6. Thanks for being with us.');
        }
        if (!empty($row->coupon_code)) {
            $inner .= self::bcoupon($row->coupon_code, (int) ($settings['coupon_expiry_days'] ?? 90));
        }

        $club_base = defined('GALADO_CLUB_URL') ? rtrim(GALADO_CLUB_URL, '/') : 'https://club.galado.com.my';
        $secondary = '<a href="' . esc_url(gwarr_coverage_url()) . '" style="color:#8C8C8C;text-decoration:underline;font-weight:600;">See what\'s covered</a>';

        return self::brand_shell([
            'eyebrow'    => 'Warranty registered',
            'eyebrow_color' => '#111111', // coupon carries the red on this one
            'heading'    => 'Thanks for registering, ' . $name . '.',
            'inner'      => $inner,
            'cta_label'  => 'Open GALADO Club',
            'cta_url'    => $club_base . '/?utm_source=warranty_email',
            'secondary'  => $secondary,
            'preheader'  => 'Your GALADO warranty is registered. Coverage details and a welcome gift inside.',
        ]);
    }

    /**
     * Registration whose coverage window has already lapsed. Same brand shell,
     * honest "lapsed" note, welcome gift still shown.
     */
    private static function send_approved_expired($user, $row, $months, $settings) {
        $name        = esc_html(self::first_name($user) ?: 'there');
        $marketplace = esc_html(GWARR_Marketplaces::label($row->marketplace));

        $meta = [];
        if (!empty($row->product_text)) {
            $meta['Item'] = gwarr_format_product_email($row->product_text);
        }
        $meta['Where'] = $marketplace;
        $meta['Order'] = self::mono(esc_html($row->order_number));

        $inner  = self::bp('We\'ve registered your ' . $marketplace . ' order. Here are the details:');
        $inner .= self::bmeta($meta);
        $inner .= self::bcallout('neutral', 'This purchase is more than ' . (int) $months . ' months old, so the extended warranty has already lapsed. Your welcome gift below still stands. Thanks for being a customer.');
        if (!empty($row->coupon_code)) {
            $inner .= self::bcoupon($row->coupon_code, (int) ($settings['coupon_expiry_days'] ?? 90));
        }

        $html = self::brand_shell([
            'eyebrow'       => 'Warranty registered',
            'eyebrow_color' => '#111111',
            'heading'       => 'Thanks for registering, ' . $name . '.',
            'inner'         => $inner,
            'cta_label'     => 'See what\'s covered',
            'cta_url'       => gwarr_coverage_url(),
            'preheader'     => 'Your registration is confirmed, plus a welcome gift.',
        ]);
        return self::send($user->user_email, 'Your warranty registration is confirmed, plus a welcome gift', $html);
    }

    public static function send_rejected($row) {
        $user = get_userdata((int) $row->user_id);
        if (!$user) return false;

        $marketplace = esc_html(GWARR_Marketplaces::label($row->marketplace));

        $inner  = self::bp('Thanks for registering your warranty for ' . $marketplace . ' order ' . self::mono(esc_html($row->order_number)) . '.');
        $inner .= self::bcallout('red', 'We couldn\'t verify this order against our records.' . (!empty($row->admin_note) ? ' ' . esc_html($row->admin_note) : ''));
        $inner .= self::bp('If you think this is a mistake, reply to this email with your order screenshot or proof of purchase and we\'ll take another look.');

        $html = self::brand_shell([
            'eyebrow'       => 'Warranty update',
            'eyebrow_color' => '#111111',
            'heading'       => 'Hi ' . esc_html(self::first_name($user)) . ',',
            'inner'         => $inner,
            'preheader'     => 'About your GALADO warranty registration.',
        ]);
        return self::send($user->user_email, 'About your GALADO warranty registration', $html);
    }

    // ============================================================
    // Admin-facing
    // ============================================================

    public static function send_admin_new($row) {
        $user        = get_userdata((int) $row->user_id);
        $admin_email = get_option('admin_email');
        if (!$admin_email) return false;

        $rows = [
            'Customer' => esc_html(($user ? $user->display_name : 'unknown') . ' (' . ($user ? $user->user_email : '-') . ')'),
            'Where'    => esc_html(GWARR_Marketplaces::label($row->marketplace)),
            'Order'    => self::mono(esc_html($row->order_number)),
        ];
        if (!empty($row->product_text)) {
            $rows['Product'] = gwarr_format_product_email($row->product_text);
        }

        $inner  = self::bp('A customer just submitted a warranty registration. Review and approve in the admin panel.');
        $inner .= self::bmeta($rows);

        $html = self::brand_shell([
            'eyebrow'       => 'New registration',
            'eyebrow_color' => '#111111',
            'heading'       => 'New warranty registration',
            'inner'         => $inner,
            'cta_label'     => 'Review in admin',
            'cta_url'       => admin_url('admin.php?page=galado-warranty'),
            'preheader'     => 'A new warranty registration is pending review.',
        ]);
        return self::send($admin_email, '[GALADO] New warranty registration pending review', $html);
    }

    public static function send_admin_cross_claim_alert($existing, $attempting_user_id) {
        $admin_email = get_option('admin_email');
        if (!$admin_email) return false;

        $eu = get_userdata((int) $existing->user_id);
        $au = get_userdata((int) $attempting_user_id);

        $inner  = self::bp('Someone tried to register a warranty for an order that\'s already on file under a different account. Could be a customer who used a different email, or an unauthorised claim. Worth a quick look.');
        $inner .= self::bsubhead('Order in question');
        $inner .= self::bmeta([
            'Where' => esc_html(GWARR_Marketplaces::label($existing->marketplace)),
            'Order' => self::mono(esc_html($existing->order_number)),
        ]);
        $inner .= self::bsubhead('Already registered by');
        $inner .= self::bmeta([
            'Name'       => esc_html($eu ? $eu->display_name : '(deleted user)'),
            'Email'      => esc_html($eu ? $eu->user_email : '-'),
            'Status'     => esc_html(ucfirst($existing->status)),
            'Registered' => esc_html($existing->created_at),
        ]);
        $inner .= self::bsubhead('New claim attempt by');
        $inner .= self::bmeta([
            'Name'  => esc_html($au ? $au->display_name : '(unknown user)'),
            'Email' => esc_html($au ? $au->user_email : '-'),
        ]);

        $html = self::brand_shell([
            'eyebrow'   => 'Claim conflict',
            'heading'   => 'Warranty claim conflict',
            'inner'     => $inner,
            'cta_label' => 'Open Warranty admin',
            'cta_url'   => admin_url('admin.php?page=galado-warranty'),
            'preheader' => 'An order was registered under two accounts.',
        ]);
        return self::send($admin_email, '[GALADO] Warranty claim conflict: order already registered', $html);
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

        // Replacement-shipping fee -> pay-online button (WooCommerce order).
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
        $rows['Customer'] = esc_html(($user ? $user->display_name : 'unknown') . ' (' . ($user ? $user->user_email : '-') . ')');
        $rows = array_merge($rows, self::claim_delivery_rows($claim));
        $rows['Issue']    = nl2br(esc_html((string) $claim->issue_description));
        $rows['Media']    = $media ? esc_html(count($media) . ' file(s) attached') : 'none';

        $m = self::render_admin_claim_email($rows);
        return self::send($to, $m['subject'], $m['html']);
    }

    /**
     * Phone + delivery-address rows from a claim's delivery snapshot (empty
     * array when the claim predates delivery collection).
     */
    private static function claim_delivery_rows($claim) {
        $rows = [];
        if (!empty($claim->delivery_phone)) {
            $rows['Phone'] = esc_html($claim->delivery_phone);
        }
        if (!empty($claim->delivery_address_1)) {
            $state = function_exists('gwarr_state_label') ? gwarr_state_label((string) ($claim->delivery_state ?? '')) : (string) ($claim->delivery_state ?? '');
            $addr  = esc_html((string) ($claim->delivery_name ?? ''))
                . '<br>' . esc_html((string) $claim->delivery_address_1)
                . (!empty($claim->delivery_address_2) ? ', ' . esc_html((string) $claim->delivery_address_2) : '')
                . '<br>' . esc_html(trim(($claim->delivery_postcode ?? '') . ' ' . ($claim->delivery_city ?? '') . ', ' . $state, ' ,'));
            $rows['Deliver to'] = $addr;
        }
        return $rows;
    }


    /** Where claim-submission alerts go (settings, fallback warranty@galado.com.my). */
    public static function claim_notify_email() {
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
            'Order' => self::mono('#12877'),
        ];
        $url  = self::warranties_url();
        $sent = 0;

        $variants = [
            'received' => '',
            'approved' => esc_html('We\'ve approved a replacement strap. Just the delivery fee below and we\'ll ship it out.'),
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

        $sample_addr = esc_html('Sherlyn Tan') . '<br>' . esc_html('12, Jalan Mahsuri 3, Sunway Tunas') . '<br>' . esc_html('11900 Bayan Lepas, Pulau Pinang');

        $rows = $meta;
        $rows['Customer']   = esc_html('Sherlyn Tan (sherlyn@galado.com.my)');
        $rows['Phone']      = esc_html('012-345 6789');
        $rows['Deliver to'] = $sample_addr;
        $rows['Issue']      = esc_html('The strap clip snapped after about 3 weeks of normal use.');
        $rows['Media']      = esc_html('2 file(s) attached');
        $am = self::render_admin_claim_email($rows);
        if (self::send($to, '[SAMPLE] ' . $am['subject'], $am['html'])) $sent++;

        return $sent;
    }

    /**
     * Shared detail rows describing the warranty a claim is against.
     */
    private static function claim_rows($warranty, $claim) {
        if (!$warranty) {
            return ['Claim' => self::mono('#' . (int) $claim->id)];
        }
        $is_website = isset($warranty->source) && $warranty->source === 'website';
        $order      = ($is_website && !empty($warranty->wc_order_id)) ? '#' . $warranty->wc_order_id : $warranty->order_number;

        $rows = [];
        if (!empty($claim->item_label)) {
            $rows['Item'] = esc_html($claim->item_label);
        } elseif (!empty($warranty->product_text)) {
            $rows['Product'] = gwarr_format_product_email($warranty->product_text);
        }
        $rows['Where'] = esc_html(GWARR_Marketplaces::label($warranty->marketplace));
        $rows['Order'] = self::mono(esc_html($order));
        return $rows;
    }

    // ============================================================
    // Claim email renderers (build content, hand to brand_shell)
    // ============================================================

    /** @return array{subject:string,html:string} */
    private static function render_claim_email($state, $ctx) {
        $name = esc_html(($ctx['name'] ?? '') !== '' ? $ctx['name'] : 'there');
        $url  = $ctx['warranties_url'] ?? home_url('/my-account/');
        $note = trim((string) ($ctx['note'] ?? '')); // already escaped by caller
        $meta = $ctx['meta'] ?? [];

        $cta_u     = $url;
        $secondary = '';

        if ($state === 'approved') {
            $subject = 'Your warranty claim is approved ✦';
            $pre     = 'Good news, your GALADO warranty claim is approved.';
            $eyebrow = 'Claim approved';
            $head    = 'Good news, ' . $name . '.';
            $inner   = self::bp('Your warranty claim has been approved. Our team will reach out with the next steps to get this sorted for you.')
                     . self::bmeta($meta)
                     . ($note !== '' ? self::bcallout('neutral', $note) : '');
            $cta_l = 'View my warranties';

            $fee = (float) ($ctx['shipping_fee'] ?? 0);
            $pay = (string) ($ctx['pay_url'] ?? '');
            if ($fee > 0) {
                $fee_str = 'RM ' . number_format($fee, 2);
                if ($pay !== '') {
                    $inner .= self::bcallout('green', 'A delivery fee of <strong style="color:#1E8E5A;">' . $fee_str . '</strong> applies for shipping your replacement. Pay securely below and we\'ll ship as soon as it\'s received.');
                    $cta_l     = 'Pay ' . $fee_str . ' shipping';
                    $cta_u     = $pay;
                    $secondary = '<a href="' . esc_url($url) . '" style="color:#8C8C8C;text-decoration:underline;font-weight:600;">View my warranties</a>';
                } elseif (!empty($ctx['shipping_paid'])) {
                    $inner .= self::bcallout('neutral', 'Your ' . $fee_str . ' delivery fee is paid. Your replacement is on the way, thank you.');
                } else {
                    $inner .= self::bcallout('neutral', 'A delivery fee of ' . $fee_str . ' applies for shipping your replacement. We\'ll send you a secure payment link shortly.');
                }
            }
        } elseif ($state === 'rejected') {
            $subject = 'An update on your warranty claim';
            $pre     = 'An update on your GALADO warranty claim.';
            $eyebrow = 'Claim update';
            $head    = 'An update on your claim.';
            $inner   = self::bp('Hi ' . $name . ', thanks for submitting your warranty claim. After reviewing it, we\'re unable to approve it this time.')
                     . self::bmeta($meta)
                     . ($note !== '' ? self::bcallout('red', '<strong style="color:#B80023;">Reason:</strong> ' . $note) : '')
                     . self::bp('Have more details or photos? Reply to this email, or submit a new claim from My Warranties.');
            $cta_l = 'Go to my warranties';
        } else { // received
            $subject = 'We\'ve received your warranty claim';
            $pre     = 'We\'ve received your GALADO warranty claim.';
            $eyebrow = 'Claim received';
            $head    = 'Claim received, ' . $name . '.';
            $inner   = self::bp('Thanks, we\'ve received your warranty claim and our team will review it shortly.')
                     . self::bmeta($meta)
                     . self::bcallout('neutral', 'We\'ll email you the moment there\'s an update. You can track the status any time under My Warranties.');
            $cta_l = 'Track my claim';
        }

        return ['subject' => $subject, 'html' => self::brand_shell([
            'eyebrow'   => $eyebrow,
            'heading'   => $head,
            'inner'     => $inner,
            'cta_label' => $cta_l,
            'cta_url'   => $cta_u,
            'secondary' => $secondary,
            'preheader' => $pre,
        ])];
    }

    /** @return array{subject:string,html:string} */
    private static function render_admin_claim_email($rows) {
        $inner = self::bp('A customer has submitted a warranty claim for review.') . self::bmeta($rows);
        return ['subject' => '[GALADO] New warranty claim pending review', 'html' => self::brand_shell([
            'eyebrow'       => 'New claim',
            'eyebrow_color' => '#111111',
            'heading'       => 'New warranty claim',
            'inner'         => $inner,
            'cta_label'     => 'Review claim',
            'cta_url'       => admin_url('admin.php?page=galado-warranty-claims'),
            'preheader'     => 'A new warranty claim is awaiting review.',
        ])];
    }

    // ============================================================
    // Brand v1.0 building blocks (inline-styled, email-safe)
    // ============================================================

    private static function bp($html) {
        return '<p style="margin:0 0 18px;font-family:' . self::bfont() . ';font-weight:400;font-size:15px;line-height:1.6;color:#4A4A4A;">' . $html . '</p>';
    }

    private static function bsubhead($text) {
        return '<div style="margin:6px 0 10px;font-family:' . self::afont() . ';font-weight:800;font-size:14px;color:#111111;">' . esc_html($text) . '</div>';
    }

    private static function mono($text) {
        // $text is already escaped by the caller.
        return '<span style="font-family:\'Courier New\',monospace;font-size:13px;color:#111111;">' . $text . '</span>';
    }

    /** Detail box: warm-grey fill, Inter uppercase labels, Archivo values. */
    private static function bmeta($pairs) {
        if (empty($pairs)) return '';
        $rows = '';
        foreach ($pairs as $label => $value) {
            $rows .= '<tr>'
                . '<td style="padding:5px 16px 5px 0;font-family:' . self::bfont() . ';font-weight:600;font-size:11px;letter-spacing:0.04em;text-transform:uppercase;color:#8C8C8C;white-space:nowrap;vertical-align:top;">' . esc_html($label) . '</td>'
                . '<td style="padding:5px 0;font-family:' . self::afont() . ';font-weight:700;font-size:14px;line-height:1.5;color:#111111;">' . $value . '</td>'
                . '</tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;"><tr>'
            . '<td style="background-color:#F5F5F3;border-radius:12px;padding:16px 18px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
            . '</td></tr></table>';
    }

    /** neutral (warm-grey) | red (rejection) | green (positive next step). */
    private static function bcallout($variant, $html) {
        if ($variant === 'red')        { $bg = '#FFE9EC'; $col = '#B80023'; }
        elseif ($variant === 'green')  { $bg = '#E9F7EF'; $col = '#1E8E5A'; }
        else                           { $bg = '#F5F5F3'; $col = '#4A4A4A'; }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;"><tr>'
            . '<td style="background-color:' . $bg . ';border-radius:12px;padding:14px 18px;font-family:' . self::bfont() . ';font-weight:500;font-size:14px;line-height:1.55;color:' . $col . ';">' . $html . '</td>'
            . '</tr></table>';
    }

    /** Welcome-gift coupon: red-tint promo card (the sanctioned red moment). */
    private static function bcoupon($code, $expiry_days) {
        $perks = esc_html(function_exists('gwarr_perk_description') ? gwarr_perk_description() : 'a discount');
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;"><tr>'
            . '<td style="background-color:#FFE9EC;border-radius:20px;padding:24px 22px;text-align:center;">'
            . '<div style="font-family:' . self::afont() . ';font-weight:700;font-size:11px;letter-spacing:0.20em;text-transform:uppercase;color:#E4002B;margin-bottom:12px;">Welcome gift</div>'
            . '<div style="display:inline-block;font-family:\'Courier New\',monospace;font-weight:700;font-size:23px;letter-spacing:0.06em;color:#111111;background:#FFFFFF;border-radius:999px;padding:11px 24px;margin-bottom:12px;">' . esc_html($code) . '</div>'
            . '<div style="font-family:' . self::afont() . ';font-weight:800;font-size:16px;color:#111111;margin-bottom:4px;">' . $perks . '</div>'
            . '<div style="font-family:' . self::bfont() . ';font-weight:400;font-size:12px;color:#8C8C8C;">on your next order at galado.com.my. single use. valid ' . (int) $expiry_days . ' days.</div>'
            . '</td></tr></table>';
    }

    /**
     * The full brand-v1.0 email document: wordmark header, white card, footer.
     */
    private static function brand_shell($a) {
        $eyebrow   = (string) ($a['eyebrow'] ?? '');
        $eyebrow_c = (string) ($a['eyebrow_color'] ?? '#E4002B');
        $head      = (string) ($a['heading'] ?? '');
        $inner     = (string) ($a['inner'] ?? '');
        $cta_l     = (string) ($a['cta_label'] ?? '');
        $cta_u     = esc_url($a['cta_url'] ?? '');
        $cta_var   = (string) ($a['cta_variant'] ?? 'ink'); // ink | red
        $secnd     = (string) ($a['secondary'] ?? '');
        $pre       = (string) ($a['preheader'] ?? '');

        $af   = self::afont();
        $bf   = self::bfont();
        $home = esc_url(home_url('/'));

        $h  = '<!DOCTYPE html><html lang="en"><head>';
        $h .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $h .= '<meta name="color-scheme" content="light only"><title>GALADO</title>';
        $h .= '<style>';
        $h .= "@import url('https://fonts.googleapis.com/css2?family=Archivo:wght@700;800;900&family=Inter:wght@400;500;600;700&display=swap');";
        $h .= 'body{margin:0;padding:0;background:#F5F5F3;}';
        $h .= '@media (max-width:520px){.gw-card{padding:30px 24px !important;}}';
        $h .= '</style></head>';
        $h .= '<body style="margin:0;padding:0;background-color:#F5F5F3;">';
        if ($pre !== '') {
            $h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F5F5F3;">' . esc_html($pre) . '</div>';
        }
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F5F5F3;">';
        $h .= '<tr><td align="center" style="padding:40px 16px;">';
        $h .= '<table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" style="width:520px;max-width:100%;">';

        // Wordmark header
        $h .= '<tr><td align="center" style="padding-bottom:24px;">';
        $h .= '<a href="' . $home . '" target="_blank" rel="noopener" style="text-decoration:none;"><img src="' . self::WORDMARK . '" width="128" alt="galado" style="display:block;width:128px;height:auto;border:0;outline:none;"></a>';
        $h .= '</td></tr>';

        // Card
        $h .= '<tr><td class="gw-card" style="background-color:#FFFFFF;border:1px solid #E7E7E7;border-radius:20px;padding:38px 36px;text-align:left;">';

        if ($eyebrow !== '') {
            $h .= '<div style="font-family:' . $af . ';font-weight:700;font-size:11px;letter-spacing:0.20em;text-transform:uppercase;color:' . $eyebrow_c . ';margin:0 0 12px;">' . esc_html($eyebrow) . '</div>';
        }
        if ($head !== '') {
            $h .= '<h1 style="margin:0 0 16px;font-family:' . $af . ';font-weight:800;font-size:24px;line-height:1.18;letter-spacing:-0.01em;color:#111111;">' . $head . '</h1>';
        }
        $h .= $inner;

        if ($cta_l !== '' && $cta_u !== '') {
            $bg = $cta_var === 'red' ? '#E4002B' : '#111111';
            $h .= '<div style="margin-top:6px;"><a href="' . $cta_u . '" target="_blank" rel="noopener" style="display:inline-block;font-family:' . $af . ';font-weight:700;font-size:15px;line-height:1;color:#FFFFFF;text-decoration:none;padding:15px 30px;border-radius:999px;background-color:' . $bg . ';">' . esc_html($cta_l) . '</a></div>';
        }
        if ($secnd !== '') {
            $h .= '<p style="margin:16px 0 0;font-family:' . $bf . ';font-weight:600;font-size:13px;color:#8C8C8C;">' . $secnd . '</p>';
        }

        $h .= '</td></tr>';

        // Footer
        $h .= '<tr><td align="center" style="padding:24px 18px 0;font-family:' . $bf . ';font-size:12px;line-height:1.65;color:#8C8C8C;">';
        $h .= '<div style="font-family:' . $af . ';font-weight:800;font-size:15px;letter-spacing:0.02em;color:#111111;margin-bottom:6px;">GALADO<span style="color:#E4002B;">.</span></div>';
        $h .= 'Cases, charms and accessories that make your phone feel like yours.<br>';
        $h .= '<a href="' . $home . '" style="color:#8C8C8C;text-decoration:underline;">galado.com.my</a> &nbsp;&middot;&nbsp; Need help? Just reply to this email.';
        $h .= '</td></tr>';

        $h .= '</table></td></tr></table></body></html>';
        return $h;
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
        $from_name  = ($settings['from_name']  ?? '') ?: get_bloginfo('name');
        $from_email = ($settings['from_email'] ?? '') ?: get_option('admin_email');

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
