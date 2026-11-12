<?php
/**
 * galado-warranty: a Shopee registration must carry a Shopee Order ID (v1.12.1).
 * Run: php tests/warranty-shopee-order-id.test.php  (exits 1 on any failure)
 *
 * Customers kept registering their SPX tracking number instead: 22 Shopee
 * registrations did, and 21 of them were rejected at review (21 of the 28
 * rejections ever). The rule lives in GWARR_Marketplaces; the form handler is
 * driven here with a crafted POST, exactly what arrives with JavaScript off.
 * Lives outside the plugin folder, so Git Sync never ships it to the store.
 */

define('ABSPATH', __DIR__);

// ---------------------------------------------------------------- WP stubs
$GLOBALS['inserts'] = [];
function add_shortcode() {}
function add_action() {}
function gwarr_mark() {}
function is_user_logged_in() { return true; }
function get_current_user_id() { return 7; }
function wp_unslash($v) { return $v; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function get_option($k, $d = false) { return $d; }
function is_wp_error($x) { return false; }
class GWARR_DB {
    public static function insert($row) { $GLOBALS['inserts'][] = $row; return 41; }
    public static function find($id) { return null; }
}

require __DIR__ . '/../galado-warranty/includes/class-warranty-marketplaces.php';
require __DIR__ . '/../galado-warranty/public/register-shortcode.php';

// ----------------------------------------------------------------- runner
$failed = 0;
function check($label, $cond) {
    global $failed;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$cond) $failed++;
}
// 9 Oct 2026, 15:00 in Kuala Lumpur: "tomorrow" there is 10 Oct.
$now = new DateTimeImmutable('2026-10-09 15:00:00', new DateTimeZone('Asia/Kuala_Lumpur'));
function problem($order, $slug = 'shopee') {
    global $now;
    return GWARR_Marketplaces::order_number_problem($slug, $order, $now);
}

echo "the rule (Shopee)\n";
foreach (['26100952KDU4JS', ' 2610084uxrf9ku ', '260609KXBRPS2K'] as $ok) {
    check("accepts '{$ok}'", problem($ok) === null);
}
check('accepts it with spaces pasted in the middle', problem('2610 0952 KDU4JS') === null);
check('accepts it with a # in front (one live registration had one)', problem('#260909KXBRPS2K') === null);
foreach (['SPXMY0412345678', 'SPX123', 'spxmy0412345678', ' SPX 123 '] as $spx) {
    check("'{$spx}' is a tracking number", problem($spx) === 'tracking');
}
foreach ([
    '630012345678'    => '12 digits',
    '261399KXBRPS2K'  => 'month 13',
    '260231KXBRPS2K'  => '31 Feb',
    '260000KXBRPS2K'  => 'day 0',
    '26100952KDU4J'   => '13 characters',
    '26100952KDU4JSX' => '15 characters',
    'KDU4JS26100952'  => 'no date in front',
    '2610095-KDU4JS'  => 'a hyphen inside',
    '#'               => 'nothing but a #',
] as $bad => $why) {
    check("'{$bad}' ({$why}) is not an Order ID", problem($bad) === 'format');
}
check('29 Feb is real in 2024', problem('240229KXBRPS2K') === null);

echo "pasted spaces: the same characters script.js strips (its \\s)\n";
foreach ([
    "2610\u{00A0}0952KDU4JS" => 'a non-breaking space inside',
    "26100952KDU4JS\u{3000}" => 'an ideographic space after',
    "\u{FEFF}26100952KDU4JS" => 'a byte-order mark in front',
    "2610\u{2028}0952KDU4JS" => 'a line separator inside',
    "2610\u{202F}0952\t\nKDU4JS" => 'a narrow space, a tab and a newline',
] as $pasted => $why) {
    check("accepts it with {$why}", problem($pasted) === null && GWARR_Marketplaces::normalise_order_number('shopee', $pasted) === '26100952KDU4JS');
}
check('a lone # is not an Order ID (and not an empty field)', problem('# ') === 'format');
check('only ASCII letters are upper-cased, like the browser now does (dotless i stays)', problem("26100952KDU4J\u{0131}") === 'format');
check('29 Feb is not real in 2025', problem('250229KXBRPS2K') === 'format');

echo "the date may be up to tomorrow in KL\n";
check('today is fine', problem('261009KXBRPS2K') === null);
check('tomorrow is fine (one day of slack)', problem('261010KXBRPS2K') === null);
check('the day after tomorrow is refused', problem('261011KXBRPS2K') === 'format');
check('next year is refused', problem('270101KXBRPS2K') === 'format');
// 9 Oct 23:30 UTC is already 10 Oct 07:30 in KL, so tomorrow there is 11 Oct.
$late_utc = new DateTimeImmutable('2026-10-09 23:30:00', new DateTimeZone('UTC'));
check('"tomorrow" is counted in KL time, not the server clock',
    GWARR_Marketplaces::order_number_problem('shopee', '261011KXBRPS2K', $late_utc) === null
    && GWARR_Marketplaces::order_number_problem('shopee', '261012KXBRPS2K', $late_utc) === 'format');
check('the date the form hands the browser is that same tomorrow', GWARR_Marketplaces::order_max_date($now) === '2026-10-10');

echo "the other marketplaces stay as they were\n";
foreach (['lazada', 'tiktok', 'retail'] as $slug) {
    check("{$slug} has no rule", problem('SPX123', $slug) === null && problem('anything at all', $slug) === null);
    check("{$slug} numbers are only trimmed", GWARR_Marketplaces::normalise_order_number($slug, ' gt2-422 ab ') === 'gt2-422 ab');
}
check('only Shopee carries a rule for the form', array_keys(GWARR_Marketplaces::order_rules()) === ['shopee']);

echo "what gets saved\n";
check('a Shopee number is saved trimmed, without spaces or #, in capitals',
    GWARR_Marketplaces::normalise_order_number('shopee', ' #2610 0952 kdu4js ') === '26100952KDU4JS');

echo "the copy\n";
$tracking = GWARR_Marketplaces::order_number_message('shopee', 'tracking');
$format   = GWARR_Marketplaces::order_number_message('shopee', 'format');
check('the tracking-number message, word for word', $tracking === 'That looks like your SPX tracking number, not your Shopee Order ID. Your Order ID has 14 characters and starts with your order date, like 260609KXBRPS2K. In the Shopee app, go to Me > My Purchases, open the order and copy the Order ID.');
check('the format message, word for word', $format === "That doesn't look like a Shopee Order ID. It has 14 characters and starts with your order date, like 260609KXBRPS2K. In the Shopee app, go to Me > My Purchases, open the order and copy the Order ID.");
check('no em or en dashes in either', !preg_match('/[\x{2013}\x{2014}]/u', $tracking . $format));
check('the pattern handed to the browser is the one PHP uses',
    @preg_match('/' . GWARR_Marketplaces::order_rules()['shopee']['pattern'] . '/', '') !== false
    && GWARR_Marketplaces::order_rules()['shopee']['pattern'] === '^\d{6}[A-Z0-9]{8}$');

echo "the form, with JavaScript off (a crafted POST)\n";
function post($marketplace, $order) {
    $GLOBALS['inserts'] = [];
    $_POST = ['marketplace' => $marketplace, 'order_number' => $order, 'notes' => '', 'marketing_consent' => '1'];
    return gwarr_handle_form_submission();
}
$r = post('shopee', 'SPXMY0412345678');
check('an SPX number is refused', $r['ok'] === false);
check('with the tracking-number message', strpos($r['notice'], esc_html($tracking)) !== false && strpos($r['notice'], 'gwarr-notice-error') !== false);
check('and nothing is saved', $GLOBALS['inserts'] === []);
$r = post('shopee', '261399KXBRPS2K');
check('a number that is not an Order ID gets the format message and is not saved', $r['ok'] === false && strpos($r['notice'], esc_html($format)) !== false && $GLOBALS['inserts'] === []);
$r = post('shopee', ' 2610 0952 kdu4js ');
check('a real Order ID goes through', $r['ok'] === true);
check('saved in the normalised form', ($GLOBALS['inserts'][0]['order_number'] ?? null) === '26100952KDU4JS');
$r = post('lazada', 'SPX123');
check('Lazada is unchanged: saved exactly as typed (trimmed)', $r['ok'] === true && ($GLOBALS['inserts'][0]['order_number'] ?? null) === 'SPX123');
$r = post('shopee', '');
check('an empty number still gets the old message', $r['ok'] === false && strpos($r['notice'], 'Order number is required.') !== false && $GLOBALS['inserts'] === []);

echo $failed ? "\n{$failed} FAILED\n" : "\nall passed\n";
exit($failed ? 1 : 0);
