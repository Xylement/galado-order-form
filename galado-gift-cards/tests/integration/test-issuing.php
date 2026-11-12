<?php
/**
 * Issuing: one coupon per card when the order is paid, exactly once, with the right settings.
 * (Two real processes racing are in race.sh.)
 */
require __DIR__ . '/bootstrap.php';

gct_product();
$tz = new DateTimeZone('Asia/Kuala_Lumpur');

echo "-- an order with two card lines and a charm is paid\n";
$order = gct_paid_order([['value' => 100], ['label' => 'Custom amount', 'value' => 75]]);
$coupons = gct_coupons($order);
check('one coupon per card: two', count($coupons), 2);
$c = $coupons[0];
check('fixed cart, RM100, usage limit 1', [$c->get_discount_type(), $c->get_amount('edit'), $c->get_usage_limit()], ['fixed_cart', '100.00', 1]);
check('the custom card is RM75', $coupons[1]->get_amount('edit'), '75.00');
check('no email restriction (anyone can spend it; also keeps Coreem reminders away)', $c->get_email_restrictions(), []);
check('not individual use, published, no free shipping', [$c->get_individual_use(), $c->get_status(), $c->get_free_shipping()], [false, 'publish', false]);
check('the gift card product is not on the coupon exclusion list (excluded in code instead)', $c->get_excluded_product_ids(), []);
check('code format GIFT-XXXX-XXXX-XXXX', (bool) preg_match(Galado_GC_Codes::PATTERN, strtoupper($c->get_code())), true);
check('the two codes differ', $coupons[0]->get_code() !== $coupons[1]->get_code(), true);
$paid = $order->get_date_paid()->getTimestamp();
$expected = (new DateTimeImmutable('@' . $paid))->setTimezone($tz)->modify('+3 years')->format('Y-m-d') . ' 23:59:59';
check('expires at 23:59:59 Malaysian time three years after payment',
    $c->get_date_expires()->setTimezone($tz)->format('Y-m-d H:i:s'), $expected);
$items = array_values($order->get_items());
check('meta: gift flag, source order and order line', [$c->get_meta('_galado_gift_card'), (int) $c->get_meta('_galado_gc_order_id'), (int) $c->get_meta('_galado_gc_order_item_id')],
    ['yes', $order->get_id(), $items[0]->get_id()]);
check('the line records its coupon ids', $items[0]->get_meta('_galado_gc_coupon_ids'), [$c->get_id()]);
check('recognised by the public helper', galado_gift_cards_is_gift_card_code(strtoupper($c->get_code())), true);

echo "-- exactly once\n";
$order->update_status('completed');
check('processing then completed: still two', count(gct_coupons(wc_get_order($order->get_id()))), 2);
for ($i = 0; $i < 3; $i++) {
    do_action('woocommerce_order_status_processing', $order->get_id(), $order);
    do_action('woocommerce_order_status_completed', $order->get_id(), $order);
    Galado_GC_Issuer::issue_for_order($order->get_id());
}
check('both hooks fired three more times and a direct call: still two', count(gct_coupons(wc_get_order($order->get_id()))), 2);
global $wpdb;
$rows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_galado_gc_order_id' AND meta_value = %s", (string) $order->get_id()));
check('and the database agrees: two coupon rows for this order', $rows, 2);

echo "-- a disabled or binned card is never reissued under a new code\n";
$first = $coupons[0];
$first->set_status('draft');
$first->save();
wp_trash_post($coupons[1]->get_id());
Galado_GC_Issuer::issue_for_order($order->get_id());
check('still two coupon rows, no new code', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_galado_gc_order_id' AND meta_value = %s", (string) $order->get_id())), 2);

echo "-- never for unpaid, failed or cancelled orders\n";
foreach (['pending', 'on-hold', 'failed', 'cancelled'] as $status) {
    $o = gct_paid_order([['value' => 50, 'label' => 'RM50']], $status === 'pending' ? null : $status);
    check("{$status}: no code", count(gct_coupons($o)), 0);
}
$o = gct_paid_order([['value' => 50, 'label' => 'RM50']], 'on-hold');
$o->update_status('processing');
check('on-hold then paid: codes appear once paid', count(gct_coupons(wc_get_order($o->get_id()))), 1);

echo "-- a line with quantity 2 (e.g. made in admin) gets two cards\n";
$o = gct_paid_order([['value' => 50, 'label' => 'RM50', 'qty' => 2]]);
check('two coupons for the one line', count(gct_coupons($o)), 2);

echo "-- a line with no value is not guessed\n";
$o = gct_paid_order([['value' => 0, 'label' => 'RM50']], null);
$o->update_status('processing');
check('no code, and a note for staff', [count(gct_coupons(wc_get_order($o->get_id()))), false !== strpos(gct_notes_text(wc_get_order($o->get_id())), 'has no value')], [0, true]);

echo "-- codes never appear in order notes (they are logs)\n";
$o = gct_paid_order([['value' => 100]]);
$notes = gct_notes_text($o);
check('the issuing note names only the last four characters', false !== strpos($notes, 'ending ' . Galado_GC_Codes::tail(gct_coupons($o)[0]->get_code())), true);
check('no full code in any note', preg_match(Galado_GC_Codes::FIND_PATTERN, $notes), 0);

echo "-- Points and Rewards is active here, as on live (it once stopped every card being created)\n";
check('P&R active in this run', class_exists('WC_Points_Rewards_Discount') ? 'active' : 'not installed (setup.sh GCT_PR_DIR)', class_exists('WC_Points_Rewards_Discount') ? 'active' : 'not installed (setup.sh GCT_PR_DIR)');
if (class_exists('WC_Points_Rewards_Discount')) {
    $bare = new WC_Coupon();
    $bare->set_code('probe-bare-coupon');
    $bare->set_discount_type('fixed_cart');
    $bare->set_amount('1');
    check('negative control: with P&R active, a coupon made by `new WC_Coupon()` is never saved', (int) $bare->save(), 0);
}
$o = gct_paid_order([['value' => 100]]);
check('with no session (a payment webhook), the card is a real, saved coupon', [count(gct_coupons($o)), gct_coupons($o)[0]->get_id() > 0, get_post_type(gct_coupons($o)[0]->get_id())], [1, true, 'shop_coupon']);

echo "-- a failure is retried, further apart each time, then staff are told\n";
// The database refuses every new coupon, the way a clash with another plugin made saves vanish.
$boom = function ($refuse, $postarr) {
    return 'shop_coupon' === ($postarr['post_type'] ?? '') ? true : $refuse;
};
$pending = function ($id) {
    return as_get_scheduled_actions(['hook' => Galado_GC_Issuer::RETRY_HOOK, 'args' => [$id], 'group' => Galado_GC_Issuer::GROUP,
        'status' => ActionScheduler_Store::STATUS_PENDING], 'ids');
};
$run_try = function ($id) use ($pending) { // as Action Scheduler runs it: marked running, then executed
    ActionScheduler::runner()->process_action((int) current($pending($id)), 'test');
};
$o = gct_paid_order([['value' => 50, 'label' => 'RM50']], null);
$id = $o->get_id();
add_filter('wp_insert_post_empty_content', $boom, 10, 2);
$o->set_date_paid(time());
$o->update_status('processing');
check('first failure: no code, one try booked, attempt 1', [count(gct_coupons(wc_get_order($id))), count($pending($id)), wc_get_order($id)->get_meta(Galado_GC_Issuer::ORDER_ATTEMPTS)], [0, 1, '1']);
$run_try($id);
check('the booked try fails too: the next one is booked from inside it (attempt 2)', [count($pending($id)), wc_get_order($id)->get_meta(Galado_GC_Issuer::ORDER_ATTEMPTS)], [1, '2']);
$o = wc_get_order($id);
$o->update_meta_data(Galado_GC_Issuer::ORDER_ATTEMPTS, (string) Galado_GC_Issuer::MAX_ATTEMPTS);
$o->save_meta_data();
$run_try($id);
$notes = gct_notes_text(wc_get_order($id));
check('after the last try: nothing more booked, and a note tells staff', [count($pending($id)), false !== strpos($notes, 'could not be created')], [0, true]);
check('no note ever claimed a code was issued', false !== strpos($notes, 'Issued'), false);
remove_filter('wp_insert_post_empty_content', $boom, 10);
wc_get_order($id)->update_status('completed');
check('fixed, then moved to Completed as the note says: the card is issued and the count cleared',
    [count(gct_coupons(wc_get_order($id))), wc_get_order($id)->get_meta(Galado_GC_Issuer::ORDER_ATTEMPTS)], [1, '']);

done();
