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

        $settings = get_option('gwarr_settings', []);
        $months   = max(1, (int) ($settings['warranty_months'] ?? 6));

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
                $settings = get_option('gwarr_settings', []);
                $months   = max(1, (int) ($settings['warranty_months'] ?? 6));
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
     * Status counts for the admin filter chips.
     */
    public static function status_counts() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A);
        $out  = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach ((array) $rows as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all']      += (int) $r['n'];
        }
        return $out;
    }
}
