<?php
// Studio model guard (2026-10-05): the picker offers only phones the Studio server can print.
// Run with tests/integration/run.sh, which uses the throwaway test WordPress; never the live store.
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function check($cond, $name) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "ok $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
function ids($models) { return array_column($models, 'model_id'); }
function reply($code, $body) {
    return ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
}
function forget() { delete_transient('gstudio_api_models'); }

// A Studio Case product with three phones: two the server can print, one it cannot.
$attr = new WC_Product_Attribute();
$attr->set_name('Model');
$attr->set_options(['iPhone 17 Pro', 'iPhone 18 Pro', 'Galaxy S24 Ultra']);
$attr->set_visible(true);
$attr->set_variation(true);
$p = new WC_Product_Variable();
$p->set_name('Studio Case');
$p->set_attributes([$attr]);
$p->set_status('publish');
$pid = $p->save();
foreach ([['iPhone 17 Pro', 'iphone-17-pro'], ['iPhone 18 Pro', 'iphone-18-pro'], ['Galaxy S24 Ultra', 'samsung-galaxy-s24-ultra']] as $v) {
    $var = new WC_Product_Variation();
    $var->set_parent_id($pid);
    $var->set_attributes(['model' => $v[0]]);
    $var->set_sku('studio-' . $v[1]);
    $var->set_regular_price('169');
    $var->set_status('publish');
    $var->save();
}
update_option('gstudio_settings', ['api_base' => 'https://studio.test', 'product_id' => $pid, 'secret' => '', 'turnstile_sitekey' => '', 'page_slug' => 'studio']);
delete_option('gstudio_api_models');
forget();

$calls = 0;
$next = null;
add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls, &$next) {
    if (0 !== strpos($url, 'https://studio.test/v1/models')) return $pre;
    $calls++;
    return $next;
}, 10, 3);

$all = ['iphone-17-pro', 'iphone-18-pro', 'samsung-galaxy-s24-ultra'];
$printable = ['iphone-17-pro', 'samsung-galaxy-s24-ultra'];

// 1. A good answer filters the picker and names the hidden phone.
$next = reply(200, json_encode(['models' => ['iphone-11', 'iphone-17-pro', 'samsung-galaxy-s24-ultra']]));
check(ids(GSTUDIO_Page::models()) === $printable, 'good answer: picker keeps only printable phones');
check(ids(GSTUDIO_Page::hidden_models()) === ['iphone-18-pro'], 'good answer: iPhone 18 Pro is the hidden one');
check($calls === 1, 'good answer: one request for both questions (cached)');
check(get_option('gstudio_api_models') === ['iphone-11', 'iphone-17-pro', 'samsung-galaxy-s24-ultra'], 'good answer: kept as the last good list');
$labels = array_column(GSTUDIO_Page::models(), 'label');
check($labels === ['iPhone 17 Pro', 'Galaxy S24 Ultra'], 'labels come from the variation attribute');
check(ids(GSTUDIO_Page::config()['models']) === $printable, 'the page config carries the filtered list');

// 2. Server down after a good answer: the last good list holds, and it is not asked on every view.
forget();
$next = new WP_Error('http_request_failed', 'cURL error 28: timed out');
check(ids(GSTUDIO_Page::models()) === $printable, 'server down: last good list still hides iPhone 18 Pro');
$before = $calls;
GSTUDIO_Page::models();
GSTUDIO_Page::models();
check($calls === $before, 'server down: asked again only after a minute, not per view');

// 3. Server down before any good answer ever: unfiltered (the old behaviour), no hammering.
forget();
delete_option('gstudio_api_models');
check(ids(GSTUDIO_Page::models()) === $all, 'never answered: picker unfiltered');
check(GSTUDIO_Page::hidden_models() === [], 'never answered: nothing reported hidden');
$before = $calls;
GSTUDIO_Page::models();
check($calls === $before, 'never answered: the miss is cached too');

// 4. Answers that are not a usable list count as no answer.
foreach ([
    'empty list' => reply(200, json_encode(['models' => []])),
    'server error' => reply(500, json_encode(['error_code' => 'INTERNAL'])),
    'not json' => reply(200, '<html>Cloudflare challenge</html>'),
    'wrong shape' => reply(200, json_encode(['models' => 'iphone-17-pro'])),
] as $name => $r) {
    forget();
    delete_option('gstudio_api_models');
    $next = $r;
    check(ids(GSTUDIO_Page::models()) === $all, "$name: treated as no answer (unfiltered)");
    check(false === get_option('gstudio_api_models'), "$name: not stored as the last good list");
}

// 5. Junk entries are dropped, good ones kept.
forget();
$next = reply(200, json_encode(['models' => ['iphone-17-pro', 5, '<script>x</script>', 'IPHONE-18-PRO', null]]));
check(ids(GSTUDIO_Page::models()) === ['iphone-17-pro'], 'junk ids dropped');

// 6. Staff note on the Studio Case product screen only.
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
forget();
$next = reply(200, json_encode(['models' => $printable]));
set_current_screen('product');
$_GET['post'] = (string) $pid;
ob_start();
GSTUDIO_Page::hidden_models_notice();
$note = ob_get_clean();
check(false !== strpos($note, 'iPhone 18 Pro is hidden from customers in the Studio'), 'note names the hidden phone (singular)');
check(false !== strpos($note, 'for this phone yet, so its designs') && false !== strpos($note, 'It will be offered again once'), 'note grammar (singular)');
check(false === strpos($note, "\u{2014}") && false === strpos($note, "\u{2013}"), 'note has no dashes');

forget();
$next = reply(200, json_encode(['models' => ['iphone-17-pro']]));
ob_start();
GSTUDIO_Page::hidden_models_notice();
$note = ob_get_clean();
check(false !== strpos($note, 'iPhone 18 Pro, Galaxy S24 Ultra are hidden') && false !== strpos($note, 'They will be offered again once'), 'note grammar (plural)');

$_GET['post'] = (string) ($pid + 999);
ob_start();
GSTUDIO_Page::hidden_models_notice();
check('' === ob_get_clean(), 'no note on another product');

$_GET['post'] = (string) $pid;
set_current_screen('dashboard');
ob_start();
GSTUDIO_Page::hidden_models_notice();
check('' === ob_get_clean(), 'no note on other admin screens');

forget();
$next = reply(200, json_encode(['models' => $all]));
set_current_screen('product');
ob_start();
GSTUDIO_Page::hidden_models_notice();
check('' === ob_get_clean(), 'no note when every phone is printable');

echo $GLOBALS['pass'] . " passed, " . $GLOBALS['fail'] . " failed\n";
exit($GLOBALS['fail'] ? 1 : 0);
