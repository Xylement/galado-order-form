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
                'issue_description' => (string) $row['issue_description'],
                'media_ids'         => wp_json_encode($media),
                'status'            => 'submitted',
            ],
            ['%d', '%d', '%s', '%s', '%s']
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
     * Approve a claim → flips the underlying warranty to 'claimed'.
     */
    public static function approve($id, $admin_note = '') {
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
        return $updated;
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
