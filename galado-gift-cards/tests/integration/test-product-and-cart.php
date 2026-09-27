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
check('the limit counts RM2,000 in the cart', Galado_GC_Cart::cart_gift_total(), 2000.0);
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

echo "-- the price shown for the card\n";
$gp = wc_get_product(gct_product());
$price = html_entity_decode(wp_strip_all_tags($gp->get_price_html()), ENT_QUOTES, 'UTF-8');
check('"RM30.00 to RM1,000.00": no en dash, and the real top of the range', [$price, preg_match('/\x{2013}|\x{2014}/u', $price)], ['RM30.00 to RM1,000.00', 0]);
$by_label = [];
foreach ($gp->get_available_variations() as $v) {
    $by_label[$v['attributes']['attribute_amount']] = html_entity_decode(wp_strip_all_tags($v['price_html']), ENT_QUOTES, 'UTF-8');
}
check('choosing "Custom amount" shows the range, not its RM30 floor', $by_label['Custom amount'], 'RM30.00 to RM1,000.00');
check('negative control: RM100 shows RM100.00', $by_label['RM100'], 'RM100.00');
check('no points setting on the product or its amounts: the buyer earns on what they pay, like any product',
    array_values(array_unique(array_map(function ($id) { return get_post_meta($id, '_wc_points_earned', true); }, array_merge([gct_product()], $gp->get_children())))), ['']);

echo "-- choosing a design, with a live preview\n";
$GLOBALS['product'] = $gp;
ob_start();
Galado_GC_Product::render_fields();
$page = ob_get_clean();
preg_match_all('/name="galado_gc_design" value="([a-z]+)"/', $page, $m);
check('four designs to choose from, Classic first and chosen', [$m[1], (bool) preg_match('/value="classic"[^>]*checked/', $page)], [['classic', 'birthday', 'thanks', 'festive'], true]);
check('a live preview card with the amount, name and message', [
    false !== strpos($page, 'data-galado-gc-preview'), false !== strpos($page, 'data-gc-amount'),
    false !== strpos($page, 'data-gc-to'), false !== strpos($page, 'data-gc-message'),
], [true, true, true, true]);
check('every design has its artwork in the plugin',
    array_map(function ($key) { return is_readable(GALADO_GC_DIR . 'assets/designs/' . $key . '.jpg'); }, array_keys(Galado_GC_Designs::all())), [true, true, true, true]);
gct_cart();
$key = gct_add_gift('RM100', ['galado_gc_design' => 'birthday']);
$rows = array_column(apply_filters('woocommerce_get_item_data', [], WC()->cart->get_cart_item($key)), 'value', 'key');
check('the cart line shows the design', $rows['Design'] ?? null, 'Birthday');
$order = gct_order_from_cart();
$line = current($order->get_items());
$shown = [];
foreach ($line->get_formatted_meta_data() as $meta) {
    $shown[$meta->display_key] = trim(wp_strip_all_tags($meta->display_value));
}
check('the order line keeps it, and customers see it', [$line->get_meta('_galado_gc_design'), $shown['Design'] ?? null], ['birthday', 'Birthday']);
gct_cart();
wc_clear_notices();
check('an unknown design is refused', [gct_add_gift('RM100', ['galado_gc_design' => 'nope']), gct_notices()], [false, ['Please choose a card design.']]);
$theme_art = get_stylesheet_directory() . '/' . Galado_GC_Designs::THEME_DIR . 'birthday.jpg';
wp_mkdir_p(dirname($theme_art));
copy(GALADO_GC_DIR . 'assets/designs/festive.jpg', $theme_art);
check('real artwork dropped into the child theme is used instead of the placeholder',
    Galado_GC_Designs::image_url('birthday'), get_stylesheet_directory_uri() . '/' . Galado_GC_Designs::THEME_DIR . 'birthday.jpg');
unlink($theme_art);
check('negative control: without it, the plugin placeholder', Galado_GC_Designs::image_url('birthday'), GALADO_GC_URL . 'assets/designs/birthday.jpg');

echo "-- saving the order in admin does not copy the display rows into real order data\n";
$o = gct_paid_order([['value' => 100, 'message' => 'Hi there']]);
$item = current($o->get_items());
$item_id = $item->get_id();
$product = $item->get_product();
$form = function () use ($item, $item_id, $product) {
    ob_start();
    include WC_ABSPATH . 'includes/admin/meta-boxes/views/html-order-item-meta.php';
    return ob_get_clean();
};
$html = $form();
check('the admin item form posts none of the five display rows', preg_match('/meta_key\[' . $item_id . '\]\[galado_gc_/', $html), 0);
check('... while the customer still sees them (emails, My Account)', count(array_filter($item->get_formatted_meta_data(), function ($m) { return 0 === strpos($m->key, 'galado_gc_'); })), 5); // value, to, date, design, message
remove_filter('woocommerce_hidden_order_itemmeta', [Galado_GC_Cart::class, 'hide_display_rows']);
check('negative control: without the fix the form would post them', preg_match('/meta_key\[' . $item_id . '\]\[galado_gc_value\]/', $form()), 1);
add_filter('woocommerce_hidden_order_itemmeta', [Galado_GC_Cart::class, 'hide_display_rows']);

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
