<?php
/**
 * Cancelled or refunded card orders: unused codes disabled, used codes untouched, partial refunds
 * matched to the refunded card lines only, and a disabled code cannot be spent.
 */
require __DIR__ . '/bootstrap.php';

gct_product();
$state = function ($order) {
    $out = [];
    foreach (gct_coupons(wc_get_order($order->get_id())) as $c) {
        $c = new WC_Coupon($c->get_id());
        $out[] = $c->get_status() . ($c->get_usage_count() ? '+used' : '');
    }
    return $out;
};

echo "-- a cancelled order disables its unused codes; a used code stays as it is\n";
$o = gct_paid_order([['value' => 100], ['value' => 50, 'label' => 'RM50']]);
$used = gct_coupons($o)[0];
$used->increase_usage_count('someone@example.test');
$o->update_status('cancelled');
check('card 1 used, card 2 unused: card 2 disabled (draft), card 1 untouched', $state($o), ['publish+used', 'draft']);
check('a note names only the last four characters', (bool) preg_match('/Disabled 1 unused gift card code \(ending [A-Z0-9]{4}\)/', gct_notes_text($o)), true);

echo "-- a fully refunded order\n";
$o = gct_paid_order([['value' => 100], ['value' => 50, 'label' => 'RM50']]);
wc_create_refund(['order_id' => $o->get_id(), 'amount' => $o->get_total(), 'reason' => 'test']);
check('every unused code disabled', $state($o), ['draft', 'draft']);
check('refunded status reached', wc_get_order($o->get_id())->get_status(), 'refunded');

echo "-- a partial refund disables only the refunded card line\n";
$charm = gct_charm(20);
$o = gct_paid_order([['value' => 100], ['value' => 50, 'label' => 'RM50']]);
$charm_item = new WC_Order_Item_Product();
$charm_item->set_product(wc_get_product($charm));
$charm_item->set_total('20');
$o->add_item($charm_item);
$o->calculate_totals();
$o->save();
$lines = array_values(Galado_GC_Cart::order_gift_items($o));
wc_create_refund(['order_id' => $o->get_id(), 'amount' => 50, 'line_items' => [
    $lines[1]->get_id() => ['qty' => 1, 'refund_total' => 50],
]]);
check('the RM50 card is disabled, the RM100 card is not', $state($o), ['publish', 'draft']);
wc_create_refund(['order_id' => $o->get_id(), 'amount' => 20, 'line_items' => [
    $charm_item->get_id() => ['qty' => 1, 'refund_total' => 20],
]]);
check('refunding the charm touches no card', $state($o), ['publish', 'draft']);
wc_create_refund(['order_id' => $o->get_id(), 'amount' => 5]);
check('a refund with no line items changes no card ...', $state($o), ['publish', 'draft']);
check('... and asks staff to disable by hand if it was for a card', false !== strpos(gct_notes_text($o), 'cannot be matched to a gift card'), true);

echo "-- two cards on one line (quantity 2), one refunded\n";
$o = gct_paid_order([['value' => 50, 'label' => 'RM50', 'qty' => 2]]);
$line = current($o->get_items());
wc_create_refund(['order_id' => $o->get_id(), 'amount' => 50, 'line_items' => [$line->get_id() => ['qty' => 1, 'refund_total' => 50]]]);
check('exactly one of the two disabled', $state($o), ['publish', 'draft']);
do_action('woocommerce_order_refunded', $o->get_id(), 0);
Galado_GC_Revoke::revoke_for_line($line->get_id(), 1, 'again');
check('repeating the same refund disables nothing more', $state($o), ['publish', 'draft']);

echo "-- a scheduled delivery is cancelled with its card\n";
$later = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->modify('+30 days')->format('Y-m-d');
$o = gct_paid_order([['value' => 100, 'date' => $later]]);
$args = [$o->get_id(), current($o->get_items())->get_id()];
check('scheduled before', is_int(as_next_scheduled_action(Galado_GC_Delivery::HOOK, $args, Galado_GC_Delivery::GROUP)), true);
$o->update_status('cancelled');
check('unscheduled after cancelling', as_next_scheduled_action(Galado_GC_Delivery::HOOK, $args, Galado_GC_Delivery::GROUP), false);

echo "-- a disabled code cannot be spent\n";
$o = gct_paid_order([['value' => 100]]);
$code = gct_coupons($o)[0]->get_code();
$o->update_status('refunded');
gct_cart();
WC()->cart->add_to_cart(gct_charm(60));
wc_clear_notices();
check('applying it fails', WC()->cart->apply_coupon($code), false);
check('with a plain message that never repeats the code', gct_notices(), ['We couldn’t find that gift card. Check the code and try again.']);
$c = new WC_Coupon(gct_coupons($o)[0]->get_id());
$c->set_status('publish');
$c->save();
wc_clear_notices();
check('publishing its coupon again brings the card back', WC()->cart->apply_coupon($code), true);

echo "-- a refund typed as an amount only (quantity left at 0), as the admin refund screen allows\n";
$o = gct_paid_order([['value' => 100], ['value' => 50, 'label' => 'RM50']]);
$lines = array_values(Galado_GC_Cart::order_gift_items($o));
wc_create_refund(['order_id' => $o->get_id(), 'amount' => 100, 'line_items' => [$lines[0]->get_id() => ['qty' => 0, 'refund_total' => 100]]]);
check('RM100 typed on the RM100 card line: that card is disabled, the RM50 card is not', $state($o), ['draft', 'publish']);
$o = gct_paid_order([['value' => 50, 'label' => 'RM50', 'qty' => 2], ['value' => 100]]);
$line = current(Galado_GC_Cart::order_gift_items($o));
wc_create_refund(['order_id' => $o->get_id(), 'amount' => 70, 'line_items' => [$line->get_id() => ['qty' => 0, 'refund_total' => 70]]]);
check('RM70 on a line of two RM50 cards: one card disabled', $state($o), ['publish', 'draft', 'publish']);
check('... and a note about the extra RM20 that is not a whole card', false !== strpos(gct_notes_text($o), 'Part of a gift card'), true);
$o = gct_paid_order([['value' => 100], ['value' => 50, 'label' => 'RM50']]);
$line = current(Galado_GC_Cart::order_gift_items($o));
wc_create_refund(['order_id' => $o->get_id(), 'amount' => 20, 'line_items' => [$line->get_id() => ['qty' => 0, 'refund_total' => 20]]]);
check('RM20 goodwill on the RM100 card line: no card disabled ...', $state($o), ['publish', 'publish']);
check('... and staff are told to disable it by hand if needed', false !== strpos(gct_notes_text($o), 'Part of a gift card'), true);
check('no note repeats a code', preg_match(Galado_GC_Codes::FIND_PATTERN, gct_notes_text($o)), 0);

echo "-- bringing a card back in the coupon editor keeps its end-of-day expiry\n";
include_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-coupon-data.php';
$editor_save = function ($cid) {
    $c = new WC_Coupon($cid);
    $_POST = [ // what the coupon edit form posts for a gift card
        'discount_type' => 'fixed_cart', 'coupon_amount' => $c->get_amount('edit'),
        'expiry_date' => $c->get_date_expires()->date('Y-m-d'), 'usage_limit' => '1', 'usage_limit_per_user' => '0',
        'limit_usage_to_x_items' => '', 'minimum_amount' => '', 'maximum_amount' => '', 'customer_email' => '',
    ];
    wp_update_post(['ID' => $cid, 'post_status' => 'publish']); // the Publish button
    WC_Meta_Box_Coupon_Data::save($cid, get_post($cid));
    $_POST = [];
    return new WC_Coupon($cid);
};
$o = gct_paid_order([['value' => 100]]);
$cid = gct_coupons($o)[0]->get_id();
$end = (new WC_Coupon($cid))->get_date_expires()->getTimestamp();
$o->update_status('cancelled');
$back = $editor_save($cid);
check('published again from the editor: works again, still until 23:59:59 Malaysian time', [$back->get_status(), $back->get_date_expires()->getTimestamp()], ['publish', $end]);
remove_action('woocommerce_coupon_options_save', [Galado_GC_Admin::class, 'keep_end_of_day_expiry'], 20);
$plain = $editor_save($cid);
add_action('woocommerce_coupon_options_save', [Galado_GC_Admin::class, 'keep_end_of_day_expiry'], 20, 2);
check('negative control: WooCommerce alone moves it to 00:00 that day', $plain->get_date_expires()->getTimestamp(), $end - 86399);

done();
