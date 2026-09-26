<?php
/**
 * Product page fields and limits; customer copy never uses em or en dashes.
 *   php tests/unit/test-fields-and-copy.php
 */
require __DIR__ . '/bootstrap.php';

galado_test_gift_product(500, [501, 506]);
$GLOBALS['__post_meta'][501]['_galado_gc_amount'] = '100';   // the RM100 variation
$GLOBALS['__post_meta'][506]['_galado_gc_custom'] = 'yes';   // the custom-amount variation
$GLOBALS['__post_meta'][502]['_galado_gc_amount'] = '300';
$GLOBALS['__post_parent'][502] = 500;
$GLOBALS['__post_type'][502] = 'product_variation';

$now = (new DateTimeImmutable('2026-09-26 10:00:00', new DateTimeZone('Asia/Kuala_Lumpur')))->getTimestamp();
$post = [
    'galado_gc_recipient_name'  => 'Mei Chin',
    'galado_gc_recipient_email' => 'meichin@example.com',
    'galado_gc_message'         => 'Happy birthday!',
    'galado_gc_delivery_date'   => '2026-10-01',
];
$parse = function (array $overrides, $variation = 501) use ($post, $now) {
    $r = Galado_GC_Cart::parse_fields(array_merge($post, $overrides), $variation, $now);
    return is_wp_error($r) ? $r->get_error_message() : $r;
};

echo "-- product page fields travel into the cart\n";
check('a preset card carries its RM value and the fields', $parse([]), [
    'value' => 100.0, 'recipient_name' => 'Mei Chin', 'recipient_email' => 'meichin@example.com',
    'message' => 'Happy birthday!', 'delivery_date' => '2026-10-01',
]);
check('the message is optional', $parse(['galado_gc_message' => ''])['message'], '');
check('tags are stripped from the message (it is escaped again wherever shown)',
    $parse(['galado_gc_message' => '<script>alert(1)</script>Hi'])['message'], 'alert(1)Hi');
check('no amount chosen', $parse([], 0), 'Please choose an amount.');
check('name required', $parse(['galado_gc_recipient_name' => '  ']), "Please enter the recipient's name.");
check('name up to 60 characters', $parse(['galado_gc_recipient_name' => str_repeat('a', 61)]), "The recipient's name can be up to 60 characters.");
check('email validated', $parse(['galado_gc_recipient_email' => 'not-an-email']), "Please enter the recipient's email address.");
check('message limited to 200 characters', $parse(['galado_gc_message' => str_repeat('x', 201)]), 'The message can be up to 200 characters.');
check('200 characters of multibyte text is allowed', strlen($parse(['galado_gc_message' => str_repeat('祝', 200)])['message']) > 0, true);
$lines = str_repeat('x', 99) . "\r\n" . str_repeat('y', 100); // 200 as the browser counts it (a line break is one)
check('a line break counts once: 200 characters over two lines is allowed', is_array($parse(['galado_gc_message' => $lines])), true);
check('... and is kept as a single newline', $parse(['galado_gc_message' => $lines])['message'], str_repeat('x', 99) . "\n" . str_repeat('y', 100));
check('negative control: 201 characters over two lines is still refused',
    $parse(['galado_gc_message' => str_repeat('x', 100) . "\r\n" . str_repeat('y', 100)]), 'The message can be up to 200 characters.');
check('delivery date before today refused', $parse(['galado_gc_delivery_date' => '2026-09-25']), 'Please choose a delivery date from today up to one year ahead.');
check('delivery date more than a year ahead refused', $parse(['galado_gc_delivery_date' => '2027-09-27']), 'Please choose a delivery date from today up to one year ahead.');

echo "-- custom amount: RM30 to the per-card limit, whole ringgit\n";
check('RM30 accepted', $parse(['galado_gc_custom_amount' => '30'], 506)['value'], 30.0);
check('RM1,000 accepted', $parse(['galado_gc_custom_amount' => '1000'], 506)['value'], 1000.0);
check('RM29 refused', $parse(['galado_gc_custom_amount' => '29'], 506), 'A custom amount can be from RM30 to RM1,000.');
check('RM1,001 refused', $parse(['galado_gc_custom_amount' => '1001'], 506), 'A custom amount can be from RM30 to RM1,000.');
check('RM50.50 refused (whole ringgit)', $parse(['galado_gc_custom_amount' => '50.50'], 506), 'Please enter an amount in whole ringgit, from RM30 to RM1,000.');
check('empty refused', $parse(['galado_gc_custom_amount' => ''], 506), 'Please enter an amount in whole ringgit, from RM30 to RM1,000.');
check('negative refused', $parse(['galado_gc_custom_amount' => '-100'], 506), 'Please enter an amount in whole ringgit, from RM30 to RM1,000.');

echo "-- the three settings\n";
$GLOBALS['__options']['galado_gift_cards_max_card'] = '500';
check('lowering the card limit to RM500 lowers the custom maximum', $parse(['galado_gc_custom_amount' => '501'], 506), 'A custom amount can be from RM30 to RM500.');
$GLOBALS['__options']['galado_gift_cards_max_card'] = '200';
check('and blocks a preset above it (RM300)', $parse([], 502), 'One gift card can hold up to RM200.');
$GLOBALS['__options']['galado_gift_cards_max_card'] = '5';
check('a card limit below RM30 is ignored (falls back to RM1,000)', Galado_GC_Config::max_card(), 1000);
$GLOBALS['__options']['galado_gift_cards_max_card'] = '1000';
$GLOBALS['__options']['galado_gift_cards_max_order'] = '500';
check('the order limit is never below one card\'s limit', Galado_GC_Config::max_order(), 1000);
unset($GLOBALS['__options']['galado_gift_cards_max_order']);
check('defaults: RM1,000 per card, RM2,000 per order', [Galado_GC_Config::max_card(), Galado_GC_Config::max_order()], [1000, 2000]);
check('risk hold on by default', Galado_GC_Config::risk_hold_enabled(), true);
$GLOBALS['__options']['galado_gift_cards_risk_hold'] = 'no';
check('and can be switched off', Galado_GC_Config::risk_hold_enabled(), false);

echo "-- amounts for customers\n";
check('whole ringgit without decimals', Galado_GC_Cart::rm(1000), 'RM1,000');
check('cents when there are any', Galado_GC_Cart::rm(17.9), 'RM17.90');

echo "-- no em or en dashes in anything the plugin ships\n";
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(GALADO_GC_DIR, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    // Everything except the tests themselves (this file names the pattern it looks for).
    if (!preg_match('/\.(php|md|txt|js|css|html)$/', $file->getFilename()) || false !== strpos($file->getPathname(), '/tests/')) {
        continue;
    }
    foreach (file($file->getPathname()) as $n => $line) {
        if (preg_match('/\x{2013}|\x{2014}|&mdash;|&ndash;/u', $line)) {
            $hits[] = str_replace(GALADO_GC_DIR, '', $file->getPathname()) . ':' . ($n + 1);
        }
    }
}
check('none found in any shipped file', $hits, []);
check('negative control: the scan does catch one', (bool) preg_match('/\x{2014}/u', "Valid \u{2014} 3 years"), true);

done();
