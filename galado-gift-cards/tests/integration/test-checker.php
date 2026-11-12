<?php
/**
 * The "check your gift card" shortcode, run as a real page render: each answer, the nonce, the
 * rate limit, and that the answer never repeats the code.
 */
require __DIR__ . '/bootstrap.php';

gct_product();
// Headers can only be sent before any output, as on a real page load, so this runs first.
$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Check your gift card', 'post_content' => '[galado_gift_card_check]']);
$plain = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => 'Hello']);
$nocache = 0;
add_filter('nocache_headers', function ($h) use (&$nocache) { $nocache++; return $h; });
foreach ([$page, $plain] as $id) {
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $id]);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    Galado_GC_Checker::no_cache();
}
$post = function ($code, $ip = '203.0.113.1', $nonce = null) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_POST = wp_slash(['galado_gc_code' => $code, 'galado_gc_check_nonce' => $nonce ?? wp_create_nonce(Galado_GC_Checker::NONCE)]);
    $html = do_shortcode('[galado_gift_card_check]');
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    preg_match('#<div class="galado-gc-check-result[^"]*"[^>]*>\s*<p>(.*?)</p>#s', $html, $m);
    return [html_entity_decode($m[1] ?? '', ENT_QUOTES, 'UTF-8'), $html];
};
$card = function ($value = 100) {
    return strtoupper(gct_coupons(gct_paid_order([['value' => $value, 'label' => 'Custom amount']]))[0]->get_code());
};
$tz = new DateTimeZone('Asia/Kuala_Lumpur');

echo "-- the page is never cached\n";
check('no-cache headers on the check page only (negative control: the About page)', $nocache, 1);

echo "-- the form\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$form = do_shortcode('[galado_gift_card_check]');
check('a POST form with a nonce, the code box masked for Clarity, no answer yet', [
    false !== strpos($form, '<form method="post"'), false !== strpos($form, 'name="galado_gc_check_nonce"'),
    (bool) preg_match('/name="galado_gc_code"[^>]*data-clarity-mask="True"/', $form), false !== strpos($form, 'galado-gc-check-result'),
], [true, true, true, false]);

echo "-- each answer\n";
$valid = $card(150);
$expiry = (new WC_Coupon(strtolower($valid)))->get_date_expires()->setTimezone($tz)->format('j F Y');
[$msg, $html] = $post('  ' . strtolower($valid) . ' ');
check('valid (typed in lower case with spaces): value and expiry',
    $msg, "This gift card is worth RM150 and can be used until {$expiry}. Single use: spend it in one order. Any unused value is lost.");
check('the page never repeats the code', stripos($html, $valid), false);
$used = $card();
(new WC_Coupon(strtolower($used)))->increase_usage_count('someone@example.test');
check('used', $post($used, '203.0.113.2')[0], 'This gift card has already been used.');
$expired = $card();
$ec = new WC_Coupon(strtolower($expired));
$ec->set_date_expires(strtotime('2025-03-01 23:59:59 Asia/Kuala_Lumpur'));
$ec->save();
check('expired, with the date', $post($expired, '203.0.113.2')[0], 'This gift card expired on 1 March 2025.');
check('unknown code', $post('GIFT-2345-6789-ABCD', '203.0.113.2')[0], 'We couldn’t find that gift card. Check the code and try again.');
check('not a code at all', $post('hello', '203.0.113.2')[0], 'We couldn’t find that gift card. Check the code and try again.');
$o = gct_paid_order([['value' => 100]]);
$disabled = strtoupper(gct_coupons($o)[0]->get_code());
$o->update_status('refunded');
check('a disabled (refunded) card reads as not found', $post($disabled, '203.0.113.3')[0], 'We couldn’t find that gift card. Check the code and try again.');
$promo = new WC_Coupon(0); // not new WC_Coupon(): Points and Rewards turns that into a virtual coupon
$promo->set_code('GIFT-AAAA-BBBB-CCCC'); // looks like a card but was never issued as one
$promo->set_amount('50');
$promo->save();
check('a coupon that only looks like a card reads as not found', $post('GIFT-AAAA-BBBB-CCCC', '203.0.113.3')[0], 'We couldn’t find that gift card. Check the code and try again.');
check('negative control: the same page finds a real card', $post($valid, '203.0.113.3')[0] !== 'We couldn’t find that gift card. Check the code and try again.', true);

echo "-- a stale or missing nonce asks again and counts nothing\n";
[$msg, $html] = $post($valid, '203.0.113.4', 'stale');
check('asks for the code again, with a fresh form', [$msg, false !== strpos($html, 'name="galado_gc_code"')], ['Please enter the code again.', true]);
check('... and that attempt did not use up a check', get_transient('galado_gc_chk_' . md5('203.0.113.4|' . gmdate('YmdH'))), false);

echo "-- 10 checks per IP per hour\n";
$answers = [];
for ($i = 1; $i <= 11; $i++) {
    $answers[] = $post('GIFT-2345-6789-ABCD', '198.51.100.7')[0];
}
check('checks 1 to 10 are answered', count(array_filter(array_slice($answers, 0, 10), function ($a) { return false !== strpos($a, 'couldn’t find'); })), 10);
check('check 11 is refused', $answers[10], 'You have checked a lot of codes. Please try again in an hour.');
check('... even for a real card', $post($valid, '198.51.100.7')[0], 'You have checked a lot of codes. Please try again in an hour.');
check('another visitor is not affected', false !== strpos($post($valid, '198.51.100.8')[0], 'worth RM150'), true);
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.50';
check('behind Cloudflare the visitor address counts, not the proxy', Galado_GC_Checker::client_ip(), '192.0.2.50');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
check('a malformed Cloudflare header falls back to the connection address', Galado_GC_Checker::client_ip(), $_SERVER['REMOTE_ADDR']);
unset($_SERVER['HTTP_CF_CONNECTING_IP']);


done();
