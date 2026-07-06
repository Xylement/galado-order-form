<?php
/**
 * Repository for warranty registrations.
 *
 * Wraps $wpdb so the rest of the plugin never builds SQL by hand and
 * every parameter goes through prepare().
 */

if (!defined('ABSPATH')) exit;

class GWARR_DB {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . GWARR_TABLE;
    }

    /**
     * Insert a new registration. Returns the row ID on success, or a WP_Error
     * with structured `data` describing the conflict on duplicates.
     *
     * Conflict semantics:
     *   - Same user + previous record was rejected → allow retry (UPDATE in
     *     place, reset to pending). Lets a customer fix a typo without
     *     emailing support.
     *   - Same user + previous record is pending/approved → block with
     *     code 'gwarr_duplicate' and data['same_user'] = true.
     *   - Different user → block with code 'gwarr_duplicate' and
     *     data['same_user'] = false. The caller should alert admins
     *     (could be a confused customer with two accounts, or fraud).
     */
    public static function insert($args) {
        global $wpdb;

        $row = wp_parse_args($args, [
            'user_id'           => 0,
            'marketplace'       => '',
            'order_number'      => '',
            'product_text'      => '',
            'notes'             => '',
            'marketing_consent' => 1,
            'status'            => 'pending',
        ]);

        $existing = self::find_by_order($row['marketplace'], $row['order_number']);
        if ($existing) {
            $same_user = (int) $existing->user_id === (int) $row['user_id'];

            // Same user wants to retry after a rejection — overwrite in place.
            if ($same_user && $existing->status === 'rejected') {
                $ok = $wpdb->update(
                    self::table(),
                    [
                        'notes'             => (string) $row['notes'],
                        'marketing_consent' => $row['marketing_consent'] ? 1 : 0,
                        'status'            => 'pending',
                        'admin_note'        => null,
                        'approved_by'       => null,
                        'approved_at'       => null,
                    ],
                    ['id' => (int) $existing->id],
                    ['%s', '%d', '%s', '%s', '%d', '%s'],
                    ['%d']
                );
                if ($ok === false) {
                    return new WP_Error('gwarr_update_failed', $wpdb->last_error ?: 'Database update failed.');
                }
                return (int) $existing->id;
            }

            // Anything else — block, but carry context so the caller can branch.
            return new WP_Error(
                'gwarr_duplicate',
                'This order number is already registered.',
                [
                    'existing'  => $existing,
                    'same_user' => $same_user,
                ]
            );
        }

        $ok = $wpdb->insert(
            self::table(),
            [
                'user_id'           => (int) $row['user_id'],
                'marketplace'       => (string) $row['marketplace'],
                'order_number'      => (string) $row['order_number'],
                'product_text'      => (string) $row['product_text'],
                'notes'             => (string) $row['notes'],
                'marketing_consent' => $row['marketing_consent'] ? 1 : 0,
                'status'            => (string) $row['status'],
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        if ($ok === false) {
            return new WP_Error('gwarr_insert_failed', $wpdb->last_error ?: 'Database insert failed.');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Insert (or no-op if it already exists) a per-component warranty row for a
     * WooCommerce order line item. A line item yields one row for the product
     * plus one row per paid add-on product (e.g. a "Mini Wrist Strap (RM59)"
     * sold via Product Add-Ons), so each is claimable independently.
     *
     * Idempotency is keyed on the full order_number ("{orderId}#{itemId}" for
     * the base product, "{orderId}#{itemId}#a1" for add-ons) which the existing
     * (marketplace, order_number) unique key enforces — so re-firing the hook
     * or re-running the backfill never duplicates. When a row already exists its
     * product_text is refreshed (in case the capture logic improved) but its
     * status / dates / any claim are left untouched. Rows are created already-
     * active ('approved') — a paid Woo order is self-verifying.
     *
     * @param array $args user_id, wc_order_id, wc_item_id, product_text,
     *                    purchase_date, warranty_ends, and optional 'suffix'
     *                    (e.g. 'a1') that distinguishes an add-on row.
     * @return int|false New row id on insert, 0 if it already existed
     *                   (idempotent no-op), or false on failure.
     */
    public static function insert_website_item($args) {
        global $wpdb;

        $row = wp_parse_args($args, [
            'user_id'       => 0,
            'wc_order_id'   => 0,
            'wc_item_id'    => 0,
            'suffix'        => '',
            'billing_email' => '',
            'product_text'  => '',
            'purchase_date' => null,
            'warranty_ends' => null,
        ]);

        $wc_item_id = (int) $row['wc_item_id'];
        if ($wc_item_id <= 0) {
            return false;
        }

        $email = strtolower(trim((string) $row['billing_email']));

        // wc_order_id holds the clean number; order_number is the unique key.
        $order_number = (int) $row['wc_order_id'] . '#' . $wc_item_id;
        $suffix       = preg_replace('/[^a-z0-9]/i', '', (string) $row['suffix']);
        if ($suffix !== '') {
            $order_number .= '#' . $suffix;
        }

        $existing = self::find_by_order('website', $order_number);
        if ($existing) {
            // Refresh label / link to an account, but never disturb status /
            // dates / claims. Only ever fill in a real owner (never blank one).
            $set = [];
            $fmt = [];
            if ((string) $existing->product_text !== (string) $row['product_text']) {
                $set['product_text'] = (string) $row['product_text']; $fmt[] = '%s';
            }
            if ((int) $row['user_id'] > 0 && (int) $existing->user_id !== (int) $row['user_id']) {
                $set['user_id'] = (int) $row['user_id']; $fmt[] = '%d';
            }
            if ($email !== '' && (string) $existing->billing_email !== $email) {
                $set['billing_email'] = $email; $fmt[] = '%s';
            }
            if ($set) {
                $wpdb->update(self::table(), $set, ['id' => (int) $existing->id], $fmt, ['%d']);
            }
            return 0; // already captured — not counted as new
        }

        $ok = $wpdb->insert(
            self::table(),
            [
                'user_id'           => (int) $row['user_id'],
                'source'            => 'website',
                'marketplace'       => 'website',
                'order_number'      => $order_number,
                'wc_order_id'       => (int) $row['wc_order_id'],
                'wc_item_id'        => $wc_item_id,
                'billing_email'     => $email ?: null,
                'product_text'      => (string) $row['product_text'],
                'marketing_consent' => 0,
                'status'            => 'approved',
                'purchase_date'     => $row['purchase_date'] ?: null,
                'warranty_ends'     => $row['warranty_ends'] ?: null,
                'approved_at'       => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($ok === false) {
            return false;
        }
        return (int) $wpdb->insert_id;
    }

    public static function find_by_wc_item($wc_item_id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE wc_item_id = %d LIMIT 1',
                (int) $wc_item_id
            )
        );
    }

    /**
     * Attach orphaned guest-checkout website warranties (user_id = 0) to a
     * customer account by matching the billing email. Called when a customer
     * logs in or registers, so an order placed as a guest surfaces in their
     * My Warranties once they have an account.
     *
     * @return int rows linked
     */
    public static function link_website_orphans($email, $user_id) {
        global $wpdb;
        $email   = strtolower(trim((string) $email));
        $user_id = (int) $user_id;
        if ($email === '' || $user_id <= 0) {
            return 0;
        }
        return (int) $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table() . " SET user_id = %d
                 WHERE source = 'website' AND user_id = 0 AND billing_email = %s",
                $user_id,
                $email
            )
        );
    }

    /**
     * Mark an approved warranty as claimed (greys out in customer + admin
     * views). Reversible via unclaim().
     */
    public static function mark_claimed($id) {
        global $wpdb;
        $ok = $wpdb->update(
            self::table(),
            ['status' => 'claimed', 'claimed_at' => current_time('mysql')],
            ['id' => (int) $id],
            ['%s', '%s'],
            ['%d']
        );
        if ($ok === false) {
            return new WP_Error('gwarr_update_failed', $wpdb->last_error ?: 'Database update failed.');
        }
        return self::find($id);
    }

    /**
     * Revert a claimed warranty back to approved/active.
     */
    public static function unclaim($id) {
        global $wpdb;
        $ok = $wpdb->update(
            self::table(),
            ['status' => 'approved', 'claimed_at' => null],
            ['id' => (int) $id],
            ['%s', '%s'],
            ['%d']
        );
        if ($ok === false) {
            return new WP_Error('gwarr_update_failed', $wpdb->last_error ?: 'Database update failed.');
        }
        return self::find($id);
    }

    public static function find($id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id)
        );
    }

    public static function find_by_order($marketplace, $order_number) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE marketplace = %s AND order_number = %s LIMIT 1',
                (string) $marketplace,
                (string) $order_number
            )
        );
    }

    /**
     * All registrations belonging to a single customer, newest first.
     */
    public static function for_user($user_id) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created_at DESC',
                (int) $user_id
            )
        );
    }

    /**
     * Paginated list for the admin screen. $args supports:
     *   status, marketplace, search (matches user_email/order_number/product_text),
     *   per_page, page, orderby, order.
     *
     * Returns ['rows' => array, 'total' => int].
     */
    public static function list($args = []) {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'      => '',
            'marketplace' => '',
            'search'      => '',
            'per_page'    => 20,
            'page'        => 1,
            'orderby'     => 'created_at',
            'order'       => 'DESC',
        ]);

        $where  = ['1=1'];
        $params = [];

        if ($args['status'] !== '') {
            $where[]  = 'w.status = %s';
            $params[] = $args['status'];
        }
        if ($args['marketplace'] !== '') {
            $where[]  = 'w.marketplace = %s';
            $params[] = $args['marketplace'];
        }
        if ($args['search'] !== '') {
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[]  = '(w.order_number LIKE %s OR w.product_text LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $allowed_orderby = ['created_at', 'updated_at', 'status', 'marketplace'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order   = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = max(1, min(200, (int) $args['per_page']));
        $page     = max(1, (int) $args['page']);
        $offset   = ($page - 1) * $per_page;

        $base = 'FROM ' . self::table() . ' w LEFT JOIN ' . $wpdb->users . ' u ON u.ID = w.user_id WHERE ' . implode(' AND ', $where);

        $total_sql = 'SELECT COUNT(*) ' . $base;
        $rows_sql  = "SELECT w.*, u.user_email, u.display_name {$base} ORDER BY w.{$orderby} {$order} LIMIT %d OFFSET %d";

        if (!empty($params)) {
            $total = (int) $wpdb->get_var($wpdb->prepare($total_sql, ...$params));
            $rows  = $wpdb->get_results($wpdb->prepare($rows_sql, ...array_merge($params, [$per_page, $offset])));
        } else {
            $total = (int) $wpdb->get_var($total_sql);
            $rows  = $wpdb->get_results($wpdb->prepare($rows_sql, $per_page, $offset));
        }

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    /**
     * Approve a registration. Caller passes the verified purchase date —
     * warranty_ends is computed from it + configured warranty period.
     */
    public static function approve($id, $purchase_date, $coupon_code, $admin_note = '') {
        global $wpdb;

        // Tier-aware coverage length — Black Club members get 12 months, others 6.
        // Fetched fresh per approval so we always reflect the customer's current tier.
        $existing = self::find($id);
        $months   = gwarr_months_for_row($existing);

        $ts = strtotime($purchase_date);
        if ($ts === false) {
            return new WP_Error('gwarr_bad_date', 'Invalid purchase date.');
        }

        $purchase = gmdate('Y-m-d', $ts);
        $ends     = gmdate('Y-m-d', strtotime('+' . $months . ' months', $ts));

        $ok = $wpdb->update(
            self::table(),
            [
                'status'        => 'approved',
                'purchase_date' => $purchase,
                'warranty_ends' => $ends,
                'coupon_code'   => $coupon_code,
                'admin_note'    => $admin_note,
                'approved_by'   => get_current_user_id() ?: null,
                'approved_at'   => current_time('mysql'),
            ],
            ['id' => (int) $id],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($ok === false) {
            return new WP_Error('gwarr_update_failed', $wpdb->last_error ?: 'Database update failed.');
        }

        return self::find($id);
    }

    public static function reject($id, $reason) {
        global $wpdb;

        $ok = $wpdb->update(
            self::table(),
            [
                'status'      => 'rejected',
                'admin_note'  => (string) $reason,
                'approved_by' => get_current_user_id() ?: null,
                'approved_at' => current_time('mysql'),
            ],
            ['id' => (int) $id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($ok === false) {
            return new WP_Error('gwarr_update_failed', $wpdb->last_error ?: 'Database update failed.');
        }

        return self::find($id);
    }

    /**
     * Edit a registration's editable fields from the admin UI. Whitelisted
     * fields only — status, coupon_code, approved_by, etc. stay under the
     * control of the approve/reject lifecycle.
     *
     * If purchase_date changes on an approved row, warranty_ends is auto
     * recomputed from the configured warranty period.
     */
    public static function update($id, $args) {
        global $wpdb;

        $existing = self::find($id);
        if (!$existing) {
            return new WP_Error('gwarr_not_found', 'Registration not found.');
        }

        $editable = [];
        $formats  = [];

        if (array_key_exists('marketplace', $args)) {
            if (!GWARR_Marketplaces::is_valid($args['marketplace'])) {
                return new WP_Error('gwarr_bad_marketplace', 'Unknown marketplace.');
            }
            $editable['marketplace'] = $args['marketplace'];
            $formats[] = '%s';
        }
        if (array_key_exists('order_number', $args)) {
            $order = trim((string) $args['order_number']);
            if ($order === '') {
                return new WP_Error('gwarr_bad_order', 'Order number cannot be empty.');
            }
            if (strlen($order) > 64) {
                return new WP_Error('gwarr_bad_order', 'Order number is too long.');
            }
            // Guard against duplicating into another existing (marketplace, order_number)
            $mp = $editable['marketplace'] ?? $existing->marketplace;
            $clash = self::find_by_order($mp, $order);
            if ($clash && (int) $clash->id !== (int) $existing->id) {
                return new WP_Error('gwarr_duplicate', 'Another registration already uses that marketplace + order number.');
            }
            $editable['order_number'] = $order;
            $formats[] = '%s';
        }
        if (array_key_exists('product_text', $args)) {
            $editable['product_text'] = (string) $args['product_text'];
            $formats[] = '%s';
        }
        if (array_key_exists('notes', $args)) {
            $editable['notes'] = (string) $args['notes'];
            $formats[] = '%s';
        }
        if (array_key_exists('admin_note', $args)) {
            $editable['admin_note'] = (string) $args['admin_note'];
            $formats[] = '%s';
        }

        // purchase_date only meaningful once the row is approved. If supplied,
        // also recompute warranty_ends so the customer-facing dates stay consistent.
        if (array_key_exists('purchase_date', $args) && $args['purchase_date'] !== '') {
            $ts = strtotime((string) $args['purchase_date']);
            if ($ts === false) {
                return new WP_Error('gwarr_bad_date', 'Invalid purchase date.');
            }
            $editable['purchase_date'] = gmdate('Y-m-d', $ts);
            $formats[] = '%s';

            if ($existing->status === 'approved') {
                // Tier-aware coverage (Black Club = 12, others = configured standard).
                $months = gwarr_months_for_row($existing);
                $editable['warranty_ends'] = gmdate('Y-m-d', strtotime('+' . $months . ' months', $ts));
                $formats[] = '%s';
            }
        }

        if (empty($editable)) {
            return $existing; // nothing actually changed
        }

        $ok = $wpdb->update(self::table(), $editable, ['id' => (int) $id], $formats, ['%d']);
        if ($ok === false) {
            return new WP_Error('gwarr_update_failed', $wpdb->last_error ?: 'Database update failed.');
        }

        return self::find($id);
    }

    /**
     * Hard-delete a registration. The associated WC coupon (if any) is NOT
     * touched — the admin can clear it manually from WooCommerce → Coupons
     * if they want. Caller should confirm this in the UI.
     */
    public static function delete($id) {
        global $wpdb;
        $ok = $wpdb->delete(self::table(), ['id' => (int) $id], ['%d']);
        if ($ok === false) {
            return new WP_Error('gwarr_delete_failed', $wpdb->last_error ?: 'Database delete failed.');
        }
        return $ok > 0;
    }

    /**
     * Approved registrations that carry a welcome-coupon code, paginated by id
     * (for the "create missing coupons" repair). The set is stable so offset
     * paging is safe.
     */
    public static function coupon_rows($limit, $offset) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, user_id, marketplace, order_number, coupon_code, created_at FROM ' . self::table()
            . " WHERE status = 'approved' AND coupon_code IS NOT NULL AND coupon_code <> '' ORDER BY id ASC LIMIT %d OFFSET %d",
            (int) $limit,
            (int) $offset
        ));
    }

    public static function coupon_rows_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'approved' AND coupon_code IS NOT NULL AND coupon_code <> ''"
        );
    }

    /**
     * Status counts for the admin filter chips.
     */
    public static function status_counts() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A);
        $out  = ['pending' => 0, 'approved' => 0, 'claimed' => 0, 'rejected' => 0, 'all' => 0];
        foreach ((array) $rows as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all']      += (int) $r['n'];
        }
        return $out;
    }
}
