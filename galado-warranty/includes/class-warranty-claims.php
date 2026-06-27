<?php
/**
 * Repository + lifecycle for warranty claims.
 *
 * A claim is a customer report against one warranty row (one item). It carries
 * an issue description and a set of uploaded media (WP attachment ids). When an
 * admin approves a claim, the underlying warranty is flipped to 'claimed' (greys
 * out for the customer); rejecting leaves the warranty active so they can re-file.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Claims {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'galado_warranty_claims';
    }

    /**
     * Create a claim. media_ids is an array of WP attachment ids.
     * @return int|WP_Error new claim id
     */
    public static function insert($args) {
        global $wpdb;

        $row = wp_parse_args($args, [
            'warranty_id'       => 0,
            'user_id'           => 0,
            'item_label'        => '',
            'issue_description' => '',
            'media_ids'         => [],
        ]);

        if ((int) $row['warranty_id'] <= 0 || (int) $row['user_id'] <= 0) {
            return new WP_Error('gwarr_claim_bad', 'Missing warranty or user.');
        }
        if (trim((string) $row['issue_description']) === '') {
            return new WP_Error('gwarr_claim_no_issue', 'Please describe the issue.');
        }

        $media = array_values(array_filter(array_map('absint', (array) $row['media_ids'])));

        $ok = $wpdb->insert(
            self::table(),
            [
                'warranty_id'       => (int) $row['warranty_id'],
                'user_id'           => (int) $row['user_id'],
                'item_label'        => (string) $row['item_label'] ?: null,
                'issue_description' => (string) $row['issue_description'],
                'media_ids'         => wp_json_encode($media),
                'status'            => 'submitted',
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($ok === false) {
            return new WP_Error('gwarr_claim_insert_failed', $wpdb->last_error ?: 'Database insert failed.');
        }
        return (int) $wpdb->insert_id;
    }

    public static function find($id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id)
        );
    }

    /**
     * The most recent claim for a warranty (any status), or null.
     */
    public static function latest_for_warranty($warranty_id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE warranty_id = %d ORDER BY id DESC LIMIT 1',
                (int) $warranty_id
            )
        );
    }

    /**
     * Whether a warranty already has a claim awaiting review (blocks re-filing).
     */
    public static function has_open_claim($warranty_id) {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table() . " WHERE warranty_id = %d AND status = 'submitted'",
                (int) $warranty_id
            )
        ) > 0;
    }

    /**
     * Open-claim map keyed by warranty_id for a user, so the My Warranties
     * render can decorate cards without an N+1 query.
     * @return array<int,object> warranty_id => latest claim row
     */
    public static function map_for_user($user_id) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.* FROM ' . self::table() . ' c
                 INNER JOIN (SELECT warranty_id, MAX(id) AS max_id FROM ' . self::table() . '
                             WHERE user_id = %d GROUP BY warranty_id) latest
                 ON c.id = latest.max_id',
                (int) $user_id
            )
        );
        $map = [];
        foreach ((array) $rows as $r) {
            $map[(int) $r->warranty_id] = $r;
        }
        return $map;
    }

    /**
     * Paginated admin list with the customer + warranty joined in.
     * @return array{rows:array,total:int}
     */
    public static function list($args = []) {
        global $wpdb;
        $args = wp_parse_args($args, ['status' => '', 'per_page' => 20, 'page' => 1]);

        $where  = ['1=1'];
        $params = [];
        if ($args['status'] !== '') {
            $where[]  = 'c.status = %s';
            $params[] = $args['status'];
        }

        $per_page = max(1, min(200, (int) $args['per_page']));
        $offset   = (max(1, (int) $args['page']) - 1) * $per_page;

        $w_table = $wpdb->prefix . GWARR_TABLE;
        $base = 'FROM ' . self::table() . ' c
                 LEFT JOIN ' . $w_table . ' w ON w.id = c.warranty_id
                 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = c.user_id
                 WHERE ' . implode(' AND ', $where);

        $rows_sql = "SELECT c.*, u.user_email, u.display_name,
                     w.product_text, w.marketplace, w.source, w.order_number, w.wc_order_id, w.warranty_ends, w.status AS warranty_status
                     {$base} ORDER BY c.id DESC LIMIT %d OFFSET %d";

        if (!empty($params)) {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) ' . $base, ...$params));
            $rows  = $wpdb->get_results($wpdb->prepare($rows_sql, ...array_merge($params, [$per_page, $offset])));
        } else {
            $total = (int) $wpdb->get_var('SELECT COUNT(*) ' . $base);
            $rows  = $wpdb->get_results($wpdb->prepare($rows_sql, $per_page, $offset));
        }
        return ['rows' => $rows ?: [], 'total' => $total];
    }

    public static function status_counts() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A);
        $out  = ['submitted' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach ((array) $rows as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all']      += (int) $r['n'];
        }
        return $out;
    }

    /**
     * Approve a claim → flips the underlying warranty to 'claimed'. When a
     * replacement-shipping fee is supplied (> 0), a pending WooCommerce order
     * for that amount is created under the customer's account so they can pay
     * online (WooCommerce verifies the payment); the order id is stored on the
     * claim and the approval email links to it.
     */
    public static function approve($id, $admin_note = '', $shipping_fee = 0) {
        global $wpdb;

        $claim = self::find($id);
        if (!$claim) {
            return new WP_Error('gwarr_claim_not_found', 'Claim not found.');
        }
        $updated = self::set_status($id, 'approved', $admin_note);
        if (is_wp_error($updated)) {
            return $updated;
        }
        if (class_exists('GWARR_DB')) {
            GWARR_DB::mark_claimed((int) $claim->warranty_id);
        }

        $fee = round((float) $shipping_fee, 2);
        if ($fee > 0) {
            $order_id = self::create_shipping_order($claim, $fee);
            $wpdb->update(
                self::table(),
                ['shipping_fee' => $fee, 'shipping_order_id' => $order_id ?: null],
                ['id' => (int) $id],
                ['%f', '%d'],
                ['%d']
            );
            $updated = self::find($id);
        }

        return $updated;
    }

    /**
     * Attach (or re-use) a replacement-shipping fee + WooCommerce pay order to
     * an already-approved claim — for when the fee was missed at approval, or
     * the claim was approved before the pay flow existed. Creates the order
     * only if one isn't already linked.
     *
     * @return object|WP_Error the refreshed claim row
     */
    public static function set_shipping_fee($id, $fee) {
        global $wpdb;
        $fee   = round((float) $fee, 2);
        $claim = self::find($id);
        if (!$claim) {
            return new WP_Error('gwarr_claim_not_found', 'Claim not found.');
        }
        if ($fee <= 0) {
            return new WP_Error('gwarr_bad_fee', 'Please enter a shipping fee greater than 0.');
        }

        $order_id = !empty($claim->shipping_order_id)
            ? (int) $claim->shipping_order_id
            : self::create_shipping_order($claim, $fee);

        if (!$order_id) {
            $why = self::last_order_error();
            return new WP_Error('gwarr_order_failed',
                'Could not create the WooCommerce shipping order' . ($why ? ': ' . $why : ' — check the error log.'));
        }

        $wpdb->update(
            self::table(),
            ['shipping_fee' => $fee, 'shipping_order_id' => $order_id],
            ['id' => (int) $id],
            ['%f', '%d'],
            ['%d']
        );
        return self::find($id);
    }

    /**
     * Create a pending WooCommerce order for a warranty-claim shipping fee,
     * assigned to the customer. Returns the order id, or 0 on failure (the
     * approval still succeeds — the email just falls back to "we'll send a
     * payment link"). Tax is disabled so the customer pays exactly the fee.
     */
    /** Last shipping-order failure reason, surfaced by set_shipping_fee(). */
    private static $last_order_error = '';

    public static function last_order_error() {
        return self::$last_order_error;
    }

    private static function create_shipping_order($claim, $fee) {
        self::$last_order_error = '';
        if (!function_exists('wc_create_order')) {
            self::$last_order_error = 'WooCommerce is not active.';
            return 0;
        }
        if (!class_exists('WC_Order_Item_Fee')) {
            self::$last_order_error = 'WC_Order_Item_Fee class unavailable.';
            return 0;
        }
        try {
            // Order-save hooks (and WC internals) often reach for WC()->session,
            // ->cart or ->customer, which are null on a wp-admin request →
            // "Call to a member function get() on null". Initialise them first.
            if (function_exists('WC')) {
                if (null === WC()->session && class_exists('WC_Session_Handler')) {
                    WC()->session = new WC_Session_Handler();
                    WC()->session->init();
                }
                if (null === WC()->customer) {
                    WC()->customer = new WC_Customer((int) $claim->user_id, true);
                }
                if (null === WC()->cart && class_exists('WC_Cart')) {
                    WC()->cart = new WC_Cart();
                }
            }

            $user  = get_userdata((int) $claim->user_id);
            $label = !empty($claim->item_label) ? (string) $claim->item_label : 'warranty replacement';

            $order = wc_create_order(['customer_id' => (int) $claim->user_id]);
            if (is_wp_error($order)) {
                self::$last_order_error = $order->get_error_message();
                error_log('[galado-warranty] wc_create_order failed for claim ' . (int) $claim->id . ': ' . $order->get_error_message());
                return 0;
            }

            $fee_item = new WC_Order_Item_Fee();
            $fee_item->set_name('Replacement shipping — ' . $label);
            $fee_item->set_amount((string) $fee);
            $fee_item->set_total((string) $fee);
            $fee_item->set_tax_status('none');
            $fee_item->set_total_tax(0);
            $order->add_item($fee_item);

            if ($user && $user->user_email) {
                $order->set_billing_email($user->user_email);
            }
            $order->set_created_via('galado-warranty');
            $order->add_order_note('Auto-created for warranty claim #' . (int) $claim->id . ' (replacement shipping fee).');

            // Set the total directly instead of calculate_totals(): the fee is a
            // fixed, tax-free amount, and calculate_totals() runs WooCommerce's
            // tax/session/customer machinery which is null on an admin request
            // ("Call to a member function get() on null").
            $order->set_cart_tax(0);
            $order->set_total((float) $fee);
            $order->update_status('pending', 'Awaiting warranty-replacement shipping payment.');
            $order->save();

            $oid = (int) $order->get_id();
            if ($oid <= 0) {
                self::$last_order_error = 'Order saved but no ID was returned.';
                return 0;
            }
            return $oid;
        } catch (\Throwable $e) {
            // Pinpoint the exact failing call so we can see whether it's WC core
            // or a third-party hook (and which one), not just the message.
            self::$last_order_error = $e->getMessage()
                . ' [' . get_class($e) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            error_log('[galado-warranty] shipping order for claim ' . (int) $claim->id
                . ' failed (v' . (defined('GWARR_VERSION') ? GWARR_VERSION : '?') . '): '
                . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
                . "\n" . $e->getTraceAsString());
            return 0;
        }
    }

    public static function reject($id, $admin_note = '') {
        return self::set_status($id, 'rejected', $admin_note);
    }

    private static function set_status($id, $status, $admin_note) {
        global $wpdb;
        $ok = $wpdb->update(
            self::table(),
            [
                'status'      => $status,
                'admin_note'  => (string) $admin_note,
                'resolved_by' => get_current_user_id() ?: null,
                'resolved_at' => current_time('mysql'),
            ],
            ['id' => (int) $id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );
        if ($ok === false) {
            return new WP_Error('gwarr_claim_update_failed', $wpdb->last_error ?: 'Database update failed.');
        }
        return self::find($id);
    }

    /**
     * Decode media_ids JSON to an array of attachment ids.
     */
    public static function media_ids($claim) {
        if (!$claim || empty($claim->media_ids)) {
            return [];
        }
        $ids = json_decode($claim->media_ids, true);
        return is_array($ids) ? array_values(array_filter(array_map('absint', $ids))) : [];
    }
}
