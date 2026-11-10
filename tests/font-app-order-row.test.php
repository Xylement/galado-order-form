<?php
/**
 * galado-font-preview: app orders get the real font name for staff (v1.4.2).
 * Run: php tests/font-app-order-row.test.php  (exits 1 on any failure)
 *
 * Replays the Club bridge's /app-order save order: add_product() saves the line (woocommerce_new_order_item, no
 * custom rows yet), the bridge adds "Font Style: <id> - text - colour" with $item->save() (an update), reads the
 * rows back and cancels the order if a value changed, then sets _galado_app_order and saves the order. The row
 * must still be the code at the read-back and the real name after the final save. Lives outside the plugin
 * folder, so Git Sync never ships it.
 */

define('ABSPATH', __DIR__);

// ---------------------------------------------------------------- WP / WC stubs
$GLOBALS['hooks'] = []; $GLOBALS['db'] = []; $GLOBALS['notes'] = [];
function add_action($h, $cb) { $GLOBALS['hooks'][$h][] = $cb; }
function add_filter($h, $cb) { $GLOBALS['hooks'][$h][] = $cb; }
function do_action($h, ...$a) { foreach ($GLOBALS['hooks'][$h] ?? [] as $cb) call_user_func_array($cb, $a); }
function wp_json_encode($v) { return json_encode($v); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { public function __construct($c = '', $m = '') {} }

class WC_Order_Item_Product {
    public $id; public $order_id; public $meta = [];
    public function __construct($id = 0, $order_id = 0) { $this->id = $id; $this->order_id = $order_id; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function add_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function get_product_id() { return 233902; }
    public function save() { // update path: no woocommerce_new_order_item
        $GLOBALS['db'][$this->id] = $this->meta;
        do_action('woocommerce_update_order_item', $this->id, $this, $this->order_id);
    }
}
class WC_Order_Factory {
    public static function get_order_item($id) { $i = new WC_Order_Item_Product($id, 1); $i->meta = $GLOBALS['db'][$id]; return $i; }
}
class WC_Order {
    public $id; public $status = 'pending'; public $meta = []; public $items = [];
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function add_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function get_items($t = '') { return $this->items; } // the order's own copies: no custom rows
    public function add_order_note($n) { $GLOBALS['notes'][] = $n; }
    public function add_product() { // saves the line first, like WooCommerce
        $item = new WC_Order_Item_Product(100 + count($this->items), $this->id);
        $GLOBALS['db'][$item->id] = [];
        do_action('woocommerce_new_order_item', $item->id, $item, $this->id);
        $this->items[$item->id] = $item;
        return $item->id;
    }
    public function save() { do_action('woocommerce_after_order_object_save', $this, null); }
}
function wc_get_order($id) { return new WC_Order($id); }

require __DIR__ . '/../galado-font-preview/includes/class-font-product-picker.php';
class Test_Picker extends Galado_Font_Product_Picker {
    public function picture(array $c) { return 'https://example.test/' . $c['id'] . '.png'; }
}
$picker = new Test_Picker(['Gotcha' => 'gotcha.ttf', 'Rustling Sound' => 'rustling.otf']);

// ----------------------------------------------------------------- runner
$failed = 0;
function check($label, $cond) {
    global $failed;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$cond) $failed++;
}
$code = Galado_Font_Product_Picker::id_for('Gotcha');
check('Gotcha id is f379a29', 'f379a29' === $code);

/** The bridge's steps for one line; returns the value its read-back saw. */
function bridge_line(WC_Order $order, $value) {
    $item_id = $order->add_product();
    $item = WC_Order_Factory::get_order_item($item_id);
    $item->add_meta_data('Font Style', $value);
    $item->save();
    return [$item_id, $GLOBALS['db'][$item_id]['Font Style']];
}

// 1. An app order: code at the read-back, name after the final save.
$order = new WC_Order(430700);
[$id, $readback] = bridge_line($order, $code . ' - BearBee - Black');
check('read-back still sees what the app sent (order not cancelled)', $code . ' - BearBee - Black' === $readback);
$order->save(); // an earlier save (calculate_totals) before _galado_app_order is set
check('no rewrite before _galado_app_order is set', $code . ' - BearBee - Black' === $GLOBALS['db'][$id]['Font Style']);
$order->add_meta_data('_galado_app_order', '1');
$order->save();
check('staff row has the real name', 'Gotcha - BearBee - Black' === $GLOBALS['db'][$id]['Font Style']);
$hidden = json_decode($GLOBALS['db'][$id]['_galado_font'], true);
check('hidden copy has id and name', 'f379a29' === $hidden['id'] && 'Gotcha' === $hidden['font'] && 'BearBee' === $hidden['text']);
check('picture saved', 'https://example.test/f379a29.png' === $GLOBALS['db'][$id]['_galado_font_img']);
$order->save();
check('a second save changes nothing', 'Gotcha - BearBee - Black' === $GLOBALS['db'][$id]['Font Style']);

// 2. Old preview rows and non-app orders are left alone.
$order2 = new WC_Order(430701);
[$id2] = bridge_line($order2, 'Rustling Sound');
$order2->add_meta_data('_galado_app_order', '1');
$order2->save();
check('older preview row "Font Style: <name>" untouched', 'Rustling Sound' === $GLOBALS['db'][$id2]['Font Style'] && !isset($GLOBALS['db'][$id2]['_galado_font']));

$order3 = new WC_Order(430702);
[$id3] = bridge_line($order3, $code . ' - Jo - White');
$order3->save();
check('non-app order untouched', $code . ' - Jo - White' === $GLOBALS['db'][$id3]['Font Style']);

$order4 = new WC_Order(430703);
[$id4] = bridge_line($order4, $code . ' - Jo - White');
$order4->add_meta_data('_galado_app_order', '1');
$order4->status = 'processing';
$order4->save();
check('only while pending (no rewrite on later saves of paid orders)', $code . ' - Jo - White' === $GLOBALS['db'][$id4]['Font Style']);

// 3. A broken code row gets one note, no rewrite.
$order5 = new WC_Order(430704);
[$id5] = bridge_line($order5, 'f000000 - Jo - White');
$order5->add_meta_data('_galado_app_order', '1');
$order5->save();
check('unknown code: row kept, note added', 'f000000 - Jo - White' === $GLOBALS['db'][$id5]['Font Style'] && 1 === count($GLOBALS['notes']));

exit($failed ? 1 : 0);
