<?php
/**
 * Generates (and repairs) the unique welcome coupon a customer receives on
 * approval.
 *
 * Uses WooCommerce's native coupon system so it appears in the standard
 * Coupons admin screen and the customer can apply it at checkout exactly
 * like any other coupon.
 *
 * Persistence is verified after save(): WC_Coupon::save() can silently return
 * without creating the post (a blocking plugin, a bad hook), so we confirm the
 * coupon got an ID before reporting success. Otherwise a code would be stored
 * on the registration and shown to the customer with no coupon behind it.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Coupon {

    const REPAIR_STATE = 'gwarr_coupon_repair_state';
    const REPAIR_BATCH = 40;

    /**
     * Create a fresh, single-use, customer-restricted coupon for a registration.
     * @return string|WP_Error Coupon code on success, WP_Error on failure.
     */
    public static function create_for_registration($row) {
        return self::build($row, self::unique_code());
    }

    /**
     * Repair: ensure the WooCommerce coupon named by $row->coupon_code actually
     * exists. Reuses the stored code so the customer's known code works, and
     * honours the original expiry (registration date + expiry days) without
     * ever creating an already-dead coupon.
     *
     * @return string 'exists' | 'created' | 'skipped', or WP_Error on failure.
     */
    public static function ensure_for_row($row) {
        $code = trim((string) ($row->coupon_code ?? ''));
        if ($code === '') {
            return 'skipped';
        }
        if (!function_exists('wc_get_coupon_id_by_code')) {
            return new WP_Error('gwarr_no_wc', 'WooCommerce is not active.');
        }
        if (wc_get_coupon_id_by_code($code)) {
            return 'exists';
        }
        $res = self::build($row, $code, $row->created_at ?? null);
        return is_wp_error($res) ? $res : 'created';
    }

    /**
     * Build + save a coupon with an explicit code, then verify it persisted.
     *
     * @param object      $row          Registration row.
     * @param string      $code         Coupon code to use.
     * @param string|null $expiry_base  MySQL datetime to base expiry on (repair
     *                                  honours the original registration date);
     *                                  null = from now.
     * @return string|WP_Error
     */
    private static function build($row, $code, $expiry_base = null) {
        if (!class_exists('WC_Coupon')) {
            return new WP_Error('gwarr_no_wc', 'WooCommerce is not active.');
        }

        $settings      = get_option('gwarr_settings', []);
        $amount        = max(0, (float) ($settings['coupon_amount'] ?? 10));
        $min_spend     = max(0, (float) ($settings['coupon_min_spend'] ?? 0));
        $expiry_days   = max(1, (int) ($settings['coupon_expiry_days'] ?? 90));
        $free_shipping = !empty($settings['coupon_free_shipping']);

        $user_email = self::user_email($row->user_id);
        if (!$user_email) {
            return new WP_Error('gwarr_no_email', 'Customer has no email on file.');
        }

        $base    = $expiry_base ? strtotime($expiry_base) : time();
        if (!$base) $base = time();
        $expires = $base + ($expiry_days * DAY_IN_SECONDS);
        // Never create a coupon that is already expired (repair of old rows):
        // give it at least a 30-day usable window.
        if ($expires <= time() + DAY_IN_SECONDS) {
            $expires = time() + (30 * DAY_IN_SECONDS);
        }

        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount($amount);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions([$user_email]);
        $coupon->set_minimum_amount($min_spend);
        $coupon->set_date_expires($expires);
        // Free shipping flag only takes effect if a WooCommerce shipping zone
        // is configured with the "Free shipping requires a valid free shipping
        // coupon" option enabled, see WC, Settings, Shipping.
        $coupon->set_free_shipping($free_shipping);
        $coupon->set_description(sprintf(
            'GALADO warranty welcome coupon, registration #%d (%s order %s)',
            (int) $row->id,
            GWARR_Marketplaces::label($row->marketplace),
            $row->order_number
        ));

        // Attempt 1: WooCommerce's own save (best path, sets caches + fires hooks).
        try {
            $coupon->save();
            if ((int) $coupon->get_id() > 0) {
                return $code; // persisted cleanly
            }
        } catch (\Throwable $e) {
            error_log('[galado-warranty] WC coupon save exception for ' . $code . ': ' . $e->getMessage());
        }

        // WC save did not persist. Something is blocking shop_coupon creation via
        // WooCommerce's data store. Create the coupon post directly: this both
        // surfaces the real reason (wp_insert_post with $wp_error=true) and works
        // as a fallback when the block lives only in WC's coupon layer, not core.
        return self::direct_insert($code, $coupon->get_description(), $amount, $min_spend, $expires, $free_shipping, $user_email);
    }

    /**
     * Create a shop_coupon post directly + set the coupon meta WooCommerce reads,
     * capturing the real error if a filter refuses it. Reuses an existing
     * draft/pending post for the same code (e.g. a half-created one) instead of
     * duplicating.
     *
     * @return string|WP_Error the code on success.
     */
    private static function direct_insert($code, $description, $amount, $min_spend, $expires, $free_shipping, $user_email) {
        try {
            return self::direct_insert_inner($code, $description, $amount, $min_spend, $expires, $free_shipping, $user_email);
        } catch (\Throwable $e) {
            // A plugin can fatal on programmatic post creation; capture it rather
            // than 500 the page, and surface the exact class/file:line.
            error_log('[galado-warranty] coupon ' . $code . ' direct insert threw: ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return new WP_Error('gwarr_coupon_fatal',
                'Coupon creation crashed: ' . $e->getMessage() . ' [' . get_class($e) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']');
        }
    }

    private static function direct_insert_inner($code, $description, $amount, $min_spend, $expires, $free_shipping, $user_email) {
        global $wpdb;

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND LOWER(post_title) = LOWER(%s) ORDER BY ID DESC LIMIT 1",
            $code
        ));

        if ($existing_id) {
            wp_update_post(['ID' => $existing_id, 'post_status' => 'publish']);
            $post_id = $existing_id;
        } else {
            $post_id = wp_insert_post([
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
                'post_title'     => $code,
                'post_excerpt'   => (string) $description,
                'post_author'    => get_current_user_id() ?: 1,
                'ping_status'    => 'closed',
                'comment_status' => 'closed',
            ], true);

            if (is_wp_error($post_id)) {
                error_log('[galado-warranty] coupon ' . $code . ' blocked: ' . $post_id->get_error_message());
                return new WP_Error('gwarr_coupon_blocked', 'Coupon creation blocked: ' . $post_id->get_error_message());
            }
            if (!$post_id) {
                error_log('[galado-warranty] coupon ' . $code . ' insert returned 0 (a plugin is filtering out shop_coupon posts)');
                return new WP_Error('gwarr_coupon_blocked', 'Coupon post was silently dropped, a plugin is filtering shop_coupon creation.');
            }
        }

        // Set the meta WooCommerce reads back for a coupon.
        update_post_meta($post_id, 'discount_type', 'percent');
        update_post_meta($post_id, 'coupon_amount', $amount);
        update_post_meta($post_id, 'individual_use', 'yes');
        update_post_meta($post_id, 'usage_limit', 1);
        update_post_meta($post_id, 'usage_limit_per_user', 1);
        update_post_meta($post_id, 'usage_count', 0);
        update_post_meta($post_id, 'customer_email', [$user_email]);
        update_post_meta($post_id, 'minimum_amount', (string) $min_spend);
        update_post_meta($post_id, 'maximum_amount', '');
        update_post_meta($post_id, 'date_expires', (int) $expires);
        update_post_meta($post_id, 'free_shipping', $free_shipping ? 'yes' : 'no');
        update_post_meta($post_id, 'exclude_sale_items', 'no');

        // Bust WooCommerce's code->id lookup cache so the new coupon resolves.
        if (class_exists('WC_Cache_Helper') && method_exists('WC_Cache_Helper', 'invalidate_cache_group')) {
            WC_Cache_Helper::invalidate_cache_group('coupons');
        }

        error_log('[galado-warranty] coupon ' . $code . ' created via direct insert (WC save was blocked), post #' . (int) $post_id);
        return $code;
    }

    /**
     * W-XXXXXX style code, collision-checked against existing coupons.
     * Old WARRANTY-XXXXXX codes from earlier versions continue to work since
     * we never disable existing coupons; only newly-issued ones use W-.
     */
    private static function unique_code() {
        for ($i = 0; $i < 8; $i++) {
            $suffix = strtoupper(wp_generate_password(6, false, false));
            $code   = 'W-' . $suffix;
            if (!wc_get_coupon_id_by_code($code)) {
                return $code;
            }
        }
        return 'W-' . strtoupper(uniqid());
    }

    private static function user_email($user_id) {
        $user = get_userdata((int) $user_id);
        return $user ? $user->user_email : '';
    }

    /**
     * Diagnose a single coupon code: whether a WooCommerce coupon post exists
     * (in any status, so trashed/draft show up too) and which registration row
     * carries the code.
     *
     * @return array{code:string,published_id:int,posts:array,rows:array}
     */
    public static function inspect($code) {
        global $wpdb;
        $code = trim((string) $code);
        $out  = ['code' => $code, 'published_id' => 0, 'posts' => [], 'rows' => []];
        if ($code === '') {
            return $out;
        }
        if (function_exists('wc_get_coupon_id_by_code')) {
            $out['published_id'] = (int) wc_get_coupon_id_by_code($code);
        }
        // Any shop_coupon post matching the code, case-insensitively, any status.
        $out['posts'] = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND LOWER(post_title) = LOWER(%s)",
            $code
        )) ?: [];
        if (class_exists('GWARR_DB')) {
            $out['rows'] = GWARR_DB::rows_by_coupon($code) ?: [];
        }
        return $out;
    }

    /**
     * Force-create the coupon for a single code from its linked registration,
     * regardless of the bulk repair. If a published coupon already exists it is
     * left alone. Returns 'exists' | 'created' | WP_Error.
     */
    public static function force_create($code) {
        $code = trim((string) $code);
        if ($code === '') {
            return new WP_Error('gwarr_no_code', 'Enter a coupon code.');
        }
        if (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($code)) {
            return 'exists';
        }
        if (!class_exists('GWARR_DB')) {
            return new WP_Error('gwarr_no_db', 'Database class unavailable.');
        }
        $rows = GWARR_DB::rows_by_coupon($code);
        if (empty($rows)) {
            return new WP_Error('gwarr_no_row', 'No warranty registration carries the code ' . $code . '.');
        }
        // Build directly with the stored code so the customer's known code works.
        $res = self::build($rows[0], $rows[0]->coupon_code, $rows[0]->created_at ?? null);
        return is_wp_error($res) ? $res : 'created';
    }

    // =====================================================================
    // Repair runner: create any missing WC coupons for existing warranty
    // codes. Driven one batch per settings-page load (like the order backfill),
    // so it never depends on WP-Cron and can't time out on a big catalogue.
    // =====================================================================

    public static function get_repair_state() {
        $state = get_option(self::REPAIR_STATE, []);
        return is_array($state) ? wp_parse_args($state, self::default_repair_state()) : self::default_repair_state();
    }

    private static function default_repair_state() {
        return [
            'status'   => 'idle', // idle | running | done
            'offset'   => 0,
            'checked'  => 0,
            'created'  => 0,
            'existing' => 0,
            'failed'   => 0,
            'total'    => 0,
            'started'  => '',
            'finished' => '',
        ];
    }

    public static function start_repair() {
        $total = class_exists('GWARR_DB') ? GWARR_DB::coupon_rows_count() : 0;
        update_option(self::REPAIR_STATE, array_merge(self::default_repair_state(), [
            'status'  => 'running',
            'total'   => $total,
            'started' => current_time('mysql'),
        ]), false);
    }

    /**
     * Process one batch of registrations-with-a-code and advance the cursor.
     * The row set is stable (creating a coupon doesn't change coupon_code), so
     * offset paging is safe. Returns true while more remain.
     */
    public static function run_repair_batch() {
        $st = self::get_repair_state();
        if ($st['status'] !== 'running') {
            return false;
        }
        if (!class_exists('GWARR_DB')) {
            $st['status'] = 'done';
            update_option(self::REPAIR_STATE, $st, false);
            return false;
        }

        $rows = GWARR_DB::coupon_rows(self::REPAIR_BATCH, (int) $st['offset']);
        if (empty($rows)) {
            $st['status']   = 'done';
            $st['finished'] = current_time('mysql');
            update_option(self::REPAIR_STATE, $st, false);
            return false;
        }

        foreach ($rows as $r) {
            $st['checked']++;
            $res = self::ensure_for_row($r);
            if ($res === 'exists')       $st['existing']++;
            elseif ($res === 'created')  $st['created']++;
            elseif (is_wp_error($res))   $st['failed']++;
        }
        $st['offset'] = (int) $st['offset'] + count($rows);
        update_option(self::REPAIR_STATE, $st, false);
        return true;
    }
}
