<?php
/**
 * Shared helpers for the integration tests. Each test file runs inside a real WordPress 6.9.9 +
 * WooCommerce 10.5.3 through `wp eval-file`, on a throwaway database reset before every file
 * (see run.sh). Nothing here ever talks to the live store.
 */
if (!defined('ABSPATH') || !class_exists('WooCommerce')) {
    fwrite(STDERR, "Run through tests/integration/run.sh (wp eval-file inside the test WordPress).\n");
    exit(1);
}
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__mail'] = [];

function check($label, $got, $want) {
    if ($got === $want) {
        $GLOBALS['__pass']++;
        echo "ok   {$label}\n";
    } else {
        $GLOBALS['__fail']++;
        echo "FAIL {$label}\n     want " . var_export($want, true) . "\n     got  " . var_export($got, true) . "\n";
    }
}

function done() {
    echo "\n{$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed\n";
    exit($GLOBALS['__fail'] ? 1 : 0);
}

// Every email is captured here instead of sent.
add_filter('pre_wp_mail', function ($null, $atts) {
    $GLOBALS['__mail'][] = $atts;
    return true;
}, 10, 2);

function gct_mail_reset() { $GLOBALS['__mail'] = []; }
function gct_mails_to($address) {
    return array_values(array_filter($GLOBALS['__mail'], function ($m) use ($address) {
        return in_array($address, (array) $m['to'], true);
    }));
}

/** The gift card product, created and then published the way launch day does it. */
function gct_product() {
    static $id = 0;
    if (!$id) {
        $id = Galado_GC_Product::create_product();
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        wc_delete_product_transients($id);
    }
    return $id;
}

function gct_variation($label) {
    $product = wc_get_product(gct_product());
    foreach ($product->get_children() as $vid) {
        $v = wc_get_product($vid);
        if ($v->get_attribute('amount') === $label) {
            return $vid;
        }
    }
    throw new RuntimeException("no variation {$label}");
}

function gct_charm($price = 60, $name = 'Test Charm') {
    $p = new WC_Product_Simple();
    $p->set_name($name);
    $p->set_regular_price((string) $price);
    $p->set_status('publish');
    return $p->save();
}

/** A fresh cart, as a shopper from $country. */
function gct_cart($country = 'MY') {
    if (!did_action('woocommerce_load_cart_from_session')) {
        wc_load_cart();
    }
    WC()->cart->empty_cart();
    wc_clear_notices();
    WC()->customer->set_billing_country($country);
    WC()->customer->set_shipping_country($country);
    WC()->customer->set_shipping_postcode('');
    return WC()->cart;
}

/**
 * Add a gift card exactly as the product page does: a POST through WooCommerce's own form handler
 * (WC_Form_Handler::add_to_cart_action), which is where woocommerce_add_to_cart_validation runs.
 * Calling WC()->cart->add_to_cart() directly would skip validation.
 *
 * @return string|false the new cart item key, or false if nothing was added
 */
function gct_add_gift($label = 'RM100', array $fields = []) {
    $vid = gct_variation($label);
    $post = array_merge([
        'add-to-cart'               => (string) gct_product(),
        'product_id'                => (string) gct_product(),
        'variation_id'              => (string) $vid,
        'quantity'                  => '1',
        'attribute_amount'          => $label,
        'galado_gc_recipient_name'  => 'Mei Chin',
        'galado_gc_recipient_email' => 'recipient@example.test',
        'galado_gc_message'         => 'Happy birthday!',
        'galado_gc_delivery_date'   => Galado_GC_Time::today(),
    ], $fields);
    $_POST = wp_slash($post);          // WordPress hands plugins slashed input
    $_REQUEST = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $before = array_keys(WC()->cart->get_cart());
    WC_Form_Handler::add_to_cart_action();
    $_POST = $_REQUEST = [];
    $new = array_values(array_diff(array_keys(WC()->cart->get_cart()), $before));
    return $new ? $new[0] : false;
}

function gct_notices($type = 'error') {
    $all = wc_get_notices($type);
    return array_map(function ($n) { return is_array($n) ? wp_strip_all_tags($n['notice']) : wp_strip_all_tags($n); }, $all);
}

/** Create a pending order from the current cart through the same path classic checkout uses. */
function gct_order_from_cart($email = 'buyer@example.test') {
    WC()->cart->calculate_totals();
    $order_id = WC()->checkout()->create_order([
        'billing_email'      => $email,
        'billing_first_name' => 'Clement',
        'billing_last_name'  => 'Tester',
        'billing_country'    => WC()->customer->get_billing_country(),
        'payment_method'     => 'bacs',
    ]);
    if (is_wp_error($order_id)) {
        throw new RuntimeException($order_id->get_error_message());
    }
    return wc_get_order($order_id);
}

/** A paid gift card order built directly (bypassing the cart), for issuing/refund tests. */
function gct_paid_order(array $lines, $status = 'processing', $email = 'buyer@example.test') {
    $order = wc_create_order();
    $order->set_billing_email($email);
    $order->set_billing_first_name('Clement');
    foreach ($lines as $line) {
        $label = isset($line['label']) ? $line['label'] : 'RM100';
        $item = new WC_Order_Item_Product();
        $item->set_product(wc_get_product(gct_variation($label)));
        $item->set_quantity(isset($line['qty']) ? $line['qty'] : 1);
        $item->set_total((string) ($line['value'] * (isset($line['qty']) ? $line['qty'] : 1)));
        $item->add_meta_data('_galado_gc_value', number_format($line['value'], 2, '.', ''), true);
        $item->add_meta_data('_galado_gc_recipient_name', isset($line['name']) ? $line['name'] : 'Mei Chin', true);
        $item->add_meta_data('_galado_gc_recipient_email', isset($line['email']) ? $line['email'] : 'recipient@example.test', true);
        $item->add_meta_data('_galado_gc_message', isset($line['message']) ? $line['message'] : '', true);
        $item->add_meta_data('_galado_gc_delivery_date', isset($line['date']) ? $line['date'] : Galado_GC_Time::today(), true);
        $order->add_item($item);
    }
    $order->calculate_totals();
    $order->save();
    if ($status) {
        $order->set_date_paid(time());
        $order->update_status($status);
    }
    return wc_get_order($order->get_id());
}

function gct_coupons($order) {
    $out = [];
    foreach (Galado_GC_Issuer::coupons_for_order($order) as $item_id => $cards) {
        foreach ($cards as $n => $id) {
            $out[] = new WC_Coupon($id);
        }
    }
    return $out;
}

/** Run the plugin's pending Action Scheduler jobs now. */
function gct_run_jobs() {
    $store = ActionScheduler::store();
    $ids = $store->query_actions(['group' => 'galado-gift-cards', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 100, 'date' => as_get_datetime_object(time() + 1), 'date_compare' => '<=']);
    foreach ($ids as $id) {
        ActionScheduler::runner()->process_action($id, 'integration-test');
    }
    return count($ids);
}

function gct_notes_text($order) {
    return implode("\n", array_map(function ($n) { return $n->content; }, wc_get_order_notes(['order_id' => $order->get_id()])));
}

function gct_shipping_zones() {
    foreach ((new WC_Shipping_Zones())->get_zones() as $z) {
        (new WC_Shipping_Zone($z['id']))->delete();
    }
    $my = new WC_Shipping_Zone();
    $my->set_zone_name('Free Shipping (MY Only)');
    $my->add_location('MY', 'country');
    $my->save();
    $my->add_shipping_method('free_shipping');
    $sg = new WC_Shipping_Zone();
    $sg->set_zone_name('Worldwide');
    $sg->add_location('SG', 'country');
    $sg->save();
    $free = $sg->add_shipping_method('free_shipping');
    update_option('woocommerce_free_shipping_' . $free . '_settings', ['title' => 'FREE shipping', 'requires' => 'min_amount', 'min_amount' => '150', 'ignore_discounts' => 'no']);
    $flat = $sg->add_shipping_method('flat_rate');
    update_option('woocommerce_flat_rate_' . $flat . '_settings', ['title' => "Int'l Shipping", 'cost' => '30', 'tax_status' => 'none']);
    WC_Cache_Helper::get_transient_version('shipping', true);
}

/** Rates offered for the cart right now, as method ids. */
function gct_rates() {
    WC()->cart->calculate_totals();
    $ids = [];
    foreach (WC()->shipping()->get_packages() as $pkg) {
        foreach ($pkg['rates'] as $rate) {
            $ids[] = $rate->get_method_id();
        }
    }
    sort($ids);
    return $ids;
}
