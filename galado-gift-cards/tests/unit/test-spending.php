<?php
/**
 * Spending rules: no discount on the gift card product, the gift-card-only rejection, the
 * individual-use exemption, Points and Rewards, REDIS, the free-shipping minimum and code masking.
 * The same rules run against real WooCommerce in tests/integration.
 *   php tests/unit/test-spending.php
 */
require __DIR__ . '/bootstrap.php';

galado_test_gift_product(500, [501, 506]);   // the gift card product and two of its variations
$gift_line = (object) ['product' => new WC_Product(501, 500)];
$gift_line2 = (object) ['product' => new WC_Product(506, 500)];
$charm_line = (object) ['product' => new WC_Product(700)];
$case_line = (object) ['product' => new WC_Product(801, 800)];

echo "-- no coupon of any kind discounts the gift card product\n";
check('gift lines are removed from the items any coupon may discount',
    Galado_GC_Spending::items_to_apply([$gift_line, $charm_line, $gift_line2, $case_line]), [$charm_line, $case_line]);
check('a cart without gift lines is untouched', Galado_GC_Spending::items_to_apply([$charm_line, $case_line]), [$charm_line, $case_line]);
check('a cart of gift lines only leaves nothing to discount', Galado_GC_Spending::items_to_apply([$gift_line]), []);
check('an item with no product (a fee) passes through', count(Galado_GC_Spending::items_to_apply([(object) ['product' => false]])), 1);

echo "-- a code on a cart of gift cards only fails with a clear message\n";
$reject = function ($coupon, array $items) {
    try {
        Galado_GC_Spending::is_valid(true, $coupon, new WC_Discounts($items));
        return 'accepted';
    } catch (Exception $e) {
        return $e->getMessage();
    }
};
check('a promo code', $reject(new WC_Coupon('WELCOME10'), [$gift_line]), 'Discount codes can’t be used to buy a gift card.');
check('Shopping Credits (Points and Rewards)', $reject(new WC_Coupon('wc_points_redemption_7_2026_abc'), [$gift_line]), 'Shopping Credits can’t be used to buy a gift card.');
check('another gift card', $reject(new WC_Coupon('GIFT-7KQ4-M2XD-9PWA', ['_galado_gift_card' => 'yes']), [$gift_line, $gift_line2]), 'A gift card can’t be used to buy another gift card.');
check('with anything else in the cart the code is accepted (it discounts only that)', $reject(new WC_Coupon('WELCOME10'), [$gift_line, $charm_line]), 'accepted');
check('an already-invalid coupon stays invalid without a second message', Galado_GC_Spending::is_valid(false, new WC_Coupon('X'), new WC_Discounts([$gift_line])), false);

echo "-- individual use: a gift card works with an \"individual use only\" code, both ways round\n";
galado_test_gift_code('GIFT-7KQ4-M2XD-9PWA', 9101);
galado_test_gift_code('GIFT-2345-6789-ABCD', 9102);
check('applying an individual-use promo keeps the gift codes already applied (exact strings)',
    Galado_GC_Spending::keep_gift_codes([], new WC_Coupon('VIP20'), ['gift-7kq4-m2xd-9pwa', 'summer5', 'gift-2345-6789-abcd']),
    ['gift-7kq4-m2xd-9pwa', 'gift-2345-6789-abcd']);
check('and keeps what other plugins asked to keep', Galado_GC_Spending::keep_gift_codes(['freeship'], new WC_Coupon('VIP20'), ['gift-7kq4-m2xd-9pwa']), ['freeship', 'gift-7kq4-m2xd-9pwa']);
check('negative control: an ordinary code is not kept', Galado_GC_Spending::keep_gift_codes([], new WC_Coupon('VIP20'), ['summer5']), []);
check('a gift card may join a cart that already has an individual-use code',
    Galado_GC_Spending::allow_with_individual_use(false, new WC_Coupon('GIFT-7KQ4-M2XD-9PWA', ['_galado_gift_card' => 'yes'])), true);
check('negative control: an ordinary code still may not', Galado_GC_Spending::allow_with_individual_use(false, new WC_Coupon('SUMMER5')), false);

echo "-- REDIS dynamic pricing leaves the gift card alone\n";
check('no pricing rule applies to the gift card', Galado_GC_Spending::redis_may_apply('check', 1, [], new WC_Product(501, 500)), false);
check("other products: REDIS decides ('check')", Galado_GC_Spending::redis_may_apply('check', 1, [], new WC_Product(700)), 'check');
check('a cart-level check with no product: REDIS decides', Galado_GC_Spending::redis_may_apply('check', 1, [], null), 'check');
check('the gift card keeps its own price', Galado_GC_Spending::redis_price(90.0, 100.0, 501, new WC_Product(501, 500)), 100.0);
check('other products get the REDIS price', Galado_GC_Spending::redis_price(90.0, 100.0, 700, new WC_Product(700)), 90.0);

echo "-- free-shipping minimums ignore gift card lines (Singapore: RM150, discounts counted)\n";
$rule = function ($subtotal, $gift, $discount = 0.0, $requires = 'min_amount', $has_coupon = false, $ignore = 'no') {
    return Galado_GC_Spending::free_shipping_rule($requires, 150.0, $ignore, $subtotal, $gift, $discount, $has_coupon, 2);
};
check('RM150 card + RM20 charm: not free (only RM20 counts)', $rule(170.0, 150.0), false);
check('RM150 of charms: free', $rule(150.0, 0.0), true);
check('RM150 of charms + a RM50 card: still free', $rule(200.0, 50.0), true);
check('RM160 of charms less a RM20 promo, "discounts count" setting: not free', $rule(160.0, 0.0, 20.0), false);
check('the same with the store set to ignore discounts: free', $rule(160.0, 0.0, 20.0, 'min_amount', false, 'yes'), true);
check('"either": a free-shipping coupon still unlocks it', $rule(170.0, 150.0, 0.0, 'either', true), true);
check('"both": the coupon alone is not enough', $rule(170.0, 150.0, 0.0, 'both', true), false);
check('rounding matches WooCommerce (149.995 rounds to 150.00)', $rule(149.995, 0.0), true);

echo "-- codes stay out of page text\n";
check('totals label reads "Gift card ending 9PWA"',
    Galado_GC_Spending::coupon_label('Coupon: gift-7kq4-m2xd-9pwa', new WC_Coupon('GIFT-7KQ4-M2XD-9PWA', ['_galado_gift_card' => 'yes'])), 'Gift card ending 9PWA');
check('other coupons keep their label', Galado_GC_Spending::coupon_label('Coupon: summer5', new WC_Coupon('SUMMER5')), 'Coupon: summer5');
$gift = new WC_Coupon('GIFT-7KQ4-M2XD-9PWA', ['_galado_gift_card' => 'yes']);
check('not found (105), for a code in the gift format', Galado_GC_Spending::coupon_error('Coupon "gift-zzzz-zzzz-zzzz" cannot be applied because it does not exist.', 105, new WC_Coupon('GIFT-ZZZZ-ZZZZ-ZZZZ')),
    'We couldn’t find that gift card. Check the code and try again.');
check('used (106)', Galado_GC_Spending::coupon_error('Usage limit for coupon "gift-7kq4-m2xd-9pwa" has been reached.', 106, $gift), 'This gift card has already been used.');
check('expired (107)', Galado_GC_Spending::coupon_error('Coupon "gift-7kq4-m2xd-9pwa" has expired.', 107, $gift), 'This gift card has expired.');
$held = new WC_Coupon('GIFT-7KQ4-M2XD-9PWA', ['_galado_gift_card' => 'yes'], 1);
check('held by an unpaid order (115, logged in)', Galado_GC_Spending::coupon_error('x gift-7kq4-m2xd-9pwa', 115, $held), 'This gift card is in another order that has not been paid yet. Please try again in a few minutes.');
check('held by an unpaid order (116, guest)', Galado_GC_Spending::coupon_error('x gift-7kq4-m2xd-9pwa', 116, $held), 'This gift card is in another order that has not been paid yet. Please try again in a few minutes.');
check('116 with nothing held ("0" from the database) is simply used', Galado_GC_Spending::coupon_error('x gift-7kq4-m2xd-9pwa', 116, $gift), 'This gift card has already been used.');
check('any other message is masked', Galado_GC_Spending::coupon_error('Sorry, coupon "gift-7kq4-m2xd-9pwa" is not valid.', 999, $gift), 'Sorry, coupon "your gift card" is not valid.');
check('other coupons keep WooCommerce\'s message', Galado_GC_Spending::coupon_error('Coupon "summer5" has expired.', 107, new WC_Coupon('SUMMER5')), 'Coupon "summer5" has expired.');

done();
