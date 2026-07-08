<?php
/**
 * Plugin Name: GALADO Warranty Registration
 * Plugin URI: https://galado.com.my
 * Description: Lets marketplace customers (Shopee, Lazada, TikTok, WhatsApp, social) register their purchase to extend warranty from 1 month to 6 months. Captures their contact info, subscribes them to Klaviyo marketing, and rewards them with a welcome coupon for future direct-website orders.
 * Version: 1.8.10
 * Author: GALADO
 * Author URI: https://galado.com.my
 * License: GPL v2 or later
 * Text Domain: galado-warranty
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) exit;

define('GWARR_VERSION', '1.8.10');
define('GWARR_PATH', plugin_dir_path(__FILE__));
define('GWARR_URL', plugin_dir_url(__FILE__));
define('GWARR_TABLE', 'galado_warranties');

/**
 * Default settings — first activation seeds these.
 */
function gwarr_default_settings() {
    return [
        'klaviyo_api_key'      => '',
        'klaviyo_list_id'      => '',
        'klaviyo_event_name'   => 'Warranty Approved',
        'coupon_amount'        => 10,           // percent
        'coupon_min_spend'     => 0,
        'coupon_expiry_days'   => 90,
        'coupon_free_shipping' => 1,            // On for the perk copy. GALADO already ships free in Malaysia, but marketplace buyers don't read shipping policies — they react to "10% off + free shipping" on the coupon as a stronger conversion signal. At checkout it's a no-op (shipping is already free) unless the store configures the "valid free shipping coupon" requirement, which is fine.
        'warranty_months'      => 6,
        'from_name'            => 'GALADO',
        'from_email'           => '',           // falls back to site admin email
        'claim_notify_email'   => 'warranty@galado.com.my', // where new-claim alerts go
        'page_register_url'    => '',           // optional override for "register here" CTA
        'support_coverage_url' => 'https://galado.com.my/support/#tab_satisfaction-guarantee',
        'sheet_id'             => '',           // Google Sheet ID for auto-approve
        'service_account_json' => '',           // paste-in fallback when no wp-config constant
        'auto_approve'         => 1,            // master toggle
    ];
}

/**
 * Human-readable perk summary built from the current coupon settings.
 * Reused by the My Warranties view + every email that mentions the coupon
 * so changes to settings stay in lockstep across all surfaces.
 */
function gwarr_perk_description() {
    $settings      = get_option('gwarr_settings', []);
    $amount        = (int) ($settings['coupon_amount'] ?? 10);
    $free_shipping = !empty($settings['coupon_free_shipping']);

    $parts = [];
    if ($amount > 0) $parts[] = $amount . '% off';
    if ($free_shipping) $parts[] = 'free shipping';

    return $parts ? implode(' + ', $parts) : 'a discount';
}

function gwarr_coverage_url() {
    $settings = get_option('gwarr_settings', []);
    return $settings['support_coverage_url'] ?? 'https://galado.com.my/support/#tab_satisfaction-guarantee';
}

/**
 * Warranty months for a customer by their GALADO Club tier.
 *
 * Black-tier Club members get 12 months of coverage; everyone else gets the
 * configured standard (default 6). The Club server at GALADO_CLUB_URL is the
 * source of truth — we cache its answer for 1 hour per email and fail safe
 * to the configured standard if the Club is unreachable, so a Club outage
 * never breaks the warranty flow.
 *
 * Constants GALADO_CLUB_URL and GALADO_CLUB_BRIDGE_SECRET are already defined
 * in wp-config.php by the Club bridge plugin — we reuse them rather than
 * adding another secret to manage.
 */
function galado_warranty_months_for_email($email) {
    $standard = max(1, (int) (get_option('gwarr_settings', [])['warranty_months'] ?? 6));
    $email    = strtolower(trim((string) $email));

    if ($email === '' || !defined('GALADO_CLUB_URL') || !defined('GALADO_CLUB_BRIDGE_SECRET')) {
        return $standard;
    }

    $key    = 'gwarr_months_' . md5($email);
    $cached = get_transient($key);
    if (false !== $cached) {
        return max(1, (int) $cached);
    }

    $resp = wp_remote_get(
        rtrim(GALADO_CLUB_URL, '/') . '/api/warranty/coverage?email=' . rawurlencode($email),
        [
            'timeout' => 4,
            'headers' => ['x-club-bridge-secret' => GALADO_CLUB_BRIDGE_SECRET],
        ]
    );

    if (is_wp_error($resp) || 200 !== wp_remote_retrieve_response_code($resp)) {
        return $standard; // Club down / non-200 → fall back, never break a warranty
    }

    $data   = json_decode(wp_remote_retrieve_body($resp), true);
    $months = (is_array($data) && !empty($data['months'])) ? max(1, (int) $data['months']) : $standard;
    set_transient($key, $months, HOUR_IN_SECONDS);
    return $months;
}

/**
 * Resolve the warranty-months value for a registration row. Looks up the
 * user's email from user_id, falls back to the configured standard if the
 * user was deleted.
 */
function gwarr_months_for_row($row) {
    $user = $row && !empty($row->user_id) ? get_userdata((int) $row->user_id) : null;
    if ($user && !empty($user->user_email)) {
        return galado_warranty_months_for_email($user->user_email);
    }
    return max(1, (int) (get_option('gwarr_settings', [])['warranty_months'] ?? 6));
}

/**
 * Notify GALADO Club that a warranty was registered → buyer earns the one-time
 * welcome pack (Registered badge + Guardian pet + 50 G-Coins + welcome email,
 * all granted/sent by the Club). The Club is idempotent (once per member,
 * ever — keyed on the badge), so this is safe to call on every registration.
 *
 * Fire-and-forget (blocking => false): the customer never waits on the Club,
 * and a Club hiccup simply means no pack that time — it never blocks or
 * breaks the registration. Reuses GALADO_CLUB_URL + GALADO_CLUB_BRIDGE_SECRET
 * already defined in wp-config for the bridge plugin / Black-warranty lookup.
 */
function galado_club_notify_warranty($email, $warranty_id, $args = array()) {
    if (!defined('GALADO_CLUB_URL') || !defined('GALADO_CLUB_BRIDGE_SECRET')) {
        return;
    }
    $email = strtolower(trim((string) $email));
    if ($email === '' || (string) $warranty_id === '') {
        return;
    }

    $body = wp_json_encode([
        'email'       => $email,
        'warranty_id' => (string) $warranty_id,
        'order_id'    => isset($args['order_id'])    ? $args['order_id']    : null,
        'serial'      => isset($args['serial'])      ? $args['serial']      : null,
        'marketplace' => isset($args['marketplace']) ? $args['marketplace'] : null,
    ]);

    wp_remote_post(rtrim(GALADO_CLUB_URL, '/') . '/webhooks/warranty', [
        'timeout'  => 4,
        'blocking' => false, // fire-and-forget; the customer never waits on the Club
        'headers'  => [
            'content-type'         => 'application/json',
            'x-club-bridge-secret' => GALADO_CLUB_BRIDGE_SECRET,
        ],
        'body'     => $body,
    ]);
}

/**
 * Run heavy work AFTER the HTTP response is flushed to the client.
 *
 * The approval email (wp_mail / SMTP, 1–3s) and the Klaviyo sync (3 sequential
 * API calls, 2–5s) don't need to block the customer — they only need to happen.
 * On PHP-FPM (fastcgi_finish_request available) we close the connection first
 * so the browser navigates to the result page instantly, then finish the work
 * in the background of the same request. Where FPM isn't available it still
 * runs on shutdown, just without the early flush.
 *
 * Tasks are collected and executed once on the WP 'shutdown' hook; each is
 * wrapped so one failure never aborts the rest.
 */
class GWARR_Deferred {
    private static $tasks  = [];
    private static $hooked = false;

    public static function add($callback) {
        if (!is_callable($callback)) {
            return;
        }
        self::$tasks[] = $callback;
        if (!self::$hooked) {
            self::$hooked = true;
            add_action('shutdown', [__CLASS__, 'flush'], 0);
        }
    }

    public static function flush() {
        $start   = isset($GLOBALS['timestart']) ? (float) $GLOBALS['timestart'] : microtime(true);
        $t_flush = microtime(true);

        // Send the response now; keep working after the browser has it.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $t_after_flush = microtime(true);

        $tasks       = self::$tasks;
        self::$tasks = [];
        foreach ($tasks as $task) {
            try {
                call_user_func($task);
            } catch (\Throwable $e) {
                error_log('[galado-warranty] deferred task failed: ' . $e->getMessage());
            }
        }

        // Record timing so the diagnostics screen can show whether the early
        // flush actually happened and how long the deferred work took.
        update_option('gwarr_last_deferred_timing', [
            'at'              => current_time('mysql'),
            'task_count'      => count($tasks),
            'before_flush_ms' => round(($t_flush - $start) * 1000, 1),
            'deferred_ms'     => round((microtime(true) - $t_after_flush) * 1000, 1),
            'fcr'             => function_exists('fastcgi_finish_request') ? 'yes' : 'no',
        ], false);
    }
}

/**
 * Lightweight waterfall instrumentation for the real registration path. Marks
 * are collected per-request and persisted by gwarr_store_marks() so the
 * diagnostics screen can show exactly which step of an actual submission was
 * slow (the isolated diagnostics test can't reproduce orchestration cost).
 */
function gwarr_mark($label) {
    if (!isset($GLOBALS['gwarr_marks'])) {
        $GLOBALS['gwarr_marks'] = [];
    }
    $GLOBALS['gwarr_marks'][] = [$label, microtime(true)];
}

function gwarr_store_marks($extra = []) {
    if (empty($GLOBALS['gwarr_marks'])) {
        return;
    }
    $marks = $GLOBALS['gwarr_marks'];
    $first = $marks[0][1];
    $prev  = $first;
    $rows  = [];
    foreach ($marks as $m) {
        $rows[] = [
            'label'          => $m[0],
            'since_prev_ms'  => round(($m[1] - $prev) * 1000, 1),
            'since_start_ms' => round(($m[1] - $first) * 1000, 1),
        ];
        $prev = $m[1];
    }
    update_option('gwarr_last_submit_marks', array_merge([
        'at'   => current_time('mysql'),
        'rows' => $rows,
    ], $extra), false);
    $GLOBALS['gwarr_marks'] = [];
}

/**
 * Parse a product_text value (which may contain multi-line "1) … 2) …" lists
 * from the sheet/order) into a clean array of individual item names. The
 * numeric "n)" prefixes are stripped and blank lines dropped. Used both for
 * display and for the per-item claim selector.
 */
function gwarr_parse_product_items($text) {
    $text = trim((string) $text);
    if ($text === '') return [];

    $lines = preg_split('/\r?\n/', $text);
    $items = array_map(function ($line) {
        return trim(preg_replace('/^\s*\d+\)\s*/', '', (string) $line));
    }, $lines);

    return array_values(array_filter($items, 'strlen'));
}

/**
 * Render a product_text value (which may contain multi-line "1) … 2) …" lists
 * from the sheet) as HTML for a web page (My Warranties, admin, etc.).
 * Single-item strings stay inline; multi-item lists become a <ul>.
 */
function gwarr_format_product_html($text) {
    $items = gwarr_parse_product_items($text);
    if (empty($items)) return '';

    if (count($items) === 1) {
        return esc_html($items[0]);
    }

    $html = '<ul class="gwarr-product-list">';
    foreach ($items as $item) {
        $html .= '<li>' . esc_html($item) . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Email-flavoured variant: same logic but with inline styles for email clients
 * that strip <style> blocks (Gmail, Outlook, Apple Mail).
 */
function gwarr_format_product_email($text) {
    $items = gwarr_parse_product_items($text);
    if (empty($items)) return '';

    if (count($items) === 1) {
        return esc_html($items[0]);
    }

    $html = '<ul style="margin:6px 0 0;padding:0 0 0 20px;font-size:15px;line-height:1.6;color:#1a1a1a;">';
    foreach ($items as $item) {
        $html .= '<li style="margin:0 0 4px;">' . esc_html($item) . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>GALADO Warranty Registration</strong> requires WooCommerce.</p></div>';
        });
        return;
    }

    if (get_option('gwarr_settings') === false) {
        update_option('gwarr_settings', gwarr_default_settings());
    }

    // git-sync deploys skip the activation hook — make sure the DB table
    // exists regardless of how the plugin arrived. Failures are logged but
    // never bubble up: a missing table affects warranty features only, not
    // the rest of the site.
    if (get_option('gwarr_db_version') !== GWARR_VERSION) {
        try {
            gwarr_install_table();
            update_option('gwarr_db_version', GWARR_VERSION);
        } catch (Throwable $e) {
            error_log('[galado-warranty] install_table failed: ' . $e->getMessage());
        }
    }

    // Loading each module under try/catch keeps a problem in one file from
    // taking the whole site down — worst case, the warranty UI doesn't render
    // but other plugins continue working.
    try {
        require_once GWARR_PATH . 'includes/class-warranty-db.php';
        require_once GWARR_PATH . 'includes/class-warranty-marketplaces.php';
        require_once GWARR_PATH . 'includes/class-warranty-coupon.php';
        require_once GWARR_PATH . 'includes/class-warranty-email.php';
        require_once GWARR_PATH . 'includes/class-warranty-klaviyo.php';
        require_once GWARR_PATH . 'includes/class-warranty-approval.php';
        require_once GWARR_PATH . 'includes/class-warranty-sheet-api.php';
        require_once GWARR_PATH . 'includes/class-warranty-sheet-sync.php';
        require_once GWARR_PATH . 'includes/class-warranty-auto-approve.php';
        require_once GWARR_PATH . 'includes/class-warranty-orders.php';
        require_once GWARR_PATH . 'includes/class-warranty-claims.php';
        require_once GWARR_PATH . 'includes/class-warranty-diagnostics.php';
        require_once GWARR_PATH . 'public/register-shortcode.php';
        require_once GWARR_PATH . 'public/my-warranties.php';
        require_once GWARR_PATH . 'public/claim-form.php';
        require_once GWARR_PATH . 'public/auth-ajax.php';

        if (is_admin()) {
            require_once GWARR_PATH . 'admin/list-table.php';
            require_once GWARR_PATH . 'admin/settings-page.php';
            require_once GWARR_PATH . 'admin/claims-page.php';
        }

        // Background sheet sync — hourly. Cron hook is registered here so
        // even git-sync deploys (which skip activation) keep the sync alive.
        add_action(GWARR_Sheet_Sync::CRON_HOOK, ['GWARR_Sheet_Sync', 'run']);
        GWARR_Sheet_Sync::ensure_scheduled();

        // Auto-capture WooCommerce orders as per-item warranties.
        GWARR_Orders::init();

        // Warranty shipping-fee pay pages: restore gateways (FPX online
        // banking, Touch 'n Go) that hide themselves because the cart is
        // empty on the pay-for-order page. Scoped to our orders only. Runs
        // at max priority so a later cart-based filter can't re-remove them.
        add_filter('woocommerce_available_payment_gateways', ['GWARR_Claims', 'pay_page_gateways'], PHP_INT_MAX);

        // Link guest-checkout warranties to a customer's account by billing
        // email when they log in or register — so an order placed as a guest
        // (user_id = 0) surfaces in My Warranties once they have an account.
        add_action('wp_login', function ($login, $user) {
            if ($user instanceof WP_User) {
                GWARR_DB::link_website_orphans($user->user_email, $user->ID);
            }
        }, 10, 2);
        add_action('user_register', function ($user_id) {
            $u = get_userdata($user_id);
            if ($u) {
                GWARR_DB::link_website_orphans($u->user_email, $user_id);
            }
        });
    } catch (Throwable $e) {
        error_log('[galado-warranty] module load failed: ' . $e->getMessage());
    }
}, 20);

// Frontend assets — only on pages that actually contain a shortcode or My Account.
add_action('wp_enqueue_scripts', function () {
    global $post;
    $needs_assets = function_exists('is_account_page') && is_account_page();
    if (!$needs_assets && $post instanceof WP_Post) {
        $needs_assets = has_shortcode($post->post_content, 'galado_warranty_register')
            || has_shortcode($post->post_content, 'galado_warranty_list');
    }
    if (!$needs_assets) {
        return;
    }
    // Version the assets by file mtime, not the plugin version, so every deploy
    // (git-sync rewrites the files) gets a fresh ?ver= and busts browser/CDN
    // caches automatically — no manual version bump or cache purge needed.
    $css_ver = gwarr_asset_version('public/style.css');
    $js_ver  = gwarr_asset_version('public/script.js');
    wp_enqueue_style('galado-warranty', GWARR_URL . 'public/style.css', [], $css_ver);
    wp_enqueue_script('galado-warranty', GWARR_URL . 'public/script.js', ['jquery'], $js_ver, true);

    // Make the AJAX endpoint + nonce available to the auth modal.
    wp_localize_script('galado-warranty', 'gwarrAuth', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('gwarr_auth'),
    ]);
});

// Never cache the warranty form / My Warranties pages — they render per-user
// state (the just-registered confirmation, the customer's own warranty list).
// A cached copy would swallow the post-submit success notice. Runs before
// output so the no-cache headers actually take effect, and sets DONOTCACHEPAGE
// which WP page-cache plugins honour.
add_action('template_redirect', function () {
    if (is_admin()) {
        return;
    }
    $dynamic = function_exists('is_account_page') && is_account_page();
    if (!$dynamic) {
        global $post;
        if ($post instanceof WP_Post) {
            $dynamic = has_shortcode($post->post_content, 'galado_warranty_register')
                || has_shortcode($post->post_content, 'galado_warranty_list');
        }
    }
    if (!$dynamic) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    nocache_headers();
}, 1);

// Admin assets — only on the plugin's screens.
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'galado-warranty') === false) {
        return;
    }
    wp_enqueue_style('galado-warranty-admin', GWARR_URL . 'admin/style.css', [], gwarr_asset_version('admin/style.css'));
});

/**
 * Cache-busting version string for a bundled asset — its file mtime, falling
 * back to the plugin version if the file can't be stat'd. Changing the file
 * (every deploy does) changes the ?ver=, forcing browsers/CDNs to refetch.
 */
function gwarr_asset_version($relpath) {
    $full = GWARR_PATH . ltrim($relpath, '/');
    $mtime = @filemtime($full);
    return $mtime ? (string) $mtime : GWARR_VERSION;
}

// Register with the GALADO admin hub if the hub plugin is active.
add_filter('galado_admin_hub_plugins', function ($plugins) {
    $plugins['galado-warranty'] = [
        'name'    => 'Warranty Registration',
        'icon'    => '🛡️',
        'version' => GWARR_VERSION,
    ];
    return $plugins;
});

// Admin menu — under GALADO Hub if available, otherwise under WooCommerce.
add_action('admin_menu', function () {
    $parent = class_exists('Galado_Admin_Hub') ? 'galado-hub' : 'woocommerce';

    if (function_exists('gwarr_render_registrations_page')) {
        add_submenu_page(
            $parent,
            'Warranty Registrations',
            'Warranties',
            'manage_woocommerce',
            'galado-warranty',
            'gwarr_render_registrations_page'
        );
    }
    if (function_exists('gwarr_render_claims_page')) {
        $counts = class_exists('GWARR_Claims') ? GWARR_Claims::status_counts() : ['submitted' => 0];
        $pending = (int) ($counts['submitted'] ?? 0);
        $label  = 'Warranty Claims' . ($pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '');
        add_submenu_page(
            $parent,
            'Warranty Claims',
            $label,
            'manage_woocommerce',
            'galado-warranty-claims',
            'gwarr_render_claims_page'
        );
    }
    if (function_exists('gwarr_render_settings_page')) {
        add_submenu_page(
            $parent,
            'Warranty Settings',
            'Warranty Settings',
            'manage_woocommerce',
            'galado-warranty-settings',
            'gwarr_render_settings_page'
        );
    }
}, 20);

/**
 * Create/upgrade the warranty registrations table. Idempotent — safe to call
 * on every load. Used by both register_activation_hook and the version-bump
 * check in plugins_loaded so git-sync deploys aren't left without a table.
 */
function gwarr_install_table() {
    global $wpdb;
    $table   = $wpdb->prefix . GWARR_TABLE;
    $charset = $wpdb->get_charset_collate();

    // Columns added in v1.8.10 (source/wc_order_id/wc_item_id/claimed_at) are
    // appended by dbDelta on existing installs — the UNIQUE KEY is left
    // untouched. Website (WooCommerce) rows stay unique per line item by
    // storing order_number as "{orderId}#{itemId}", which the existing
    // (marketplace, order_number) key already enforces; wc_order_id holds the
    // clean order number for display.
    $sql_main = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'marketplace',
        marketplace VARCHAR(32) NOT NULL,
        order_number VARCHAR(64) NOT NULL,
        wc_order_id BIGINT UNSIGNED NULL,
        wc_item_id BIGINT UNSIGNED NULL,
        billing_email VARCHAR(191) NULL,
        product_text TEXT NOT NULL,
        notes TEXT NULL,
        marketing_consent TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        purchase_date DATE NULL,
        warranty_ends DATE NULL,
        coupon_code VARCHAR(64) NULL,
        admin_note TEXT NULL,
        approved_by BIGINT UNSIGNED NULL,
        approved_at DATETIME NULL,
        claimed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_marketplace_order (marketplace, order_number),
        KEY idx_user (user_id),
        KEY idx_status (status),
        KEY idx_source (source),
        KEY idx_wc_item (wc_item_id),
        KEY idx_created (created_at)
    ) {$charset};";

    // Local cache of the Numeris sheet — fed by WP-Cron in the background,
    // queried by the form-submit auto-approve path. Keeping it as a flat
    // table means lookups are a primary-key hit, not a Google API round-trip.
    $cache_table = $wpdb->prefix . 'galado_warranty_sheet_cache';
    $sql_cache = "CREATE TABLE {$cache_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        marketplace VARCHAR(32) NOT NULL,
        order_number VARCHAR(64) NOT NULL,
        product_name TEXT NOT NULL,
        purchase_date DATE NULL,
        raw_marketplace VARCHAR(64) NOT NULL DEFAULT '',
        sheet_tab VARCHAR(64) NOT NULL DEFAULT '',
        raw_row INT NOT NULL DEFAULT 0,
        synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_cache_order (marketplace, order_number),
        KEY idx_synced (synced_at)
    ) {$charset};";

    // Warranty claims (v1.8.10) — one row per customer claim against a warranty.
    // media_ids holds a JSON array of WP attachment IDs (photos + 1 video).
    $claims_table = $wpdb->prefix . 'galado_warranty_claims';
    $sql_claims = "CREATE TABLE {$claims_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        warranty_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        item_label VARCHAR(255) NULL,
        issue_description TEXT NOT NULL,
        media_ids TEXT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'submitted',
        admin_note TEXT NULL,
        shipping_fee DECIMAL(10,2) NULL,
        shipping_order_id BIGINT UNSIGNED NULL,
        resolved_by BIGINT UNSIGNED NULL,
        resolved_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_warranty (warranty_id),
        KEY idx_user (user_id),
        KEY idx_status (status)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_main);
    dbDelta($sql_cache);
    dbDelta($sql_claims);
}

register_activation_hook(__FILE__, function () {
    try {
        gwarr_install_table();
        update_option('gwarr_db_version', GWARR_VERSION);
        if (get_option('gwarr_settings') === false) {
            update_option('gwarr_settings', gwarr_default_settings());
        }
        // Register the My Account endpoint and flush rewrite rules once,
        // here in activation only. Doing this every request fires every
        // other plugin's rewrite_rules_array callbacks and is unsafe.
        if (function_exists('add_rewrite_endpoint')) {
            add_rewrite_endpoint('warranties', EP_ROOT | EP_PAGES);
        }
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    } catch (Throwable $e) {
        error_log('[galado-warranty] activation failed: ' . $e->getMessage());
    }
});

register_deactivation_hook(__FILE__, function () {
    if (class_exists('GWARR_Sheet_Sync')) {
        GWARR_Sheet_Sync::unschedule();
    } else {
        // Class might not be loaded yet if deactivation runs before plugins_loaded.
        $next = wp_next_scheduled('gwarr_sheet_sync');
        if ($next) wp_unschedule_event($next, 'gwarr_sheet_sync');
    }
});
