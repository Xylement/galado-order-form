<?php
/**
 * Serves the Facebook product feed when ?galado_fb_feed= is requested.
 *
 * No rewrite rules — a plain query var keeps activation side-effect-free.
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {
    if (!isset($_GET['galado_fb_feed'])) {
        return;
    }

    $settings = get_option('gfbf_settings', []);
    $token    = isset($settings['token']) ? (string) $settings['token'] : '';

    // Token gate — keeps the feed from being trivially scraped.
    $given = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    if ($token !== '' && !hash_equals($token, $given)) {
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden — invalid or missing feed token.';
        exit;
    }

    // Admin-only diagnostic — explains why the feed has the rows it does.
    if (isset($_GET['debug']) && current_user_can('manage_woocommerce')) {
        if (!class_exists('GFBF_Feed_Generator')) {
            require_once GFBF_PATH . 'includes/class-feed-generator.php';
        }
        $generator = new GFBF_Feed_Generator($settings);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo $generator->diagnose();
        exit;
    }

    $format = isset($_GET['format']) ? sanitize_key((string) $_GET['format']) : ($settings['format'] ?? 'xml');
    if (!in_array($format, ['xml', 'csv'], true)) {
        $format = 'xml';
    }

    // Admins can force a rebuild with &refresh=1.
    $force = isset($_GET['refresh']) && current_user_can('manage_woocommerce');

    $cache_key = 'gfbf_feed_' . $format;
    $body = $force ? false : get_transient($cache_key);

    if ($body === false) {
        if (!class_exists('GFBF_Feed_Generator')) {
            require_once GFBF_PATH . 'includes/class-feed-generator.php';
        }
        $generator   = new GFBF_Feed_Generator($settings);
        $body        = $format === 'csv' ? $generator->build_csv() : $generator->build_xml();
        $cache_hours = max(1, intval($settings['cache_hours'] ?? 6));
        set_transient($cache_key, $body, $cache_hours * HOUR_IN_SECONDS);
        update_option('gfbf_last_generated', current_time('mysql'));
    }

    nocache_headers();
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: inline; filename="galado-facebook-catalog.csv"');
    } else {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: inline; filename="galado-facebook-catalog.xml"');
    }

    echo $body;
    exit;
}, 1);

/**
 * Clear both cached feed variants.
 */
function gfbf_clear_cache() {
    delete_transient('gfbf_feed_xml');
    delete_transient('gfbf_feed_csv');
}
