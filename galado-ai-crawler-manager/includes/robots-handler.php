<?php
if (!defined('ABSPATH')) exit;

/**
 * Modify robots.txt output with AI crawler rules
 *
 * Allowed crawlers get no group of their own. Under RFC 9309 a crawler obeys only the group
 * that names it and ignores "User-agent: *", so the old "User-agent: <bot> / Allow: /" group
 * exempted every allowed AI crawler from the shop's crawl-trap rules in the * group (layered-nav
 * filter URLs, add-to-cart links, wp-admin). Without a group of their own they follow
 * "User-agent: *" like every other crawler, which allows the whole shop except those traps.
 * Blocked crawlers keep their own "Disallow: /" group.
 */
function gaic_modify_robots_txt($output, $public) {
    $settings = get_option('gaic_crawlers', []);
    if (empty($settings)) return $output;

    $allowed = [];
    $blocked = [];

    foreach ($settings as $bot => $status) {
        if ($status === 'allow') {
            $allowed[] = $bot;
        } else {
            $blocked[] = $bot;
        }
    }

    $rules = "\n# GALADO AI Crawler Manager\n";

    if (!empty($allowed)) {
        $rules .= "# Allowed AI crawlers (they follow the general rules above): " . implode(', ', $allowed) . "\n\n";
    }

    if (!empty($blocked)) {
        $rules .= "# Blocked AI Crawlers\n";
        foreach ($blocked as $bot) {
            $rules .= "User-agent: {$bot}\nDisallow: /\n\n";
        }
    }

    return $output . $rules;
}
