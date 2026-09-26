<?php
/**
 * Launch-day product creation, the product page fields through WooCommerce's real add to cart,
 * custom amount pricing, the limits, the app path, and the order line meta.
 */
require __DIR__ . '/bootstrap.php';

function gct_variation_raw($product_id, $label) {
    foreach (wc_get_product($product_id)->get_children() as $vid) {
        if (wc_get_product($vid)->get_attribute('amount') === $label) {
            return $vid;
        }
    }
    return 0;
}

echo "-- the product, as launch day creates it\n";
$id = Galado_GC_Product::create_product();
$p = wc_get_product($id);
check('a variable product named GALADO Gift Card', [$p->get_type(), $p->get_name()], ['variable', 'GALADO Gift Card']);
check('created Private (staff can preview, customers cannot see it)', $p->get_status(), 'private');
check('negative control: while Private, a visitor cannot buy it', wc_get_product(gct_variation_raw($id, 'RM100'))->is_purchasable(), false);
check('publishing it (launch day) is the step that opens sales', gct_product(), $id);
$p = wc_get_product($id);
check('not taxable, sold individually, no reviews', [$p->get_tax_status(), $p->get_sold_individually(), $p->get_reviews_allowed()], ['none', true, false]);
check('flagged as the gift card', Galado_GC_Product::is_gift_card_product($id), true);
$labels = [];
$prices = [];
foreach ($p->get_children() as $vid) {
    $v = wc_get_product($vid);
    $labels[] = $v->get_attribute('amount');
    $prices[] = $v->get_regular_price();
    if (!$v->is_virtual()) {
        $labels[] = 'NOT VIRTUAL';
    }
}
check('variations RM50 to RM300 and a custom amount, all virtual', $labels, ['RM50', 'RM100', 'RM150', 'RM200', 'RM300', 'Custom amount']);
check('priced 50 to 300, the custom one from 30', $prices, ['50', '100', '150', '200', '300', '30']);
check('each variation is recognised as the gift card', Galado_GC_Product::is_gift_card_product(gct_variation('RM150')), true);
check('running the creator again returns the same product, never a second', Galado_GC_Product::create_product(), $id);
check('an ordinary product is not a gift card', Galado_GC_Product::is_gift_card_product(gct_charm()), false);
check('the public helper agrees', galado_gift_cards_is_gift_card_product(gct_variation('RM50')), true);

echo "-- product page fields through the real add to cart\n";
gct_cart();
$key = gct_add_gift('RM100', ['galado_gc_message' => 'Hi <b>there</b>']);
check('added', is_string($key) && '' !== $key, true);
$item = WC()->cart->get_cart_item($key);
check('the fields travel in the cart item', [$item['galado_gc']['value'], $item['galado_gc']['recipient_email'], $item['galado_gc']['message']],
    [100.0, 'recipient@example.test', 'Hi there']);
check('quantity is always one card per line (sold individually)', $item['quantity'], 1);
$key2 = gct_add_gift('RM100', ['galado_gc_recipient_email' => 'someone.else@example.test']);
check('a second card to someone else is its own line', count(WC()->cart->get_cart()), 2);
gct_cart();
check('a missing email is refused with a clear message', [gct_add_gift('RM100', ['galado_gc_recipient_email' => '']), gct_notices()],
    [false, ["Please enter the recipient's email address."]]);
gct_cart();
check('a date in the past is refused', [gct_add_gift('RM100', ['galado_gc_delivery_date' => '2020-01-01']), gct_notices()],
    [false, ['Please choose a delivery date from today up to one year ahead.']]);

echo "-- custom amount: priced from the cart, in ringgit\n";
gct_cart();
$ck = gct_add_gift('Custom amount', ['galado_gc_custom_amount' => '75']);
WC()->cart->calculate_totals();
check('a RM75 custom card costs RM75', [WC()->cart->get_cart_item($ck)['line_total'], WC()->cart->get_total('edit')], [75.0, '75.00']);
WC()->cart->calculate_totals();
WC()->cart->calculate_totals();
check('recalculating never compounds the price', WC()->cart->get_total('edit'), '75.00');
gct_cart();
check('RM1,001 custom is refused', [gct_add_gift('Custom amount', ['galado_gc_custom_amount' => '1001']), gct_notices()],
    [false, ['A custom amount can be from RM30 to RM1,000.']]);

echo "-- RM2,000 of gift cards per order\n";
gct_cart();
gct_add_gift('Custom amount', ['galado_gc_custom_amount' => '1000', 'galado_gc_recipient_email' => 'a@example.test']);
gct_add_gift('Custom amount', ['galado_gc_custom_amount' => '1000', 'galado_gc_recipient_email' => 'b@example.test']);
wc_clear_notices();
check('RM2,000 in the cart, then a RM50 card is refused', [gct_add_gift('RM50', ['galado_gc_recipient_email' => 'c@example.test']), gct_notices()],
    [false, ['Gift cards in one order can add up to RM2,000. Please check out what is in your cart first.']]);
check('the cart helper reports RM2,000', galado_gift_cards_cart_gift_total(), 2000.0);
update_option('galado_gift_cards_max_order', '1500');
wc_clear_notices();
do_action('woocommerce_check_cart_items');
check('lowering the order limit to RM1,500 blocks checkout of that cart', gct_notices(), ['Gift cards in one order can add up to RM1,500. Please remove a gift card.']);
delete_option('galado_gift_cards_max_order');

echo "-- a gift line added by code that skips the product page cannot reach checkout\n";
gct_cart();
WC()->cart->add_to_cart(gct_product(), 1, gct_variation('RM100'), ['attribute_amount' => 'RM100']);
wc_clear_notices();
do_action('woocommerce_check_cart_items');
check('checkout is blocked with a clear message', gct_notices(), ['Please remove the gift card from your cart and add it again from its page.']);

echo "-- the order line carries the fields; customers see them, never a code\n";
gct_cart();
gct_add_gift('RM150', ['galado_gc_recipient_name' => 'Jade', 'galado_gc_message' => 'Love you']);
$order = gct_order_from_cart();
$line = current($order->get_items());
check('hidden meta on the line', [$line->get_meta('_galado_gc_value'), $line->get_meta('_galado_gc_recipient_name'), $line->get_meta('_galado_gc_message')],
    ['150.00', 'Jade', 'Love you']);
$shown = [];
foreach ($line->get_formatted_meta_data() as $m) {
    $shown[$m->display_key] = wp_strip_all_tags($m->display_value);
}
check('readable rows for emails and My Account', [trim($shown['Card value']), trim($shown['To']), trim($shown['Message'])],
    ['RM150', 'Jade (recipient@example.test)', 'Love you']);
check('no hidden key is shown', count(array_filter(array_keys($shown), function ($k) { return 0 === strpos($k, '_'); })), 0);
check('the order helper reports RM150', galado_gift_cards_order_gift_total($order), 150.0);

echo "-- the app cannot buy a card yet (Store API)\n";
gct_cart();
add_filter('woocommerce_store_api_disable_nonce_check', '__return_true');
$store_add = function () {
    $req = new WP_REST_Request('POST', '/wc/store/v1/cart/add-item');
    $req->set_param('id', gct_variation('RM100'));
    $req->set_param('quantity', 1);
    $res = rest_do_request($req);
    return [$res->get_status() >= 400, $res->get_data()['message'] ?? '', count(WC()->cart->get_cart())];
};
$r = $store_add();
check('refused, and nothing reaches the cart', [$r[0], $r[2]], [true, 0]);
// A real call from the app arrives as a REST request (REST_REQUEST is defined); in-process
// dispatch does not define it, so mark it the way the live request is marked.
define('REST_REQUEST', true);
check('as a real app request: the message says where to buy', $store_add(), [true, 'Gift cards can be bought on galado.com.my for now.', 0]);

done();
