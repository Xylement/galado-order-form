<?php
/**
 * Remainder maths and wording; the check page's statuses and rate limit.
 *   php tests/unit/test-remainder-and-check.php
 */
require __DIR__ . '/bootstrap.php';

echo "-- remainder: what a card cannot cover is shown as lost\n";
$r = Galado_GC_Remainder::compute([['value' => 100.0, 'applied' => 60.0]]);
check('RM100 card on a RM60 order: RM40 lost', $r, ['value' => 100.0, 'covered' => 60.0, 'remainder' => 40.0, 'count' => 1]);
check('the exact sentence', Galado_GC_Remainder::message($r),
    'Your gift card is worth RM100 and this order is RM60. The RM40 left will be lost. Add more items?');
check('RM100 card on a RM150 order: nothing lost',
    Galado_GC_Remainder::compute([['value' => 100.0, 'applied' => 100.0]])['remainder'], 0.0);
check('a smaller cart: RM300 card on RM17.90 of charms leaves RM282.10',
    Galado_GC_Remainder::compute([['value' => 300.0, 'applied' => 17.9]])['remainder'], 282.1);
check('and the sentence shows cents only when there are any',
    Galado_GC_Remainder::message(Galado_GC_Remainder::compute([['value' => 300.0, 'applied' => 17.9]])),
    'Your gift card is worth RM300 and this order is RM17.90. The RM282.10 left will be lost. Add more items?');
$two = Galado_GC_Remainder::compute([['value' => 100.0, 'applied' => 100.0], ['value' => 100.0, 'applied' => 50.0]]);
check('two RM100 cards on a RM150 order: RM50 lost, one sentence for both', Galado_GC_Remainder::message($two),
    'Your gift cards are worth RM200 and this order is RM150. The RM50 left will be lost. Add more items?');
check('an applied figure above the value is capped (never a negative remainder)',
    Galado_GC_Remainder::compute([['value' => 50.0, 'applied' => 80.0]])['remainder'], 0.0);
$capped = Galado_GC_Remainder::compute([['value' => 100.0, 'applied' => 100.0]], 2, 10.0);
check('a RM10 discount the order could not use (the card already covered everything) counts as lost',
    [$capped['covered'], $capped['remainder']], [90.0, 10.0]);
check('... and never takes "covered" below zero', Galado_GC_Remainder::compute([['value' => 50.0, 'applied' => 5.0]], 2, 20.0)['covered'], 0.0);
$sgd = Galado_GC_Remainder::compute([['value' => 31.32, 'applied' => 18.79]]);
check('in the shopper\'s currency: a RM100 card is S$31.32 at 0.31317, S$12.53 lost', $sgd['remainder'], 12.53);

echo "-- check page: only valid, used, expired or not found\n";
$now = 1790000000;
$card = ['is_gift' => true, 'status' => 'publish', 'usage_count' => 0, 'usage_limit' => 1, 'expires' => $now + 86400, 'value' => 100.0];
check('valid, with value and expiry', Galado_GC_Checker::status_for($card, $now), ['status' => 'valid', 'value' => 100.0, 'expires' => $now + 86400]);
check('used', Galado_GC_Checker::status_for(['usage_count' => 1] + $card, $now), ['status' => 'used']);
check('expired (a used one reads "used" first)', Galado_GC_Checker::status_for(['expires' => $now - 1] + $card, $now), ['status' => 'expired', 'expires' => $now - 1]);
check('valid to the last second of its last day', Galado_GC_Checker::status_for(['expires' => $now] + $card, $now)['status'], 'valid');
check('a disabled (draft) card reads as not found', Galado_GC_Checker::status_for(['status' => 'draft'] + $card, $now), ['status' => 'not_found']);
check('an ordinary promo code reads as not found (the page reveals nothing about other coupons)',
    Galado_GC_Checker::status_for(['is_gift' => false] + $card, $now), ['status' => 'not_found']);
check('the valid answer never repeats the code', strpos(Galado_GC_Checker::message(Galado_GC_Checker::status_for($card, $now)), 'GIFT'), false);
check('the valid answer states the single-use rule',
    false !== strpos(Galado_GC_Checker::message(Galado_GC_Checker::status_for($card, $now)), 'Single use: spend it in one order. Any unused value is lost.'), true);
check('a blank or malformed code is simply not found', Galado_GC_Checker::lookup(''), ['status' => 'not_found']);

echo "-- check page: 10 checks per IP per hour\n";
$t = 1790000000 - (1790000000 % 3600) + 60; // one minute into a clock hour
$allowed = 0;
for ($i = 0; $i < 12; $i++) {
    $allowed += Galado_GC_Checker::allow('203.0.113.7', $t + $i) ? 1 : 0;
}
check('the 11th and 12th checks in the hour are refused', $allowed, 10);
check('another IP is unaffected', Galado_GC_Checker::allow('198.51.100.9', $t), true);
check('the next hour starts afresh', Galado_GC_Checker::allow('203.0.113.7', $t + 3600), true);
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
$_SERVER['REMOTE_ADDR'] = '172.70.1.1';
check("behind Cloudflare the visitor's address is used", Galado_GC_Checker::client_ip(), '203.0.113.7');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
check('a malformed header falls back to the connection address', Galado_GC_Checker::client_ip(), '172.70.1.1');

done();
