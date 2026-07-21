<?php
/**
 * Plugin Name: GALADO Bundles
 * Description: Self-service product bundles: staff build kits in wp-admin (simple + variable items), one flat margin-funded RM saving per bundle, rendered into home-v3 via [galado_bundles] and applied at cart as a complete-set-only negative fee. Generalises and retires Code Snippet #95. Writes no product data; reversible by deactivation. Spec: BUNDLES-SPEC.md.
 * Version: 0.1.5
 * Author: GALADO
 * Text Domain: galado-bundles
 */

if (!defined('ABSPATH')) exit;

define('GALADO_BUNDLES_VERSION', '0.1.5');
define('GALADO_BUNDLES_PATH', plugin_dir_path(__FILE__));
define('GALADO_BUNDLES_URL', plugin_dir_url(__FILE__));

/** CPT slug, meta prefix, cart-item keys, hook name. Shared contract. */
define('GALADO_BUNDLES_CPT', 'galado_bundle');
define('GALADO_BUNDLES_META', '_galado_bundle_');            // discrete meta prefix
define('GALADO_BUNDLES_FEATURED_MAX', 3);

/** Products that must never be bundle-able (collide with other engines).
 * 404826 = the Studio Case backing product (Studio Cart owns it). */
function galado_bundles_excluded_products() {
    return apply_filters('galado_bundles_excluded_products', [404826]);
}

/** Storefront + cart kill switch. Dark by default: the CPT, admin UI and REST
 * routes register regardless, so staff can author bundles, but nothing renders
 * on the storefront and no cart fee applies until this is on. */
function galado_bundles_storefront_enabled() {
    return '1' === get_option('galado_bundles_storefront_enabled', '0');
}

/** The full primitive capability set WP auto-generates for capability_type
 * 'galado_bundle'. All of these must be granted or staff cannot edit/retire a
 * PUBLISHED (active) bundle and save_post silently drops the edit. */
function galado_bundles_caps() {
    return [
        'edit_galado_bundle', 'read_galado_bundle', 'delete_galado_bundle',
        'edit_galado_bundles', 'edit_others_galado_bundles',
        'edit_published_galado_bundles', 'edit_private_galado_bundles',
        'publish_galado_bundles', 'read_private_galado_bundles',
        'delete_galado_bundles', 'delete_others_galado_bundles',
        'delete_published_galado_bundles', 'delete_private_galado_bundles',
    ];
}

require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-cpt.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-data.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-rest.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-admin.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-storefront.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-cart.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-discount.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-analytics.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>GALADO Bundles needs WooCommerce active.</p></div>';
        });
        return;
    }

    // Always on: authoring + read surface. Safe with the storefront dark.
    GALADO_Bundles_CPT::init();
    GALADO_Bundles_REST::init();
    if (is_admin()) {
        GALADO_Bundles_Admin::init();
    }
    // Register the shortcode always so a [galado_bundles] placed on a page while
    // dark renders nothing (not the literal shortcode text). It returns '' until
    // the storefront flag is on.
    add_shortcode('galado_bundles', ['GALADO_Bundles_Storefront', 'shortcode']);

    // Customer-facing: only when the flag is on. Everything below is inert
    // (no shortcode output, no cart fee, no cart tagging) while dark.
    if (galado_bundles_storefront_enabled()) {
        GALADO_Bundles_Storefront::init();
        GALADO_Bundles_Cart::init();
        GALADO_Bundles_Discount::init();
        GALADO_Bundles_Analytics::init();
        // Signals #95 to stand down (its Phase-2 guard checks this constant).
        if (!defined('GALADO_BUNDLES_OWNS_CART')) {
            define('GALADO_BUNDLES_OWNS_CART', true);
        }
    }
});

// HPOS compatibility (same declaration as the other GALADO plugins).
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Grant the bundle capabilities to shop managers and admins on activation, so
// map_meta_cap on the CPT resolves. Reversible: caps removed on deactivation.
register_activation_hook(__FILE__, function () {
    foreach (['administrator', 'shop_manager'] as $role_name) {
        $role = get_role($role_name);
        if (!$role) continue;
        foreach (galado_bundles_caps() as $cap) $role->add_cap($cap);
    }
    if (false === get_option('galado_bundles_storefront_enabled', false)) {
        add_option('galado_bundles_storefront_enabled', '0');
    }
});

register_deactivation_hook(__FILE__, function () {
    foreach (['administrator', 'shop_manager'] as $role_name) {
        $role = get_role($role_name);
        if (!$role) continue;
        foreach (galado_bundles_caps() as $cap) $role->remove_cap($cap);
    }
    // Bundle posts and meta stay in the DB (reactivation restores them). Data is
    // removed only via the explicit uninstall.php the owner opts into.
});

// Minimal settings screen: the one lever that matters (storefront on/off).
// Under the GALADO hub if available (next to the nested Bundles list), otherwise
// under the plugin's own CPT menu.
add_action('admin_menu', function () {
    $parent = class_exists('Galado_Admin_Hub') ? 'galado-hub' : 'edit.php?post_type=' . GALADO_BUNDLES_CPT;
    add_submenu_page(
        $parent,
        'Bundle settings', 'Bundle settings', 'manage_woocommerce',
        'galado-bundles-settings', 'galado_bundles_render_settings'
    );
}, 20);

function galado_bundles_render_settings() {
    if (!current_user_can('manage_woocommerce')) return;
    if (isset($_POST['galado_bundles_save']) && check_admin_referer('galado_bundles_settings')) {
        $on = isset($_POST['storefront_enabled']) ? '1' : '0';
        update_option('galado_bundles_storefront_enabled', $on);
        echo '<div class="notice notice-success"><p>Saved. Purge caches after switching the storefront on or off.</p></div>';
    }
    $on = galado_bundles_storefront_enabled();
    ?>
    <div class="wrap">
      <h1>GALADO Bundles</h1>
      <form method="post">
        <?php wp_nonce_field('galado_bundles_settings'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th>Storefront</th>
            <td>
              <label><input type="checkbox" name="storefront_enabled" value="1" <?php checked($on); ?>> Render bundles on the storefront and apply the cart saving</label>
              <p class="description">While off, staff can still create and edit bundles here; customers see nothing and no cart fee applies. Code Snippet #95 stays the live engine until this is on.</p>
            </td>
          </tr>
        </table>
        <p><button type="submit" name="galado_bundles_save" value="1" class="button button-primary">Save</button></p>
      </form>
    </div>
    <?php
}
