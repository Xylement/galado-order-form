<?php
/**
 * robots.txt as galado.com.my serves it: allowed AI crawlers must follow the shop's crawl-trap
 * rules, blocked ones stay blocked, everyone else is unaffected.
 *   php tests/unit/test-robots.php
 */
require __DIR__ . '/bootstrap.php';

/** The 1.0.0 handler, verbatim: one "User-agent: <bot> / Allow: /" group per allowed crawler. */
function gaic_v100_robots_txt($output, $public) {
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

$GLOBALS['__options']['gaic_crawlers'] = live_settings();
$old = served_robots('gaic_v100_robots_txt');
$new = served_robots('gaic_modify_robots_txt');

// The simulated chain is production: with the 1.0.0 handler it rebuilds, byte for byte, the
// robots.txt galado.com.my served on 9 Oct 2026.
check('chain with the 1.0.0 handler == live robots.txt of 9 Oct 2026',
    $old, file_get_contents(dirname(__DIR__) . '/fixtures/robots-live-20261009.txt'));

$traps = [
    '/product-category/apple/iphone/iphone-14-pro-max/?filter_design-colour=red',
    '/product-category/apple/iphone/?filter_design-theme=cute&query_type_design-theme=or',
    '/product-category/samsung/?query_type_design-colour=or',
    '/product-category/phone-charm/?orderby=price&filter_design-colour=gold',
    '/?add-to-cart=426004',
    '/product/rose-reverie/?add-to-cart=1&quantity=2',
    '/wp-admin/options.php',
];
$open = [
    '/', '/product/rose-reverie/', '/product-category/apple/', '/product-category/apple/iphone/iphone-18-pro/',
    '/product-category/phone-charm/?orderby=price', '/wp-admin/admin-ajax.php', '/llms.txt',
    '/sitemap_index.xml', '/about/', '/?s=charm',
];
$allowed = array_keys(array_filter(live_settings(), function ($s) { return $s === 'allow'; }));
$blocked = array_keys(array_filter(live_settings(), function ($s) { return $s !== 'allow'; }));

// Negative control: the 1.0.0 output let allowed AI crawlers into the traps. If these ever pass
// as false, the evaluator stopped seeing the bug and every check below is meaningless.
check('1.0.0: GPTBot may fetch a filter URL (the bug)', rp_allowed($old, 'GPTBot', $traps[0]), true);
check('1.0.0: ClaudeBot may fetch an add-to-cart link (the bug)', rp_allowed($old, 'ClaudeBot', $traps[4]), true);
check('1.0.0: PerplexityBot may fetch wp-admin (the bug)', rp_allowed($old, 'PerplexityBot', $traps[6]), true);

foreach ($allowed as $bot) {
    foreach ($traps as $u) check("{$bot} kept out of {$u}", rp_allowed($new, $bot, $u), false);
    foreach ($open as $u) check("{$bot} may fetch {$u}", rp_allowed($new, $bot, $u), true);
}

foreach ($blocked as $bot) {
    foreach (['/', '/product/rose-reverie/', '/llms.txt'] as $u) {
        check("{$bot} still blocked from {$u}", rp_allowed($new, $bot, $u), false);
    }
}

// OAI-AdsBot keeps the explicit group OpenAI's ad review asks for (added by snippet #414).
check('OAI-AdsBot keeps "User-agent: OAI-AdsBot / Allow: /"', (bool) preg_match("~^User-agent: OAI-AdsBot\nAllow: /\n~m", $new), true);
check('OAI-AdsBot may fetch a product page', rp_allowed($new, 'OAI-AdsBot', '/product/rose-reverie/'), true);

// Crawlers the plugin does not manage see no change at all.
foreach (['Googlebot', 'bingbot', 'Applebot', 'Amazonbot', 'OAI-AdsBot'] as $bot) {
    foreach (array_merge($traps, $open) as $u) {
        check("{$bot} unchanged on {$u}", rp_allowed($new, $bot, $u), rp_allowed($old, $bot, $u));
    }
}

// Output contract.
$GLOBALS['__options']['gaic_crawlers'] = live_settings();
$piece = gaic_modify_robots_txt(base_robots(), true);
check('the incoming robots.txt is kept as a prefix', strpos($piece, base_robots()), 0);
check('no group of its own for an allowed crawler', preg_match('/^User-agent: (GPTBot|ClaudeBot|PerplexityBot)\s*$/mi', $piece), 0);
check('allowed crawlers named in a comment, in settings order',
    (bool) preg_match('/^# Allowed AI crawlers \(they follow the general rules above\): GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, anthropic-ai, PerplexityBot, Google-Extended, Applebot-Extended$/m', $piece), true);
check('blocked crawlers keep their group', substr_count($piece, "\nDisallow: /\n"), count($blocked));

$GLOBALS['__options']['gaic_crawlers'] = [];
check('no settings: robots.txt untouched', gaic_modify_robots_txt(base_robots(), true), base_robots());

$GLOBALS['__options']['gaic_crawlers'] = ['GPTBot' => 'allow'];
check('only allowed crawlers: no blocked section', strpos(gaic_modify_robots_txt(base_robots(), true), 'Blocked'), false);

$GLOBALS['__options']['gaic_crawlers'] = ['CCBot' => 'disallow'];
check('only blocked crawlers: no allowed comment', strpos(gaic_modify_robots_txt(base_robots(), true), 'Allowed'), false);

done();
