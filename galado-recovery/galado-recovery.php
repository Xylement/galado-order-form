<?php
/**
 * Plugin Name: GALADO Cart Recovery
 * Description: Guest cart and checkout identity capture. Moves the email field to the top of checkout, captures it on entry, persists the cart server-side, and fires the "Started Checkout" event to Klaviyo so the existing recovery flow (W2GqDu) can reach guests. Replaces the capability lost with Metorik. Spec: GALADO-Cart-Recovery-Identity-Spec-2026-08-03.md.
 * Version: 0.2.0
 * Author: GALADO
 * Text Domain: galado-recovery
 */

if (!defined('ABSPATH')) exit;

define('GALADO_RECOVERY_VERSION', '0.2.0');
define('GALADO_RECOVERY_PATH', plugin_dir_path(__FILE__));
define('GALADO_RECOVERY_URL', plugin_dir_url(__FILE__));

/** Token + retention windows (spec FR-5, FR-7). */
define('GALADO_RECOVERY_TOKEN_TTL', 7 * DAY_IN_SECONDS);
define('GALADO_RECOVERY_RETENTION_DAYS', 90);
define('GALADO_RECOVERY_DEDUPE_TTL', 4 * HOUR_IN_SECONDS);

/**
 * Settings accessor.
 *
 * Capture and sending are deliberately separate switches. Capture (the email
 * field position plus the cart+identity table) is ours and is worth running on
 * its own: it builds the dataset that survives the move off Klaviyo. Sending is
 * a channel choice and defaults to 'none', so nothing is emailed until a
 * channel is picked.
 *
 * Channels:
 *   none         dark. Rows are captured, nothing is sent. Correct while
 *                Klaviyo's own wck-started-checkout.js already fires
 *                Started Checkout for checkout email entry.
 *   klaviyo      server-side Started Checkout on the WooCommerce metric.
 *   galado_send  hands the row + recovery token to GALADO Send (Resend) via
 *                the galado_recovery_send action.
 */
function galado_recovery_settings() {
    $defaults = [
        'enabled'         => '0',      // Phase 1: checkout capture + persistence
        'cart_prompt'     => '0',      // Phase 2: cart-page prompt
        'send_channel'    => 'none',   // none | klaviyo | galado_send
        'klaviyo_api_key' => '',
        'blocklist'       => '',       // one throwaway domain per line, optional
    ];
    $opts = get_option('galado_recovery_settings', []);
    return wp_parse_args(is_array($opts) ? $opts : [], $defaults);
}

function galado_recovery_enabled() {
    $s = galado_recovery_settings();
    return '1' === $s['enabled'];
}

/** Which channel sends recovery events, if any. */
function galado_recovery_send_channel() {
    $s  = galado_recovery_settings();
    $ch = (string) $s['send_channel'];
    return in_array($ch, ['none', 'klaviyo', 'galado_send'], true) ? $ch : 'none';
}

function galado_recovery_sending_enabled() {
    return 'none' !== galado_recovery_send_channel();
}

/**
 * Key encryption at rest (spec FR-8: the key must not sit in the options table
 * in the clear). AES-256-GCM under a key derived from the wp-config salts,
 * which live outside the database, so a leaked DB dump alone does not yield the
 * Klaviyo key. Ciphertext is tagged so plaintext values written by an older
 * build still decrypt transparently.
 */
define('GALADO_RECOVERY_CIPHER_TAG', 'grvenc1:');

function galado_recovery_cipher_key() {
    $salt = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
    if ('' === $salt) return '';
    return hash('sha256', 'galado-recovery|' . $salt, true);
}

function galado_recovery_encrypt($plain) {
    $key = galado_recovery_cipher_key();
    if ('' === (string) $plain || '' === $key || !function_exists('openssl_encrypt')) return (string) $plain;

    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt((string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (false === $ct) return (string) $plain;

    return GALADO_RECOVERY_CIPHER_TAG . base64_encode($iv . $tag . $ct);
}

function galado_recovery_decrypt($stored) {
    $stored = (string) $stored;
    if (0 !== strpos($stored, GALADO_RECOVERY_CIPHER_TAG)) return $stored; // legacy plaintext

    $key = galado_recovery_cipher_key();
    $raw = base64_decode(substr($stored, strlen(GALADO_RECOVERY_CIPHER_TAG)), true);
    if ('' === $key || false === $raw || strlen($raw) < 29 || !function_exists('openssl_decrypt')) return '';

    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return false === $plain ? '' : $plain;
}

/**
 * Klaviyo private API key. Own setting first, then the warranty plugin's
 * already-configured key, then the official Klaviyo plugin's, so this can work
 * with zero re-entry. Never logged, never echoed anywhere unmasked.
 */
function galado_recovery_klaviyo_key() {
    $s = galado_recovery_settings();
    if ('' !== $s['klaviyo_api_key']) return galado_recovery_decrypt($s['klaviyo_api_key']);

    $gwarr = get_option('gwarr_settings', []);
    if (is_array($gwarr) && !empty($gwarr['klaviyo_api_key'])) return $gwarr['klaviyo_api_key'];

    $kl = get_option('klaviyo_settings', []);
    foreach (['private_api_key', 'private_key'] as $k) {
        if (is_array($kl) && !empty($kl[$k])) return $kl[$k];
    }
    return '';
}

/**
 * Increment-and-return a counter, atomically where possible.
 *
 * get_transient() then set_transient() is a read-then-write pair with no
 * locking, so a concurrent burst can all read the same pre-increment value and
 * all pass a rate check. Where a persistent object cache exists, wp_cache_incr
 * is a genuine atomic increment; otherwise fall back to the transient pair,
 * which still bounds abuse but is soft under exact concurrency.
 * Returns the count INCLUDING this call, so callers compare with > limit.
 */
function galado_recovery_bump($key, $ttl) {
    if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
        $n = wp_cache_incr($key, 1, 'galado_recovery');
        if (false === $n) {
            wp_cache_add($key, 1, 'galado_recovery', $ttl);
            $n = wp_cache_incr($key, 0, 'galado_recovery');
            if (false === $n) $n = 1;
        }
        return (int) $n;
    }

    $n = (int) get_transient($key) + 1;
    set_transient($key, $n, $ttl);
    return $n;
}

/** Read a galado_recovery_bump() counter without incrementing it. */
function galado_recovery_peek($key) {
    if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
        return (int) wp_cache_get($key, 'galado_recovery');
    }
    return (int) get_transient($key);
}

require_once GALADO_RECOVERY_PATH . 'includes/class-recovery-db.php';
require_once GALADO_RECOVERY_PATH . 'includes/class-recovery-checkout.php';
require_once GALADO_RECOVERY_PATH . 'includes/class-recovery-rest.php';
require_once GALADO_RECOVERY_PATH . 'includes/class-recovery-klaviyo.php';
require_once GALADO_RECOVERY_PATH . 'includes/class-recovery-restore.php';
require_once GALADO_RECOVERY_PATH . 'includes/class-recovery-prompt.php';
require_once GALADO_RECOVERY_PATH . 'includes/class-recovery-admin.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>GALADO Cart Recovery needs WooCommerce active.</p></div>';
        });
        return;
    }

    // Always on: REST route registration (endpoint no-ops while dark), the
    // recovery-link handler and the purchase close. The last two must keep
    // working even if capture is later switched off, so links in already-sent
    // emails still restore carts and purchases still close their rows.
    GALADO_Recovery_REST::init();
    GALADO_Recovery_Restore::init();
    GALADO_Recovery_Klaviyo::init();
    if (is_admin()) {
        GALADO_Recovery_Admin::init();
    }

    // Customer-facing capture: only when Phase 1 is on.
    if (galado_recovery_enabled()) {
        GALADO_Recovery_Checkout::init();
        $s = galado_recovery_settings();
        if ('1' === $s['cart_prompt']) {
            GALADO_Recovery_Prompt::init();
        }
    }
}, 20);

// HPOS compatibility (same declaration as the other GALADO plugins).
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, function () {
    GALADO_Recovery_DB::create_table();
    if (!wp_next_scheduled('galado_recovery_purge')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'galado_recovery_purge');
    }
    if (false === get_option('galado_recovery_settings', false)) {
        add_option('galado_recovery_settings', []);
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('galado_recovery_purge');
    // Rows stay in the DB (reactivation resumes); data is removed only via the
    // explicit uninstall.php the owner opts into, or the 90-day purge while active.
});

add_action('galado_recovery_purge', ['GALADO_Recovery_DB', 'purge_old']);
