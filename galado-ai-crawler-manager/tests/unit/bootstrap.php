<?php
/**
 * Stubbed WordPress for the robots.txt unit tests. Rebuilds galado.com.my's robots.txt filter
 * chain in priority order (WooCommerce 10, this plugin 100, snippet #304 200, snippet #414 210,
 * Yoast 99999) and judges the result with a small RFC 9309 evaluator, so the tests check what a
 * crawler may actually fetch rather than how the text looks.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('ABSPATH', __DIR__ . '/');

$GLOBALS['__options'] = [];
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['__options']) ? $GLOBALS['__options'][$key] : $default;
}

require dirname(__DIR__, 2) . '/includes/robots-handler.php';

function check($label, $got, $want) {
    if ($got === $want) {
        $GLOBALS['__pass']++;
        echo "ok   {$label}\n";
    } else {
        $GLOBALS['__fail']++;
        echo "FAIL {$label}\n     want " . var_export($want, true) . "\n     got  " . var_export($got, true) . "\n";
    }
}

function done() {
    echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
    exit($GLOBALS['__fail'] ? 1 : 0);
}

/** Option gaic_crawlers as saved on galado.com.my, read 9 Oct 2026. */
function live_settings() {
    return [
        'GPTBot' => 'allow', 'OAI-SearchBot' => 'allow', 'ChatGPT-User' => 'allow',
        'ClaudeBot' => 'allow', 'anthropic-ai' => 'allow', 'PerplexityBot' => 'allow',
        'Google-Extended' => 'allow', 'Applebot-Extended' => 'allow',
        'Meta-ExternalFetcher' => 'disallow', 'Meta-ExternalAgent' => 'disallow', 'CCBot' => 'disallow',
        'Bytespider' => 'disallow', 'cohere-ai' => 'disallow',
    ];
}

/** robots.txt as WordPress and WooCommerce build it on galado.com.my, before priority 100. */
function base_robots() {
    return "User-agent: *\n"
        . "Disallow: /wp-content/uploads/wc-logs/\n"
        . "Disallow: /wp-content/uploads/woocommerce_transient_files/\n"
        . "Disallow: /wp-content/uploads/woocommerce_uploads/\n"
        . "Disallow: /*?add-to-cart=\n"
        . "Disallow: /*?*add-to-cart=\n"
        . "Disallow: /wp-admin/\n"
        . "Allow: /wp-admin/admin-ajax.php\n";
}

/** Live Code Snippet #304 "Robots: disallow faceted filter URLs (2026-09-05)", priority 200, verbatim. */
function snippet_304($output) {
    $rules = "Disallow: /wp-admin/\nDisallow: /*?filter_\nDisallow: /*&filter_\nDisallow: /*?query_type_\nDisallow: /*&query_type_\n";
    if (false !== strpos($output, "Disallow: /wp-admin/\n")) return str_replace("Disallow: /wp-admin/\n", $rules, $output);
    return $output . "\nUser-agent: *\n" . $rules;
}

/** Live Code Snippet #414 "robots.txt names OAI-AdsBot (ChatGPT Ads, 2026-09-24)", priority 210, verbatim. */
function snippet_414($output) {
    if (false !== stripos($output, 'User-agent: OAI-AdsBot')) {
        return $output;
    }
    $group  = "User-agent: OAI-AdsBot\nAllow: /\n\n";
    $anchor = "User-agent: ChatGPT-User\nAllow: /\n\n";
    if (false !== strpos($output, $anchor)) {
        return str_replace($anchor, $anchor . $group, $output);
    }
    return $output . "\n" . $group;
}

/** Yoast SEO's block (priority 99999) as appended on galado.com.my. */
function yoast_block($output) {
    return $output . "\n# START YOAST BLOCK\n# ---------------------------\nUser-agent: *\nDisallow:\n\n"
        . "Sitemap: https://galado.com.my/sitemap_index.xml\n# ---------------------------\n# END YOAST BLOCK";
}

/** The served robots.txt with $handler in this plugin's slot (priority 100). */
function served_robots($handler) {
    $out = base_robots();
    $out = $handler($out, true);
    $out = snippet_304($out);
    $out = snippet_414($out);
    return yoast_block($out);
}

/** Groups per RFC 9309 2.1: consecutive user-agent lines, then their rules. Comments ignored. */
function rp_groups($txt) {
    $groups = [];
    $cur = null;
    $last_was_ua = false;
    foreach (preg_split('/\r\n|\r|\n/', $txt) as $raw) {
        $line = trim(preg_replace('/#.*/', '', $raw));
        if ($line === '' || false === strpos($line, ':')) continue;
        list($key, $value) = array_map('trim', explode(':', $line, 2));
        $key = strtolower($key);
        if ($key === 'user-agent') {
            if ($cur === null || !$last_was_ua) {
                $groups[] = ['agents' => [], 'rules' => []];
                $cur = count($groups) - 1;
            }
            $groups[$cur]['agents'][] = strtolower($value);
            $last_was_ua = true;
        } else {
            if (($key === 'allow' || $key === 'disallow') && $cur !== null) {
                $groups[$cur]['rules'][] = [$key, $value];
            }
            $last_was_ua = false;
        }
    }
    return $groups;
}

/** RFC 9309 2.2.2 path match: "*" is any run of characters, a trailing "$" anchors the end. */
function rp_match($pattern, $path) {
    $anchored = substr($pattern, -1) === '$';
    if ($anchored) $pattern = substr($pattern, 0, -1);
    $re = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';
    return (bool) preg_match($re, $path);
}

/**
 * May $agent fetch $path (path plus query)? Groups naming the agent are merged; with none, the
 * "*" groups are. The longest matching rule wins and Allow wins a tie (RFC 9309 2.2.2).
 */
function rp_allowed($txt, $agent, $path) {
    $groups = rp_groups($txt);
    $rules = [];
    $named = false;
    foreach ($groups as $g) {
        if (in_array(strtolower($agent), $g['agents'], true)) {
            $rules = array_merge($rules, $g['rules']);
            $named = true;
        }
    }
    if (!$named) {
        foreach ($groups as $g) {
            if (in_array('*', $g['agents'], true)) $rules = array_merge($rules, $g['rules']);
        }
    }
    $best = null;
    $best_len = -1;
    foreach ($rules as $rule) {
        list($type, $pattern) = $rule;
        if ($pattern === '' || !rp_match($pattern, $path)) continue;
        $len = strlen($pattern);
        if ($len > $best_len || ($len === $best_len && $type === 'allow')) {
            $best = $type;
            $best_len = $len;
        }
    }
    return $best !== 'disallow';
}
