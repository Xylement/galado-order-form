<?php
/**
 * Streams the pre-built static feed file.
 *
 * This endpoint does NO catalog work — building is entirely the job of
 * GFBF_Feed_Builder running in WP-Cron. Serving here is just a token check
 * plus readfile() of a static file, so even a heavy crawler hitting this
 * URL costs almost nothing and can never overload the site.
 */

if (!defined('ABSPATH')) exit;

add_action('init', function () {
    if (!isset($_GET['galado_fb_feed'])) {
        return;
    }
    if (!class_exists('GFBF_Feed_Builder')) {
        return;
    }

    $settings = get_option('gfbf_settings', []);
    $token    = isset($settings['token']) ? (string) $settings['token'] : '';

    // Token gate.
    $given = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    if ($token !== '' && !hash_equals($token, $given)) {
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden — invalid or missing feed token.';
        exit;
    }

    $format = isset($_GET['format']) ? sanitize_key((string) $_GET['format']) : 'xml';
    if (!in_array($format, ['xml', 'csv'], true)) {
        $format = 'xml';
    }

    $file = GFBF_Feed_Builder::feed_path($format);

    // No file yet (first build hasn't finished) — tell the caller to retry.
    if (!is_file($file) || filesize($file) === 0) {
        status_header(503);
        header('Retry-After: 3600');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Feed is being generated. Please check back shortly.';
        exit;
    }

    // Discard any buffered output so readfile() streams cleanly.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'application/xml') . '; charset=utf-8');
    header('Content-Disposition: inline; filename="galado-facebook-catalog.' . $format . '"');
    header('Content-Length: ' . filesize($file));
    // Let Cloudflare/edge cache it so repeat pulls don't even reach origin.
    header('Cache-Control: public, max-age=3600');
    header('X-Robots-Tag: noindex');

    readfile($file);
    exit;
}, 1);
