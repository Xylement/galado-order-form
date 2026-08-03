<?php
/**
 * Persistence for abandoned carts (spec FR-5, FR-6).
 *
 * One row per (email, cart_hash). Repeat captures update in place. Raw
 * recovery tokens are never stored, only their SHA-256 hash. Rows are
 * hard-deleted 90 days after their last activity by a daily cron.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_DB {

    const STATUS_OPEN      = 'open';
    const STATUS_RECOVERED = 'recovered';
    const STATUS_PURCHASED = 'purchased';
    const STATUS_EXPIRED   = 'expired';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'galado_abandoned_carts';
    }

    /**
     * dbDelta schema. Deviation from spec: status is VARCHAR(10) rather than
     * ENUM because dbDelta handles ENUM unreliably; the value set is enforced
     * by the STATUS_* constants above.
     */
    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL DEFAULT '',
  session_key varchar(64) NOT NULL DEFAULT '',
  cart_contents longtext NULL,
  cart_hash char(40) NOT NULL DEFAULT '',
  cart_total decimal(12,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'MYR',
  recovery_token_hash char(64) NOT NULL DEFAULT '',
  token_expires_at datetime NULL,
  status varchar(10) NOT NULL DEFAULT 'open',
  consent tinyint(1) NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email_cart (email,cart_hash),
  KEY session_key (session_key),
  KEY cart_hash (cart_hash),
  KEY status (status)
) {$charset};");
    }

    /**
     * Normalised cart hash (spec FR-2/FR-5): stable across page loads for the
     * same line items, independent of price or coupon changes.
     */
    public static function cart_hash($cart) {
        $lines = [];
        foreach ($cart->get_cart() as $item) {
            $lines[] = [(int) $item['product_id'], (int) ($item['variation_id'] ?? 0), (int) $item['quantity']];
        }
        usort($lines, function ($a, $b) {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });
        return sha1(wp_json_encode($lines));
    }

    /**
     * Insert or update the row for (email, cart_hash). Returns the row id.
     * A re-capture always re-opens the row: a fresh capture is fresh intent,
     * even if an earlier identical cart was purchased or expired.
     */
    public static function upsert($email, $session_key, $cart_contents_json, $cart_hash, $total, $currency, $consent, $source) {
        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
               (email, session_key, cart_contents, cart_hash, cart_total, currency, status, consent, source, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %f, %s, %s, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
               session_key = VALUES(session_key),
               cart_contents = VALUES(cart_contents),
               cart_total = VALUES(cart_total),
               currency = VALUES(currency),
               status = VALUES(status),
               consent = GREATEST(consent, VALUES(consent)),
               source = VALUES(source),
               updated_at = VALUES(updated_at),
               id = LAST_INSERT_ID(id)",
            $email, $session_key, $cart_contents_json, $cart_hash, $total, $currency,
            self::STATUS_OPEN, $consent ? 1 : 0, $source, $now, $now
        ));

        return (int) $wpdb->insert_id;
    }

    public static function get($id) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    public static function get_by_token_hash($token_hash) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE recovery_token_hash = %s LIMIT 1", $token_hash
        ));
    }

    /** Store a freshly minted token's hash + expiry against a row. */
    public static function set_token($id, $token_hash, $expires_at_gmt) {
        global $wpdb;
        $table = self::table();
        $wpdb->update(
            $table,
            ['recovery_token_hash' => $token_hash, 'token_expires_at' => $expires_at_gmt, 'updated_at' => current_time('mysql', true)],
            ['id' => (int) $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public static function set_status($id, $status) {
        global $wpdb;
        $table = self::table();
        $wpdb->update(
            $table,
            ['status' => $status, 'updated_at' => current_time('mysql', true)],
            ['id' => (int) $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /** Re-attach a row to the visitor's current Woo session (recovery visits). */
    public static function set_session($id, $session_key) {
        global $wpdb;
        $table = self::table();
        $wpdb->update(
            $table,
            ['session_key' => $session_key, 'updated_at' => current_time('mysql', true)],
            ['id' => (int) $id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Close the loop on purchase (spec FR-6): flip every open or recovered row
     * belonging to this buyer. Runs before any queued Klaviyo push sends (the
     * sender re-checks status right before sending).
     *
     * Session match closes outright: that is unambiguously the same visitor.
     * Email match is corroborated by cart overlap, because the billing email on
     * an order is attacker-supplied. Without that check, anyone could submit a
     * checkout carrying a stranger's address and silently suppress their
     * pending recovery email. A genuine cross-device buyer still closes, since
     * their order contains what they had in the captured cart.
     */
    public static function mark_purchased($session_key, $email, $product_ids = []) {
        global $wpdb;
        $table       = self::table();
        $session_key = (string) $session_key;
        $email       = (string) $email;
        $closed      = 0;
        $now         = current_time('mysql', true);

        if ('' !== $session_key) {
            $closed += (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s, updated_at = %s
                 WHERE session_key = %s AND status IN (%s, %s)",
                self::STATUS_PURCHASED, $now, $session_key,
                self::STATUS_OPEN, self::STATUS_RECOVERED
            ));
        }

        if ('' === $email) return $closed;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, cart_contents FROM {$table}
             WHERE email = %s AND status IN (%s, %s)",
            $email, self::STATUS_OPEN, self::STATUS_RECOVERED
        ));

        foreach ((array) $rows as $row) {
            if (!self::shares_product($row->cart_contents, $product_ids)) continue;
            self::set_status($row->id, self::STATUS_PURCHASED);
            $closed++;
        }

        return $closed;
    }

    /** Does a stored cart snapshot contain any of the ordered products? */
    private static function shares_product($cart_contents, $product_ids) {
        $product_ids = array_map('intval', (array) $product_ids);
        if (!$product_ids) return false;

        $snapshot = json_decode((string) $cart_contents, true);
        $items    = is_array($snapshot) ? ($snapshot['items'] ?? []) : [];
        foreach ((array) $items as $it) {
            if (in_array((int) ($it['product_id'] ?? 0), $product_ids, true)) return true;
            if (in_array((int) ($it['variation_id'] ?? 0), $product_ids, true)) return true;
        }
        return false;
    }

    /** Daily retention purge (spec FR-5): hard-delete after 90 quiet days. */
    public static function purge_old() {
        global $wpdb;
        $table  = self::table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - GALADO_RECOVERY_RETENTION_DAYS * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE updated_at < %s", $cutoff));
    }

    /** Small readouts for the settings screen. */
    public static function counts() {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status");
        $out   = [];
        foreach ((array) $rows as $r) $out[$r->status] = (int) $r->n;
        return $out;
    }

    public static function recent($limit = 10) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, cart_total, currency, status, consent, source, created_at, updated_at
             FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit
        ));
    }
}
