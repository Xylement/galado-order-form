<?php
/**
 * Plugin Name: GALADO Facebook Catalog Feed
 * Plugin URI: https://galado.com.my
 * Description: Generates a Meta-spec product feed from your WooCommerce catalog. Point Facebook Commerce Manager's scheduled Data Feed at the feed URL, or download it once for a manual upload. No Graph API, no access tokens, nothing to break on a plugin update.
 * Version: 1.0.0
 * Author: GALADO
 * Author URI: https://galado.com.my
 * License: GPL v2 or later
 * Text Domain: galado-fb-feed
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) exit;

define('GFBF_VERSION', '1.0.0');
define('GFBF_PATH', plugin_dir_path(__FILE__));
define('GFBF_URL', plugin_dir_url(__FILE__));

/**
 * Default settings. A random token is generated once so the feed isn't
 * world-readable out of the box.
 */
function gfbf_default_settings() {
    return [
        'token'              => wp_generate_password(20, false),
        'format'             => 'xml',
        'include_variations' => 1,
        'exclude_cats'       => [],
        'cache_hours'        => 6,
        'brand'              => 'GALADO',
    ];
}

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>GALADO Facebook Catalog Feed</strong> requires WooCommerce.</p></div>';
        });
        return;
    }

    // git-sync deploys files without firing the activation hook — make sure
    // defaults (and a token) exist regardless of how the plugin arrived.
    if (get_option('gfbf_settings') === false) {
        update_option('gfbf_settings', gfbf_default_settings());
    }

    require_once GFBF_PATH . 'includes/class-feed-generator.php';
    require_once GFBF_PATH . 'includes/feed-endpoint.php';

    if (is_admin()) {
        require_once GFBF_PATH . 'admin/settings-page.php';
    }
});

// Admin menu — under the GALADO hub if available, otherwise under WooCommerce.
add_action('admin_menu', function () {
    $parent = class_exists('Galado_Admin_Hub') ? 'galado-hub' : 'woocommerce';
    add_submenu_page(
        $parent,
        'Facebook Catalog Feed',
        'Facebook Feed',
        'manage_woocommerce',
        'galado-fb-feed',
        'gfbf_settings_page'
    );
}, 20);

// Admin styles
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'galado-fb-feed') === false) {
        return;
    }
    wp_enqueue_style('gfbf-admin', GFBF_URL . 'admin/style.css', [], GFBF_VERSION);
});

// Activation — seed defaults and a random feed token.
register_activation_hook(__FILE__, function () {
    if (get_option('gfbf_settings') === false) {
        update_option('gfbf_settings', gfbf_default_settings());
    }
});
