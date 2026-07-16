<?php
/**
 * Plugin Name: GALADO Studio Cart
 * Description: Bridge between GALADO Studio (studio-api) and WooCommerce: validated add-to-cart for Studio Case artwork, order meta display, and the order webhook back to Studio. Spec: SPEC-STUDIO.md section 6 item 2.
 * Version: 0.2.3
 * Author: GALADO
 */

if (!defined('ABSPATH')) exit;

define('GSTUDIO_VERSION', '0.2.3');
define('GSTUDIO_PATH', plugin_dir_path(__FILE__));
define('GSTUDIO_URL', plugin_dir_url(__FILE__));

/**
 * Settings. The shared HMAC secret prefers the wp-config constant
 * GALADO_STUDIO_SECRET; the option is the fallback so ops can rotate from
 * the dashboard if ever needed.
 */
function gstudio_settings() {
    $defaults = [
        'api_base'          => 'https://studio.galado.com.my',
        'secret'            => '',
        'product_id'        => 0,
        'turnstile_sitekey' => '',
        'page_slug'         => 'studio',
    ];
    $opts = get_option('gstudio_settings', []);
    return wp_parse_args(is_array($opts) ? $opts : [], $defaults);
}

function gstudio_secret() {
    if (defined('GALADO_STUDIO_SECRET') && GALADO_STUDIO_SECRET) {
        return (string) GALADO_STUDIO_SECRET;
    }
    $s = gstudio_settings();
    return (string) $s['secret'];
}

function gstudio_api_base() {
    $s = gstudio_settings();
    return rtrim((string) $s['api_base'], '/');
}

require_once GSTUDIO_PATH . 'includes/class-studio-token.php';
require_once GSTUDIO_PATH . 'includes/class-studio-cart.php';
require_once GSTUDIO_PATH . 'includes/class-studio-webhook.php';
require_once GSTUDIO_PATH . 'includes/class-studio-page.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>GALADO Studio Cart needs WooCommerce active.</p></div>';
        });
        return;
    }
    GSTUDIO_Cart::init();
    GSTUDIO_Webhook::init();
    GSTUDIO_Page::init();
});

// HPOS compatibility declaration (same as the other GALADO plugins).
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// ---- Minimal settings page (Settings > Studio Cart) -------------------------

add_action('admin_menu', function () {
    add_options_page('Studio Cart', 'Studio Cart', 'manage_options', 'gstudio-settings', 'gstudio_render_settings');
});

function gstudio_render_settings() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['gstudio_save']) && check_admin_referer('gstudio_settings')) {
        $s = gstudio_settings();
        $s['api_base']          = esc_url_raw(wp_unslash($_POST['api_base'] ?? $s['api_base']));
        $s['product_id']        = absint($_POST['product_id'] ?? $s['product_id']);
        $s['turnstile_sitekey'] = sanitize_text_field(wp_unslash($_POST['turnstile_sitekey'] ?? ''));
        $s['page_slug']         = sanitize_title(wp_unslash($_POST['page_slug'] ?? 'studio'));
        if (!defined('GALADO_STUDIO_SECRET') && !empty($_POST['secret'])) {
            $s['secret'] = sanitize_text_field(wp_unslash($_POST['secret']));
        }
        update_option('gstudio_settings', $s, false);
        echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }
    $s = gstudio_settings();
    $secret_note = defined('GALADO_STUDIO_SECRET')
        ? '<em>Set in wp-config.php (constant wins; field disabled).</em>'
        : '<input type="password" name="secret" value="" autocomplete="new-password" class="regular-text" placeholder="' . ($s['secret'] ? 'saved, enter to replace' : 'shared secret') . '">';
    ?>
    <div class="wrap">
      <h1>GALADO Studio Cart</h1>
      <form method="post">
        <?php wp_nonce_field('gstudio_settings'); ?>
        <table class="form-table" role="presentation">
          <tr><th>Studio API base</th><td><input type="url" name="api_base" value="<?php echo esc_attr($s['api_base']); ?>" class="regular-text"></td></tr>
          <tr><th>Studio Case product ID</th><td><input type="number" name="product_id" value="<?php echo (int) $s['product_id']; ?>" class="small-text"> <em>variable product; variation SKU = <code>studio-&lt;model_id&gt;</code></em></td></tr>
          <tr><th>Turnstile site key</th><td><input type="text" name="turnstile_sitekey" value="<?php echo esc_attr($s['turnstile_sitekey']); ?>" class="regular-text"></td></tr>
          <tr><th>Studio page slug</th><td><input type="text" name="page_slug" value="<?php echo esc_attr($s['page_slug']); ?>" class="regular-text"></td></tr>
          <tr><th>Shared secret</th><td><?php echo $secret_note; // phpcs:ignore ?></td></tr>
        </table>
        <p><button type="submit" name="gstudio_save" value="1" class="button button-primary">Save</button></p>
      </form>
    </div>
    <?php
}
