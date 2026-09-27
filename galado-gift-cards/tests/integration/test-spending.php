<?php
/**
 * Spending on the website, through WooCommerce's real discount engine: full value, a smaller
 * order (warning, remainder lost), with promo codes (including individual use, both orders),
 * every blocked route, the free-shipping minimum, the Store API figures for the app, and the RM
 * value recorded on the order.
 */
require __DIR__ . '/bootstrap.php';

gct_product();
gct_shipping_zones();
$new_card = function ($value) {
    $o = gct_paid_order([['value' => $value, 'label' => 'Custom amount']]);
    return strtoupper(gct_coupons($o)[0]->get_code());
};
$promo = function ($code, $type, $amount, $individual = false) {
    $c = new WC_Coupon(0); // not new WC_Coupon(): Points and Rewards turns that into a virtual coupon
    $c->set_code($code);
    $c->set_discount_type($type);
    $c->set_amount((string) $amount);
    $c->set_individual_use($individual);
    $c->save();
    return $code;
};
$discount = function ($code) { return round((float) WC()->cart->get_coupon_discount_amount(strtolower($code), false), 2); };
$charm60 = gct_charm(60, 'Charm 60');
$charm150 = gct_charm(150, 'Charm 150');
$charm20 = gct_charm(20, 'Charm 20');

echo "-- spent in full, and on a smaller order (the warning shows, the rest is lost)\n";
$card = $new_card(100);
gct_cart();
WC()->cart->add_to_cart($charm150);
check('applied', WC()->cart->apply_coupon($card), true);
WC()->cart->calculate_totals();
check('RM150 order: the card pays RM100 in full', [$discount($card), WC()->cart->get_total('edit')], [100.0, '50.00']);
check('no warning when nothing is lost', Galado_GC_Remainder::current(), null);
gct_cart();
WC()->cart->add_to_cart($charm60);
WC()->cart->apply_coupon($card);
WC()->cart->calculate_totals();
check('RM60 order: the card pays RM60, total RM0', [$discount($card), WC()->cart->get_total('edit')], [60.0, '0.00']);
check('the warning, word for word', Galado_GC_Remainder::message(Galado_GC_Remainder::current()),
    'Your gift card is worth RM100 and this order is RM60. The RM40 left will be lost. Add more items?');
ob_start();
do_action('woocommerce_cart_totals_before_order_total');
$row = ob_get_clean();
check('it is a row in the cart totals, linking to the shop', [false !== strpos($row, 'The RM40 left will be lost.'), false !== strpos($row, '<a href=')], [true, true]);
ob_start();
do_action('woocommerce_review_order_before_order_total');
check('and in the checkout order review', false !== strpos(ob_get_clean(), 'The RM40 left will be lost.'), true);
$label = wc_cart_totals_coupon_label(new WC_Coupon($card), false);
check('the totals show "Gift card ending ...", never the code', [$label, false !== stripos($label, $card)], ['Gift card ending ' . substr($card, -4), false]);

echo "-- the app gets the same figures (Store API cart)\n";
$res = rest_do_request(new WP_REST_Request('GET', '/wc/store/v1/cart'));
$data = json_decode(wp_json_encode($res->get_data()), true); // the Store API returns objects
$ext = $data['extensions']['galado-gift-cards'] ?? null;
check('extensions.galado-gift-cards', $ext, [
    'remainder_lost' => '40.00', 'currency' => 'MYR', 'gift_card_value' => '100.00', 'order_amount' => '60.00',
    'message' => 'Your gift card is worth RM100 and this order is RM60. The RM40 left will be lost. Add more items?',
]);

echo "-- works together with other codes, including individual-use ones, either order\n";
$promo('TENOFF', 'percent', 10, true);
gct_cart();
WC()->cart->add_to_cart($charm150);
WC()->cart->apply_coupon('TENOFF');
check('individual-use promo first, then the gift card: both stay', [WC()->cart->apply_coupon($card), count(WC()->cart->get_applied_coupons())], [true, 2]);
WC()->cart->calculate_totals();
check('the promo takes 10% first, the card pays the discounted RM135 up to RM100', [$discount('TENOFF'), $discount($card), WC()->cart->get_total('edit')], [15.0, 100.0, '35.00']);
gct_cart();
WC()->cart->add_to_cart($charm150);
WC()->cart->apply_coupon($card);
WC()->cart->apply_coupon('TENOFF');
check('gift card first, then the individual-use promo: the card is kept', WC()->cart->get_applied_coupons(), [strtolower($card), 'tenoff']);
$promo('SOLO5', 'fixed_cart', 5, true);
$promo('EXTRA3', 'fixed_cart', 3);
gct_cart();
WC()->cart->add_to_cart($charm150);
WC()->cart->apply_coupon('EXTRA3');
WC()->cart->apply_coupon('SOLO5');
check('negative control: an ordinary code is still removed by an individual-use one', array_values(WC()->cart->get_applied_coupons()), ['solo5']);

echo "-- nothing ever discounts the gift card product\n";
gct_cart();
gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
WC()->cart->add_to_cart($charm60);
WC()->cart->apply_coupon('TENOFF');
WC()->cart->calculate_totals();
check('a 10% promo on a RM100 card + RM60 charm takes RM6 (the charm only)', [$discount('TENOFF'), WC()->cart->get_total('edit')], [6.0, '154.00']);
$other_card = $new_card(300);
WC()->cart->apply_coupon($other_card);
WC()->cart->calculate_totals();
check('a RM300 gift card with it pays only the rest of the charm (RM54); the new card is paid in cash', [$discount($other_card), WC()->cart->get_total('edit')], [54.0, '100.00']);
check('... and the warning shows what that card loses', Galado_GC_Remainder::current()['remainder'], 246.0);

echo "-- every blocked route on a gift-card-only cart fails with a clear message\n";
$blocked = function ($code) {
    gct_cart();
    gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
    wc_clear_notices();
    $ok = WC()->cart->apply_coupon($code);
    return [$ok, gct_notices()];
};
check('a promo code', $blocked('TENOFF'), [false, ['Discount codes can’t be used to buy a gift card.']]);
check('another gift card', $blocked($new_card(50)), [false, ['A gift card can’t be used to buy another gift card.']]);
$points = $promo('wc_points_redemption_1_' . gmdate('Ymd') . '_abc', 'fixed_cart', 50);
check('Shopping Credits (a Points and Rewards redemption coupon)', $blocked($points), [false, ['Shopping Credits can’t be used to buy a gift card.']]);
gct_cart();
gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
WC()->cart->add_to_cart($charm20);
WC()->cart->apply_coupon($points);
WC()->cart->calculate_totals();
check('Shopping Credits with a charm in the cart discount the charm only (RM20 of RM50)', $discount($points), 20.0);

echo "-- used, expired and unknown codes say so plainly\n";
$used = $new_card(100);
$uc = new WC_Coupon(strtolower($used));
$uc->increase_usage_count('x@example.test');
gct_cart();
WC()->cart->add_to_cart($charm60);
wc_clear_notices();
WC()->cart->apply_coupon($used);
check('used (as a guest, where WooCommerce 10.5.3 reports it as "stuck")', gct_notices(), ['This gift card has already been used.']);
$held_code = $new_card(100);
$hc = new WC_Coupon(strtolower($held_code));
check('an unpaid checkout elsewhere holds the unused card', (bool) $hc->get_data_store()->check_and_hold_coupon($hc), true);
check('... so WooCommerce counts one held use', Galado_GC_Spending::is_held_by_unpaid_order(new WC_Coupon(strtolower($held_code))), true);
wc_clear_notices();
WC()->cart->apply_coupon($held_code);
check('negative control: a held card says "try again", not "used"', gct_notices(),
    ['This gift card is in another order that has not been paid yet. Please try again in a few minutes.']);
$exp = $new_card(100);
$ec = new WC_Coupon(strtolower($exp));
$ec->set_date_expires(time() - 60);
$ec->save();
wc_clear_notices();
WC()->cart->apply_coupon($exp);
check('expired', gct_notices(), ['This gift card has expired.']);
wc_clear_notices();
WC()->cart->apply_coupon('GIFT-2345-6789-ABCD');
check('unknown', gct_notices(), ['We couldn’t find that gift card. Check the code and try again.']);

echo "-- Singapore free shipping from RM150 ignores gift card lines\n";
gct_cart('SG');
WC()->cart->add_to_cart($charm150);
check('RM150 of products: free shipping offered', gct_rates(), ['flat_rate', 'free_shipping']);
gct_cart('SG');
gct_add_gift('Custom amount', ['galado_gc_custom_amount' => '150', 'galado_gc_recipient_email' => 'sg@example.test']);
WC()->cart->add_to_cart($charm20);
check('RM150 card + RM20 charm: not free, only the flat rate', gct_rates(), ['flat_rate']);
gct_cart('SG');
gct_add_gift('RM50', ['galado_gc_recipient_email' => 'sg@example.test']);
WC()->cart->add_to_cart($charm150);
check('RM150 charm + RM50 card: still free', gct_rates(), ['flat_rate', 'free_shipping']);
gct_cart('SG');
gct_add_gift('RM100', ['galado_gc_recipient_email' => 'sg@example.test']);
WC()->cart->calculate_totals();
check('a gift-card-only cart needs no shipping at all', WC()->cart->needs_shipping(), false);
gct_cart('MY');
gct_add_gift('RM100', ['galado_gc_recipient_email' => 'my@example.test']);
WC()->cart->add_to_cart($charm20);
check('Malaysia stays free as before', gct_rates(), ['free_shipping']);

echo "-- a Club win-back trimmed so it cannot pay for a card: the member's balance is charged what was given\n";
// The Club bridge's two steps, the way it does them: a "GALADO Club reward" fee on the cart, then at
// checkout (priority 10) it records on the order the RM it worked out, whenever the fee applied.
$winback = function ($cart) { $cart->add_fee('GALADO Club reward: RM30 off', -30, false); };
$record = function ($order) {
    foreach (WC()->cart->get_fees() as $fee) {
        if (0 === strpos($fee->name, 'GALADO Club reward') && abs((float) $fee->amount) > 0) {
            $order->update_meta_data('_galado_winback_applied', 30);
        }
    }
};
add_action('woocommerce_cart_calculate_fees', $winback);
add_action('woocommerce_checkout_create_order', $record, 10);
gct_cart();
gct_add_gift('Custom amount', ['galado_gc_custom_amount' => '150', 'galado_gc_recipient_email' => 'friend@example.test']);
WC()->cart->add_to_cart($charm20);
$order = gct_order_from_cart();
check('RM30 win-back on a RM150 card + RM20 charm: RM20 given (the card paid in full), RM20 recorded for the balance',
    [(float) $order->get_total(), (float) $order->get_meta('_galado_winback_applied')], [150.0, 20.0]);
gct_cart();
WC()->cart->add_to_cart($charm150);
$order = gct_order_from_cart();
check('negative control: no card in the order, the full RM30 given and recorded',
    [(float) $order->get_total(), (float) $order->get_meta('_galado_winback_applied')], [120.0, 30.0]);
remove_action('woocommerce_cart_calculate_fees', $winback);
remove_action('woocommerce_checkout_create_order', $record, 10);

echo "-- the [Remove] link and order notes never carry a code\n";
$card = $new_card(100);
gct_cart();
WC()->cart->add_to_cart($charm60);
WC()->cart->apply_coupon($card);
WC()->cart->apply_coupon('TENOFF');
WC()->cart->calculate_totals();
ob_start();
wc_cart_totals_coupon_html(new WC_Coupon(strtolower($card)));
$html = ob_get_clean();
preg_match('/href="([^"]*)"/', $html, $href);
preg_match('/aria-label="([^"]*)"/', $html, $aria);
check('gift card row: the link and its label carry no code', [stripos($href[1] ?? '', substr($card, 5)), $aria[1] ?? ''], [false, 'Remove gift card']);
check('... and the AJAX removal still has what it needs (data-coupon)', false !== strpos($html, 'data-coupon="' . strtolower($card) . '"'), true);
ob_start();
wc_cart_totals_coupon_html(new WC_Coupon('tenoff'));
check('negative control: a promo row is left as WooCommerce builds it', false !== strpos(ob_get_clean(), 'remove_coupon=tenoff'), true);
$counter = wc_create_order();
$ci = new WC_Order_Item_Product();
$ci->set_product(wc_get_product($charm60));
$ci->set_quantity(1);
$ci->set_subtotal('60');
$ci->set_total('60');
$counter->add_item($ci);
$counter->calculate_totals();
$counter->save();
$staff_card = $new_card(50);
$counter = wc_get_container()->get(\Automattic\WooCommerce\Internal\Orders\CouponsController::class)
    ->add_coupon_discount(['order_id' => $counter->get_id(), 'coupon' => $staff_card]); // staff applying it on the admin order screen
$notes = html_entity_decode(gct_notes_text($counter), ENT_QUOTES, 'UTF-8'); // WooCommerce escapes its note
check('staff apply a card on the admin order screen: it pays RM50 of the RM60', (float) $counter->get_discount_total(), 50.0);
check("WooCommerce's \"Coupon applied\" note shows only the last four characters",
    [false !== stripos($notes, 'Coupon applied: "gift card ending ' . substr($staff_card, -4)), stripos($notes, substr($staff_card, 5))], [true, false]);

echo "-- discount fees (a Club offer, a REDIS cart rule) never pay for a card being bought\n";
$club = function ($cart) { $cart->add_fee('GALADO Club welcome: RM30 off your first order', -30); };
add_action('woocommerce_cart_calculate_fees', $club);
gct_cart();
gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
WC()->cart->add_to_cart($charm20);
WC()->cart->calculate_totals();
check('RM30 offer on a RM100 card + RM20 charm: only RM20 comes off (the charm), the card is paid in full', WC()->cart->get_total('edit'), '100.00');
gct_cart();
WC()->cart->add_to_cart($charm150);
WC()->cart->calculate_totals();
check('negative control: without a card in the cart the offer is untouched (RM150 - RM30)', WC()->cart->get_total('edit'), '120.00');
gct_cart();
gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
WC()->cart->calculate_totals();
check('a cart of only a card: the offer takes nothing', WC()->cart->get_total('edit'), '100.00');
check('... and its line is removed, not left showing "RM30 off" at RM0', count(WC()->cart->get_fees()), 0);
remove_action('woocommerce_cart_calculate_fees', $club);
$redis = function ($cart) { $cart->add_fee('Spend RM100, save RM10', -10); };
add_action('woocommerce_cart_calculate_fees', $redis);
$card = $new_card(100);
gct_cart();
$charm100 = gct_charm(100, 'Charm 100');
WC()->cart->add_to_cart($charm100);
WC()->cart->apply_coupon($card);
WC()->cart->calculate_totals();
check('a card covering every item leaves nothing for a RM10 discount fee: the warning counts it as lost',
    Galado_GC_Remainder::message(Galado_GC_Remainder::current()),
    'Your gift card is worth RM100 and this order is RM90. The RM10 left will be lost. Add more items?');
remove_action('woocommerce_cart_calculate_fees', $redis);
WC()->cart->calculate_totals();
check('negative control: without that fee nothing is lost', Galado_GC_Remainder::current(), null);

echo "-- REDIS conditions leave gift cards out (its filters, called as REDIS calls them)\n";
gct_cart();
gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
WC()->cart->add_to_cart($charm20);
$qty_rule = ['qty_item' => ['qty_item_min' => 2, 'qty_item_max' => '']];
check('"2 or more items" with a charm + a card: not met (the card does not count)',
    apply_filters('viredis_may_be_apply_to_cart', 'check', 7, $qty_rule, '', 0, 0, true), false);
WC()->cart->add_to_cart($charm60);
check('negative control: two charms + a card meet it (REDIS goes on checking)',
    apply_filters('viredis_may_be_apply_to_cart', 'check', 7, $qty_rule, '', 0, 0, true), 'check');
check('bulk pricing on "all items": 3 lines counted as 2 (the card left out)',
    apply_filters('viredis_get_product_qty_in_cart', WC()->cart->get_cart_contents_count() + 1, $charm20, 1), 3);
check('negative control: a per-product count is left alone', apply_filters('viredis_get_product_qty_in_cart', 2, $charm20, 1), 2);
gct_cart();
WC()->cart->add_to_cart($charm20);
$seen = null;
$peek = function () use (&$seen) { $seen = Galado_GC_Spending::cart_gift_subtotal(); };
add_action('woocommerce_add_to_cart', $peek, 1); // before WooCommerce totals the new line
gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
remove_action('woocommerce_add_to_cart', $peek, 1);
check('asked before WooCommerce has totalled a just-added card, the gift subtotal is still RM100', $seen, 100.0);

echo "-- Points and Rewards, the real plugin\n";
if (!class_exists('WC_Points_Rewards_Discount')) {
    echo "skip Points and Rewards is not installed in the test WordPress (setup.sh GCT_PR_DIR)\n";
} else {
    $uid = wp_insert_user(['user_login' => 'shopper_pr', 'user_pass' => wp_generate_password(), 'user_email' => 'pr@example.test', 'role' => 'customer']);
    wp_set_current_user($uid);
    WC_Points_Rewards_Manager::set_points_balance($uid, 500, 'admin-adjustment'); // exactly RM50 of Shopping Credits (sign-up points aside)
    $redeem = function () {
        WC()->session->set('wc_points_rewards_discount_amount', '');
        $code = WC_Points_Rewards_Discount::generate_discount_code();
        WC()->cart->apply_coupon($code);
        WC()->cart->calculate_totals();
        return $code;
    };
    gct_cart();
    gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
    WC()->cart->add_to_cart($charm20);
    $code = $redeem();
    check('RM50 of Shopping Credits on a RM100 card + RM20 charm: the charm is fully paid, nothing off the card',
        [$discount($code), WC()->cart->get_total('edit')], [20.0, '100.00']);
    remove_filter('woocommerce_coupon_get_discount_amount', [Galado_GC_Spending::class, 'points_share_without_gift_lines'], 20);
    gct_cart();
    gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
    WC()->cart->add_to_cart($charm20);
    $code = $redeem();
    check('negative control: without the fix the charm gets only its share (about RM8.33)', $discount($code) < 10, true);
    add_filter('woocommerce_coupon_get_discount_amount', [Galado_GC_Spending::class, 'points_share_without_gift_lines'], 20, 5);
    WC_Points_Rewards_Manager::set_points_balance($uid, 100, 'admin-adjustment'); // RM10 of credits
    $promo('FIVEEACH', 'fixed_product', 5);
    gct_cart();
    gct_add_gift('RM100', ['galado_gc_recipient_email' => 'friend@example.test']);
    WC()->cart->add_to_cart(gct_charm(50, 'Charm 50'));
    WC()->cart->apply_coupon('FIVEEACH');
    $code = $redeem();
    check('RM10 of credits beside a "RM5 off each item" promo, a card and a RM50 charm: exactly RM10 used, never more than held',
        [$discount('FIVEEACH'), $discount($code)], [5.0, 10.0]);
    check('the buyer earns on a card like any product they pay for (RM100 at 1 point per RM2 = 50)',
        (int) WC_Points_Rewards_Product::get_points_earned_for_product_purchase(wc_get_product(gct_variation('RM100'))), 50);
    wp_set_current_user(0);
}

done();
