<?php
/**
 * GALADO CP's window into warranty registrations and claims (v1.12.0).
 *
 * CP (cp.galado.com.my, on its own server) lists and resolves warranties
 * through these routes instead of wp-admin (Clement, 9 Oct 2026: the data
 * stays in WooCommerce, managed in CP). Every action runs the same code as
 * the admin screens, so emails, coupons, G-Send opt-ins, shipping orders and
 * My Warranties behave exactly as before, and wp-admin keeps working.
 *
 * Auth: CP sends its key in X-GWARR-CP-Key. The store keeps only the key's
 * SHA-256 (option gwarr_cp_key_sha256), so the WordPress database never holds
 * the key itself; with no hash stored, every route refuses. X-GWARR-Actor
 * names the CP staff member (CP's own audit log is the record of who did
 * what; here it only fills the internal note of an approval left blank).
 *
 * Guards the admin screens leave to the UI live here: a registration is
 * approved or rejected only while pending, a claim is resolved only once, and
 * a claim never gets a second shipping order. Each check and its change run
 * inside GWARR_Lock, so two staff or a double click cannot both pass a check;
 * the second gets a 409. Routes stay out of the public /wp-json index, and
 * answers carry Cache-Control: no-store (they hold customer details).
 */

if (!defined('ABSPATH')) exit;

class GWARR_CP_API {
    const NS         = 'galado-warranty/v1';
    const KEY_OPTION = 'gwarr_cp_key_sha256';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        $id = '(?P<id>\d+)';
        $routes = [
            ['/cp/counts', 'GET', 'counts'],
            ['/cp/registrations', 'GET', 'registrations'],
            ["/cp/registrations/{$id}", 'GET', 'registration'],
            ["/cp/registrations/{$id}/approve", 'POST', 'approve_registration'],
            ["/cp/registrations/{$id}/reject", 'POST', 'reject_registration'],
            ["/cp/registrations/{$id}/edit", 'POST', 'edit_registration'],
            ['/cp/claims', 'GET', 'claims'],
            ["/cp/claims/{$id}", 'GET', 'claim'],
            ["/cp/claims/{$id}/approve", 'POST', 'approve_claim'],
            ["/cp/claims/{$id}/decline", 'POST', 'decline_claim'],
            ["/cp/claims/{$id}/resend", 'POST', 'resend_claim'],
            ["/cp/claims/{$id}/shipping", 'POST', 'charge_shipping'],
        ];
        foreach ($routes as $r) {
            register_rest_route(self::NS, $r[0], [
                'methods'             => $r[1],
                'callback'            => [__CLASS__, 'run_' . $r[2]],
                'permission_callback' => [__CLASS__, 'authorized'],
                'show_in_index'       => false,
            ]);
        }
    }

    /** Only CP: its key must hash to the stored fingerprint. */
    public static function authorized($req) {
        $hash = strtolower(trim((string) get_option(self::KEY_OPTION, '')));
        $key  = (string) $req->get_header('x_gwarr_cp_key');
        if ($hash === '' || $key === '') {
            return false;
        }
        return hash_equals($hash, hash('sha256', $key));
    }

    /**
     * Every route goes through here: one place to catch a fatal so CP gets a
     * plain error instead of an HTML crash page.
     */
    public static function __callStatic($name, $args) {
        if (strpos($name, 'run_') !== 0 || !method_exists(__CLASS__, substr($name, 4))) {
            return self::error('gwarr_no_route', 'No such action.', 404);
        }
        try {
            return call_user_func([__CLASS__, substr($name, 4)], $args[0]);
        } catch (Throwable $e) {
            error_log('[galado-warranty] cp api ' . $name . ': ' . $e->getMessage());
            return self::error('gwarr_failed', 'The store could not finish that. Open the warranty again to see what was saved before trying again.', 500);
        }
    }

    // ------------------------------------------------------------------ reads

    public static function counts($req) {
        return self::ok([
            'registrations' => GWARR_DB::status_counts(),
            'claims'        => GWARR_Claims::status_counts(),
        ]);
    }

    public static function registrations($req) {
        $status = (string) $req->get_param('status');
        if ($status !== '' && !in_array($status, ['pending', 'approved', 'claimed', 'rejected'], true)) {
            return self::error('gwarr_bad_status', 'Unknown status.', 400);
        }
        $list = GWARR_DB::list([
            'status'   => $status,
            'search'   => sanitize_text_field((string) $req->get_param('search')),
            'per_page' => max(1, min(100, (int) ($req->get_param('per_page') ?: 50))),
            'page'     => max(1, (int) ($req->get_param('page') ?: 1)),
            'order'    => $status === 'pending' ? 'ASC' : 'DESC',
        ]);
        return self::ok([
            'rows'   => array_map([__CLASS__, 'registration_view'], $list['rows']),
            'total'  => (int) $list['total'],
            'counts' => GWARR_DB::status_counts(),
        ]);
    }

    public static function registration($req) {
        $row = self::registration_row((int) $req['id']);
        if (!$row) {
            return self::error('gwarr_not_found', 'Registration not found.', 404);
        }
        $view = self::registration_view($row);
        // What the order sheet says about this order, for checking before approving.
        $hit = method_exists('GWARR_Auto_Approve', 'lookup_cache')
            ? GWARR_Auto_Approve::lookup_cache($row->marketplace, $row->order_number)
            : null;
        $view['sheet'] = $hit ? [
            'productName'  => (string) $hit->product_name,
            'purchaseDate' => $hit->purchase_date ? (string) $hit->purchase_date : null,
            'tab'          => (string) $hit->sheet_tab,
            'row'          => (int) $hit->raw_row,
        ] : null;
        $claim = GWARR_Claims::latest_for_warranty((int) $row->id);
        $view['latestClaim'] = $claim ? ['id' => (int) $claim->id, 'status' => (string) $claim->status] : null;
        return self::ok($view);
    }

    public static function claims($req) {
        $status = (string) $req->get_param('status');
        if ($status !== '' && !in_array($status, ['submitted', 'approved', 'rejected'], true)) {
            return self::error('gwarr_bad_status', 'Unknown status.', 400);
        }
        $list = GWARR_Claims::list([
            'status'   => $status,
            'per_page' => max(1, min(100, (int) ($req->get_param('per_page') ?: 50))),
            'page'     => max(1, (int) ($req->get_param('page') ?: 1)),
        ]);
        return self::ok([
            'rows'   => array_map(function ($c) { return self::claim_view($c, false); }, $list['rows']),
            'total'  => (int) $list['total'],
            'counts' => GWARR_Claims::status_counts(),
        ]);
    }

    public static function claim($req) {
        $claim = self::claim_row((int) $req['id']);
        if (!$claim) {
            return self::error('gwarr_claim_not_found', 'Claim not found.', 404);
        }
        return self::ok(self::claim_view($claim, true));
    }

    // -------------------------------------------------------- registration acts
    // Each act checks and changes inside GWARR_Lock, so two staff, a double
    // click or a retry can never both pass the check (one gets a 409 "busy").

    public static function approve_registration($req) {
        $id   = (int) $req['id'];
        $date = self::date_param($req->get_param('purchase_date'));
        if (is_wp_error($date)) {
            return $date;
        }
        // Approval notes are internal (customers only ever see a rejection reason).
        $note = sanitize_text_field((string) $req->get_param('note'));
        if ($note === '') {
            $note = 'Approved in CP' . (self::actor($req) !== '' ? ' by ' . self::actor($req) : '');
        }
        return GWARR_Lock::run('reg_' . $id, function () use ($id, $date, $note) {
            $row = GWARR_DB::find($id);
            if (!$row) {
                return self::error('gwarr_not_found', 'Registration not found.', 404);
            }
            if ($row->status !== 'pending') {
                return self::error('gwarr_not_pending', 'Only a pending registration can be approved here (this one is ' . $row->status . ').', 409);
            }
            if (!empty($row->coupon_code)) {
                return self::error('gwarr_has_coupon', 'This registration already has coupon ' . $row->coupon_code . '. Approve it in wp-admin so no second coupon is made.', 409);
            }
            $result = GWARR_Approval::approve($id, $date, $note);
            if (is_wp_error($result)) {
                return self::fail($result, 422);
            }
            return self::ok(self::registration_view(self::registration_row($id)));
        });
    }

    public static function reject_registration($req) {
        $id = (int) $req['id'];
        // The customer reads this, in the email and on My Warranties.
        $reason = mb_substr(sanitize_text_field((string) $req->get_param('reason')), 0, 300);
        if ($reason === '') {
            return self::error('gwarr_no_reason', 'Write the reason the customer will read.', 400);
        }
        return GWARR_Lock::run('reg_' . $id, function () use ($id, $reason) {
            $row = GWARR_DB::find($id);
            if (!$row) {
                return self::error('gwarr_not_found', 'Registration not found.', 404);
            }
            if ($row->status !== 'pending') {
                return self::error('gwarr_not_pending', 'Only a pending registration can be rejected here (this one is ' . $row->status . ').', 409);
            }
            if (!empty($row->coupon_code)) {
                return self::error('gwarr_has_coupon', 'This registration already has coupon ' . $row->coupon_code . '. Handle it in wp-admin so the coupon is dealt with too.', 409);
            }
            $result = GWARR_Approval::reject($id, $reason);
            if (is_wp_error($result)) {
                return self::fail($result, 422);
            }
            return self::ok(self::registration_view(self::registration_row($id)));
        });
    }

    public static function edit_registration($req) {
        $row = GWARR_DB::find((int) $req['id']);
        if (!$row) {
            return self::error('gwarr_not_found', 'Registration not found.', 404);
        }
        $args = [];
        if ($req->has_param('marketplace'))  $args['marketplace']  = sanitize_key((string) $req->get_param('marketplace'));
        if ($req->has_param('order_number')) $args['order_number'] = sanitize_text_field((string) $req->get_param('order_number'));
        if ($req->has_param('product_text')) $args['product_text'] = sanitize_textarea_field((string) $req->get_param('product_text'));
        if ($req->has_param('notes'))        $args['notes']        = sanitize_textarea_field((string) $req->get_param('notes'));
        if ($req->has_param('purchase_date')) {
            $date = self::date_param($req->get_param('purchase_date'));
            if (is_wp_error($date)) {
                return $date;
            }
            $args['purchase_date'] = $date;
        }
        // The same Order ID rule as the registration form (v1.12.1), but only for a
        // number staff change or a move to another marketplace: rows saved before
        // the rule keep their number and can still have other fields edited.
        if (isset($args['order_number']) || isset($args['marketplace'])) {
            $mp    = $args['marketplace'] ?? (string) $row->marketplace;
            $order = isset($args['order_number'])
                ? GWARR_Marketplaces::normalise_order_number($mp, $args['order_number'])
                : (string) $row->order_number;
            // Case-insensitive like the order_number column (utf8mb4_unicode_520_ci).
            if (strcasecmp($order, (string) $row->order_number) !== 0 || $mp !== (string) $row->marketplace) {
                $problem = GWARR_Marketplaces::order_number_problem($mp, $order);
                if ($problem) {
                    return self::error('gwarr_bad_order', GWARR_Marketplaces::order_number_message($mp, $problem), 400);
                }
            }
            if (isset($args['order_number'])) {
                $args['order_number'] = $order;
            }
        }
        $result = GWARR_DB::update((int) $row->id, $args);
        if (is_wp_error($result)) {
            return self::fail($result, 422);
        }
        return self::ok(self::registration_view(self::registration_row((int) $row->id)));
    }

    // --------------------------------------------------------------- claim acts

    public static function approve_claim($req) {
        $id  = (int) $req['id'];
        $fee = self::fee_param($req->get_param('shipping_fee'), true);
        if (is_wp_error($fee)) {
            return $fee;
        }
        // The customer reads this note in the approval email.
        $note = mb_substr(sanitize_textarea_field((string) $req->get_param('note')), 0, 1000);
        return GWARR_Lock::run('claim_' . $id, function () use ($id, $fee, $note) {
            $claim = GWARR_Claims::find($id);
            if (!$claim) {
                return self::error('gwarr_claim_not_found', 'Claim not found.', 404);
            }
            if ($claim->status !== 'submitted') {
                return self::error('gwarr_claim_resolved', 'This claim was already ' . ($claim->status === 'approved' ? 'approved' : 'declined') . '.', 409);
            }
            $result = GWARR_Claims::approve($id, $note, $fee);
            if (is_wp_error($result)) {
                return self::fail($result, 422);
            }
            $warning = '';
            if ($fee > 0 && empty($result->shipping_order_id)) {
                $why     = GWARR_Claims::last_order_error();
                $warning = 'The claim is approved, but the shipping order could not be created' . ($why ? ' (' . $why . ')' : '') . ', so the email has no pay button. Use "Charge shipping" to try again.';
            }
            $sent = self::send_claim_email('approved', $id);
            return self::ok(self::claim_view(self::claim_row($id), true), $sent, $warning);
        });
    }

    public static function decline_claim($req) {
        $id = (int) $req['id'];
        // The customer reads this, in the email and on My Warranties.
        $reason = mb_substr(sanitize_textarea_field((string) $req->get_param('reason')), 0, 1000);
        if ($reason === '') {
            return self::error('gwarr_no_reason', 'Write the reason the customer will read.', 400);
        }
        return GWARR_Lock::run('claim_' . $id, function () use ($id, $reason) {
            $claim = GWARR_Claims::find($id);
            if (!$claim) {
                return self::error('gwarr_claim_not_found', 'Claim not found.', 404);
            }
            if ($claim->status !== 'submitted') {
                return self::error('gwarr_claim_resolved', 'This claim was already ' . ($claim->status === 'approved' ? 'approved' : 'declined') . '.', 409);
            }
            $result = GWARR_Claims::reject($id, $reason);
            if (is_wp_error($result)) {
                return self::fail($result, 422);
            }
            $sent = self::send_claim_email('rejected', $id);
            return self::ok(self::claim_view(self::claim_row($id), true), $sent);
        });
    }

    public static function resend_claim($req) {
        $claim = GWARR_Claims::find((int) $req['id']);
        if (!$claim || $claim->status !== 'approved') {
            return self::error('gwarr_claim_not_approved', 'Only an approved claim has an approval email to resend.', 409);
        }
        // Same self-heal as the admin Resend: the pay order needs MY billing (so
        // FPX and Touch 'n Go show) and a line item (or the pay page fails).
        if (!empty($claim->shipping_order_id) && function_exists('wc_get_order')) {
            $order = wc_get_order((int) $claim->shipping_order_id);
            if ($order) {
                GWARR_Claims::ensure_order_billing($order, (int) $claim->user_id);
                GWARR_Claims::ensure_order_line_item($order);
            }
        }
        $sent = self::send_claim_email('approved', (int) $claim->id);
        return self::ok(self::claim_view(self::claim_row((int) $claim->id), true), $sent);
    }

    public static function charge_shipping($req) {
        $id  = (int) $req['id'];
        $fee = self::fee_param($req->get_param('shipping_fee'), false);
        if (is_wp_error($fee)) {
            return $fee;
        }
        return GWARR_Lock::run('claim_' . $id, function () use ($id, $fee) {
            $claim = GWARR_Claims::find($id);
            if (!$claim || $claim->status !== 'approved') {
                return self::error('gwarr_claim_not_approved', 'Shipping can only be charged on an approved claim.', 409);
            }
            if (!empty($claim->shipping_order_id)) {
                return self::error('gwarr_has_order', 'This claim already has shipping order #' . (int) $claim->shipping_order_id . '. Use Resend to send its pay link again.', 409);
            }
            $result = GWARR_Claims::set_shipping_fee($id, $fee);
            if (is_wp_error($result)) {
                return self::fail($result, 422);
            }
            $sent = self::send_claim_email('approved', $id);
            return self::ok(self::claim_view(self::claim_row($id), true), $sent);
        });
    }

    // ---------------------------------------------------------------- helpers

    /** Sends the approved/declined email for a claim and records the result the way the admin screen does. */
    private static function send_claim_email($kind, $claim_id) {
        $fresh = GWARR_Claims::find($claim_id);
        if (!$fresh || !class_exists('GWARR_Email')) {
            return false;
        }
        $ok = $kind === 'approved'
            ? (bool) GWARR_Email::send_claim_approved($fresh)
            : (bool) GWARR_Email::send_claim_rejected($fresh);
        if ($kind === 'approved') {
            GWARR_Claims::record_email_status($claim_id, $ok);
        }
        return $ok;
    }

    private static function registration_row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT w.*, u.user_email, u.display_name FROM ' . GWARR_DB::table() . ' w LEFT JOIN ' . $wpdb->users . ' u ON u.ID = w.user_id WHERE w.id = %d',
            (int) $id
        ));
    }

    private static function claim_row($id) {
        global $wpdb;
        $w = $wpdb->prefix . GWARR_TABLE;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT c.*, u.user_email, u.display_name, w.product_text, w.marketplace, w.source, w.order_number, w.wc_order_id, w.warranty_ends, w.status AS warranty_status
             FROM ' . GWARR_Claims::table() . ' c LEFT JOIN ' . $w . ' w ON w.id = c.warranty_id LEFT JOIN ' . $wpdb->users . ' u ON u.ID = c.user_id
             WHERE c.id = %d',
            (int) $id
        ));
    }

    private static function registration_view($r) {
        $source = (string) ($r->source ?? 'marketplace');
        return [
            'id'               => (int) $r->id,
            'status'           => (string) $r->status,
            'source'           => $source,
            'marketplace'      => (string) $r->marketplace,
            'marketplaceLabel' => GWARR_Marketplaces::label((string) $r->marketplace),
            // Website rows store "{orderId}#{itemId}"; the clean order number is wc_order_id.
            'orderNumber'      => $source === 'website' && !empty($r->wc_order_id) ? (string) (int) $r->wc_order_id : (string) $r->order_number,
            'customerEmail'    => (string) (!empty($r->user_email) ? $r->user_email : ($r->billing_email ?? '')),
            'customerName'     => (string) ($r->display_name ?? ''),
            'productText'      => (string) $r->product_text,
            'notes'            => (string) ($r->notes ?? ''),
            'marketingConsent' => (bool) (int) $r->marketing_consent,
            'purchaseDate'     => $r->purchase_date ? (string) $r->purchase_date : null,
            'warrantyEnds'     => $r->warranty_ends ? (string) $r->warranty_ends : null,
            'couponCode'       => $r->coupon_code ? (string) $r->coupon_code : null,
            'adminNote'        => (string) ($r->admin_note ?? ''),
            'approvedAt'       => $r->approved_at ? (string) $r->approved_at : null,
            'claimedAt'        => $r->claimed_at ? (string) $r->claimed_at : null,
            'createdAt'        => (string) $r->created_at,
        ];
    }

    private static function claim_view($c, $full) {
        $view = [
            'id'            => (int) $c->id,
            'status'        => (string) $c->status,
            'warrantyId'    => (int) $c->warranty_id,
            'customerEmail' => (string) ($c->user_email ?? ''),
            'customerName'  => (string) ($c->display_name ?? ''),
            'itemLabel'     => (string) ($c->item_label ?? ''),
            'productText'   => (string) ($c->product_text ?? ''),
            'marketplace'   => GWARR_Marketplaces::label((string) ($c->marketplace ?? '')),
            'orderNumber'   => ($c->source ?? '') === 'website' && !empty($c->wc_order_id) ? (string) (int) $c->wc_order_id : (string) ($c->order_number ?? ''),
            'warrantyEnds'  => !empty($c->warranty_ends) ? (string) $c->warranty_ends : null,
            'issue'         => (string) $c->issue_description,
            'adminNote'     => (string) ($c->admin_note ?? ''),
            'shippingFee'   => $c->shipping_fee !== null ? (float) $c->shipping_fee : null,
            'resolvedAt'    => !empty($c->resolved_at) ? (string) $c->resolved_at : null,
            'createdAt'     => (string) $c->created_at,
            'mediaCount'    => count(GWARR_Claims::media_ids($c)),
        ];
        if (!$full) {
            return $view;
        }
        $view['delivery'] = [
            'name'     => (string) ($c->delivery_name ?? ''),
            'phone'    => (string) ($c->delivery_phone ?? ''),
            'address1' => (string) ($c->delivery_address_1 ?? ''),
            'address2' => (string) ($c->delivery_address_2 ?? ''),
            'city'     => (string) ($c->delivery_city ?? ''),
            'state'    => function_exists('gwarr_state_label') ? gwarr_state_label((string) ($c->delivery_state ?? '')) : (string) ($c->delivery_state ?? ''),
            'postcode' => (string) ($c->delivery_postcode ?? ''),
        ];
        $view['media'] = [];
        foreach (GWARR_Claims::media_ids($c) as $mid) {
            $url = wp_get_attachment_url($mid);
            if (!$url) continue;
            $view['media'][] = [
                'id'    => (int) $mid,
                'mime'  => (string) get_post_mime_type($mid),
                'url'   => (string) $url,
                'thumb' => (string) (wp_get_attachment_image_url($mid, 'medium') ?: ''),
            ];
        }
        $view['shippingOrder'] = null;
        if (!empty($c->shipping_order_id) && function_exists('wc_get_order')) {
            $order = wc_get_order((int) $c->shipping_order_id);
            if ($order) {
                $view['shippingOrder'] = [
                    'id'     => (int) $order->get_id(),
                    'status' => (string) $order->get_status(),
                    'paid'   => (bool) $order->is_paid(),
                ];
            }
        }
        $log = get_option('gwarr_claim_email_' . (int) $c->id, []);
        $view['approvalEmail'] = is_array($log) && isset($log['ok']) ? ['at' => (string) $log['at'], 'ok' => (bool) $log['ok']] : null;
        return $view;
    }

    /** YYYY-MM-DD, a real date, not in the future. */
    private static function date_param($raw) {
        $raw = (string) $raw;
        $d   = DateTime::createFromFormat('!Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) {
            return self::error('gwarr_bad_date', 'Give the purchase date as YYYY-MM-DD.', 400);
        }
        if ($raw > current_time('Y-m-d')) {
            return self::error('gwarr_future_date', 'The purchase date cannot be in the future.', 400);
        }
        return $raw;
    }

    /** A fee in RM between 0.01 and 500. Optional: blank or exactly zero means no fee; anything else must be a real amount. */
    private static function fee_param($raw, $optional) {
        if ($optional && ($raw === null || $raw === '' || (is_numeric($raw) && (float) $raw == 0))) {
            return 0.0;
        }
        $fee = is_numeric($raw) ? round((float) $raw, 2) : 0.0;
        if ($fee < 0.01 || $fee > 500) {
            return self::error('gwarr_bad_fee', 'The shipping fee must be between RM 0.01 and RM 500.', 400);
        }
        return $fee;
    }

    private static function actor($req) {
        return mb_substr(sanitize_text_field((string) $req->get_header('x_gwarr_actor')), 0, 80);
    }

    private static function ok($data, $email_sent = null, $warning = '') {
        $body = ['ok' => true, 'data' => $data];
        if ($email_sent !== null) $body['emailSent'] = (bool) $email_sent;
        if ($warning !== '') $body['warning'] = $warning;
        $response = new WP_REST_Response($body, 200);
        // Customer names, emails and addresses: never kept by a cache on the way.
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    /** A WP_Error from the plugin, keeping its own HTTP status when it has one. */
    private static function fail($e, $status) {
        $data = $e->get_error_data();
        return self::error($e->get_error_code(), $e->get_error_message(), is_array($data) && isset($data['status']) ? (int) $data['status'] : $status);
    }

    private static function error($code, $message, $status) {
        return new WP_Error($code, $message, ['status' => (int) $status]);
    }
}
