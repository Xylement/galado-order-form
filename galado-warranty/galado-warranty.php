<?php
/**
 * Plugin Name: GALADO Warranty Registration
 * Plugin URI: https://galado.com.my
 * Description: Lets marketplace customers (Shopee, Lazada, TikTok, WhatsApp, social) register their purchase to extend warranty from 1 month to 6 months. Captures their contact info, subscribes them to Klaviyo marketing, and rewards them with a welcome coupon for future direct-website orders.
 * Version: 1.1.7
 * Author: GALADO
 * Author URI: https://galado.com.my
 * License: GPL v2 or later
 * Text Domain: galado-warranty
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) exit;

define('GWARR_VERSION', '1.1.7');
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
 * Render a product_text value (which may contain multi-line "1) … 2) …" lists
 * from the sheet) as HTML for a web page (My Warranties, admin, etc.).
 * Single-item strings stay inline; multi-item lists become a <ul>.
 */
function gwarr_format_product_html($text) {
    $text = trim((string) $text);
    if ($text === '') return '';

    $lines = preg_split('/\r?\n/', $text);
    $clean = function ($line) {
        return trim(preg_replace('/^\s*\d+\)\s*/', '', (string) $line));
    };

    if (count($lines) === 1) {
        return esc_html($clean($lines[0]));
    }

    $items = array_filter(array_map($clean, $lines), 'strlen');
    if (count($items) <= 1) {
        return esc_html(reset($items) ?: $text);
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
    $text = trim((string) $text);
    if ($text === '') return '';

    $lines = preg_split('/\r?\n/', $text);
    $clean = function ($line) {
        return trim(preg_replace('/^\s*\d+\)\s*/', '', (string) $line));
    };

    if (count($lines) === 1) {
        return esc_html($clean($lines[0]));
    }

    $items = array_filter(array_map($clean, $lines), 'strlen');
    if (count($items) <= 1) {
        return esc_html(reset($items) ?: $text);
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
        require_once GWARR_PATH . 'public/register-shortcode.php';
        require_once GWARR_PATH . 'public/my-warranties.php';
        require_once GWARR_PATH . 'public/auth-ajax.php';

        if (is_admin()) {
            require_once GWARR_PATH . 'admin/list-table.php';
            require_once GWARR_PATH . 'admin/settings-page.php';
        }

        // Background sheet sync — hourly. Cron hook is registered here so
        // even git-sync deploys (which skip activation) keep the sync alive.
        add_action(GWARR_Sheet_Sync::CRON_HOOK, ['GWARR_Sheet_Sync', 'run']);
        GWARR_Sheet_Sync::ensure_scheduled();
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
    wp_enqueue_style('galado-warranty', GWARR_URL . 'public/style.css', [], GWARR_VERSION);
    wp_enqueue_script('galado-warranty', GWARR_URL . 'public/script.js', ['jquery'], GWARR_VERSION, true);

    // Make the AJAX endpoint + nonce available to the auth modal.
    wp_localize_script('galado-warranty', 'gwarrAuth', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('gwarr_auth'),
    ]);
});

// Admin assets — only on the plugin's screens.
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'galado-warranty') === false) {
        return;
    }
    wp_enqueue_style('galado-warranty-admin', GWARR_URL . 'admin/style.css', [], GWARR_VERSION);
});

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

    $sql_main = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        marketplace VARCHAR(32) NOT NULL,
        order_number VARCHAR(64) NOT NULL,
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
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_marketplace_order (marketplace, order_number),
        KEY idx_user (user_id),
        KEY idx_status (status),
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

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_main);
    dbDelta($sql_cache);
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
