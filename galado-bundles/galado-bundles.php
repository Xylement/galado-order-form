<?php
/**
 * Plugin Name: GALADO Bundles
 * Description: Self-service product bundles: staff build kits in wp-admin (simple + variable items), one flat margin-funded RM saving per bundle, rendered into home-v3 via [galado_bundles] and applied at cart as a complete-set-only negative fee. Generalises and retires Code Snippet #95. Writes no product data; reversible by deactivation. Spec: BUNDLES-SPEC.md.
 * Version: 0.17.0
 * Author: GALADO
 * Text Domain: galado-bundles
 */

if (!defined('ABSPATH')) exit;

define('GALADO_BUNDLES_VERSION', '0.17.0');
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

/** PDP protector-combo module (ADDON-COMBOS-SPEC). Needs the storefront ON to
 * sell (the cart + fee engine lives behind that flag); while dark it renders
 * for staff preview only. */
function galado_bundles_combos_enabled() {
    return '1' === get_option('galado_bundles_combos_enabled', '0');
}

/**
 * May THIS REQUEST add sets and receive the bundle fee?
 * True for everyone once the storefront master is on; while dark, true only
 * for staff (owner 2026-08-04: staff test the full add-to-cart flow, every
 * other customer stays exactly as today). Evaluated per request, never
 * cached into markup: the pages are cacheable, the decision is not.
 */
function galado_bundles_can_transact() {
    if (galado_bundles_storefront_enabled()) return true;
    return class_exists('GALADO_Bundles_Storefront') && GALADO_Bundles_Storefront::can_preview();
}

/** Mobile cart sticky CTA (ADDON-COMBOS-SPEC section 5). Independent of the
 * bundles storefront; can go live on its own. (The WCPA relabel from spec
 * section 6 is Code Snippet #182, shipped by marketing, NOT this plugin.) */
function galado_bundles_sticky_cart_enabled() {
    return '1' === get_option('galado_bundles_sticky_cart', '0');
}

/** PDP accessory add-on shelves (owner 2026-08-04: WCPA keeps name
 * customisation only; purchase-with-purchase moves here). Dark by default;
 * staff preview while the storefront master is off. */
function galado_bundles_addons_enabled() {
    return '1' === get_option('galado_bundles_addons_enabled', '0');
}

/** WCPA field keys/labels (one per line, case-insensitive substring match)
 * hidden on PDPs where the combo module renders, so the same protectors are
 * not sold twice on one page. Admin-editable in Bundle settings. */
function galado_bundles_wcpa_hide_keys() {
    $raw = get_option('galado_bundles_wcpa_hide_keys', "tempered glass\nlens protector\ng-armor\ncamera plateau");
    $keys = array_filter(array_map('trim', explode("\n", strtolower((string) $raw))));
    return array_values($keys);
}

/** Same mechanism for the ACCESSORY rows the add-on shelves replace
 * (crossbody, wrist strap, grips, stands). Applied only where a shelf
 * renders; charms and anything not listed stay visible in WCPA. */
function galado_bundles_wcpa_hide_keys_addons() {
    $raw = get_option('galado_bundles_wcpa_hide_keys_addons', "crossbody\nwrist strap\nphone grip\nring stand\nclip-on\nclip on\nadd on charm");
    $keys = array_filter(array_map('trim', explode("\n", strtolower((string) $raw))));
    return array_values($keys);
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
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-combos.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-addons.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-app.php';
require_once GALADO_BUNDLES_PATH . 'includes/class-bundles-extras.php';

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

    // Cart, fee and analytics hooks register ALWAYS, but every cart-touching
    // path guards on galado_bundles_can_transact() at request time: full
    // behaviour for everyone once the storefront is on, full behaviour for
    // staff while dark, and exactly today's nothing for customers while dark.
    GALADO_Bundles_Cart::init();
    GALADO_Bundles_Discount::init();
    GALADO_Bundles_Analytics::init();

    if (galado_bundles_storefront_enabled()) {
        GALADO_Bundles_Storefront::init();
        // Signals #95 to stand down (its Phase-2 guard checks this constant).
        // Deliberately NOT defined while dark, even for staff transactions:
        // #95 must keep serving real customers until the actual cutover.
        if (!defined('GALADO_BUNDLES_OWNS_CART')) {
            define('GALADO_BUNDLES_OWNS_CART', true);
        }
    }

    // PDP protector combos: the render hook registers always and gates itself
    // (customers need storefront + combos ON; staff get a preview while dark).
    GALADO_Bundles_Combos::init();

    // PDP accessory add-on shelves, rendered below the combos. Same gating
    // model behind its own toggle.
    GALADO_Bundles_Addons::init();

    // Independent UX extras: WCPA price-summary relabel + mobile cart sticky
    // CTA. Own toggles, no dependency on the bundles storefront.
    GALADO_Bundles_Extras::init();
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
        update_option('galado_bundles_storefront_enabled', isset($_POST['storefront_enabled']) ? '1' : '0');
        update_option('galado_bundles_combos_enabled', isset($_POST['combos_enabled']) ? '1' : '0');
        update_option('galado_bundles_addons_enabled', isset($_POST['addons_enabled']) ? '1' : '0');
        update_option('galado_bundles_sticky_cart', isset($_POST['sticky_cart']) ? '1' : '0');
        update_option('galado_bundles_wcpa_hide_keys', sanitize_textarea_field(wp_unslash($_POST['wcpa_hide_keys'] ?? '')));
        update_option('galado_bundles_wcpa_hide_keys_addons', sanitize_textarea_field(wp_unslash($_POST['wcpa_hide_keys_addons'] ?? '')));
        echo '<div class="notice notice-success"><p>Saved. Purge caches after switching customer-facing toggles.</p></div>';
    }
    if (isset($_POST['galado_bundles_seed']) && check_admin_referer('galado_bundles_settings')) {
        $made = GALADO_Bundles_Combos::seed_launch_combos();
        echo '<div class="notice notice-success"><p>' . esc_html($made > 0
            ? $made . ' protector combo draft(s) created. Review the prices, then Publish each one.'
            : 'The launch combos already exist; nothing was created.') . '</p></div>';
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
          <tr>
            <th>PDP protector combos</th>
            <td>
              <label><input type="checkbox" name="combos_enabled" value="1" <?php checked(galado_bundles_combos_enabled()); ?>> Show the "Protect your phone" combo module on phone-case product pages</label>
              <p class="description">Needs the storefront ON to take orders (the cart saving engine lives behind it). While the storefront is dark, staff see a preview on case PDPs; customers see nothing.</p>
            </td>
          </tr>
          <tr>
            <th>PDP accessory add-ons</th>
            <td>
              <label><input type="checkbox" name="addons_enabled" value="1" <?php checked(galado_bundles_addons_enabled()); ?>> Show the accessory add-on shelves on phone-case product pages, below the protector combos</label>
              <p class="description">Circle-first add-ons (straps, grips, stands) replacing the WCPA purchase-with-purchase rows; WCPA keeps name customisation. Needs the storefront ON to take orders; staff preview while dark.</p>
            </td>
          </tr>
          <tr>
            <th>WCPA fields hidden next to add-on shelves</th>
            <td>
              <textarea name="wcpa_hide_keys_addons" rows="4" class="large-text"><?php echo esc_textarea((string) get_option('galado_bundles_wcpa_hide_keys_addons', "crossbody\nwrist strap\nphone grip\nring stand\nclip-on\nclip on\nadd on charm")); ?></textarea>
              <p class="description">One entry per line. Where an add-on shelf shows, WCPA rows matching an entry are hidden. Name customisation and anything unlisted stay.</p>
            </td>
          </tr>
          <tr>
            <th>Mobile cart sticky checkout</th>
            <td>
              <label><input type="checkbox" name="sticky_cart" value="1" <?php checked(galado_bundles_sticky_cart_enabled()); ?>> Keep a Continue to Checkout bar fixed at the bottom of the cart on mobile</label>
              <p class="description">Independent of the storefront switch; safe to enable on its own.</p>
            </td>
          </tr>
          <tr>
            <th>WCPA fields hidden next to combos</th>
            <td>
              <textarea name="wcpa_hide_keys" rows="4" class="large-text"><?php echo esc_textarea((string) get_option('galado_bundles_wcpa_hide_keys', "tempered glass\nlens protector\ng-armor\ncamera plateau")); ?></textarea>
              <p class="description">One entry per line. On pages where the combo module shows, WCPA add-on rows whose name matches an entry are hidden so protectors are not sold twice. Charm/strap add-ons stay.</p>
            </td>
          </tr>
        </table>
        <p>
          <button type="submit" name="galado_bundles_save" value="1" class="button button-primary">Save</button>
          <button type="submit" name="galado_bundles_seed" value="1" class="button" style="margin-left:8px">Seed the 3 launch combos (drafts)</button>
        </p>
      </form>
    </div>
    <?php
}
