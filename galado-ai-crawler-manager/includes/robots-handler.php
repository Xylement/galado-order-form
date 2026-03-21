<?php
if (!defined('ABSPATH')) exit;

/**
 * Modify robots.txt output with AI crawler rules
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
        $rules .= "# Allowed AI Crawlers\n";
        foreach ($allowed as $bot) {
            $rules .= "User-agent: {$bot}\nAllow: /\n\n";
        }
    }

    if (!empty($blocked)) {
        $rules .= "# Blocked AI Crawlers\n";
        foreach ($blocked as $bot) {
            $rules .= "User-agent: {$bot}\nDisallow: /\n\n";
        }
    }

    return $output . $rules;
}
