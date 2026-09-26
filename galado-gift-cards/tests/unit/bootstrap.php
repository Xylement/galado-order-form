<?php
/**
 * Stubbed WordPress / WooCommerce for the pure-logic unit tests. The real behaviour against
 * WooCommerce 10.5.3 is covered by tests/integration (Docker). Loads the plugin's classes, not the
 * main file (which needs a WordPress install).
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('GALADO_GC_DIR', dirname(__DIR__, 2) . '/');

$GLOBALS['__options'] = [];
$GLOBALS['__post_meta'] = [];      // [post_id][key] = value
$GLOBALS['__post_parent'] = [];    // [post_id] = parent id
$GLOBALS['__post_type'] = [];      // [post_id] = type
$GLOBALS['__coupon_posts'] = [];   // [lowercase code] = id
$GLOBALS['__transients'] = [];
$GLOBALS['__notices'] = [];
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function __($t) { return $t; }
function esc_html__($t) { return $t; }
function _n($single, $plural, $n) { return $n === 1 ? $single : $plural; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return (string) $s; }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_email($s) { return preg_replace('/[^a-z0-9+_.@-]/i', '', (string) $s); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function is_email($s) { return (bool) filter_var($s, FILTER_VALIDATE_EMAIL); }
function wc_format_decimal($n, $dp = false) { return $dp === false ? (string) $n : number_format((float) $n, $dp, '.', ''); }
function wc_get_price_decimals() { return 2; }
function number_format_i18n($n) { return number_format((float) $n); }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__options']) ? $GLOBALS['__options'][$k] : $d; }
function get_post_meta($id, $k, $single = false) { return isset($GLOBALS['__post_meta'][$id][$k]) ? $GLOBALS['__post_meta'][$id][$k] : ''; }
function wp_get_post_parent_id($id) { return isset($GLOBALS['__post_parent'][$id]) ? $GLOBALS['__post_parent'][$id] : 0; }
function get_post_type($id) { return isset($GLOBALS['__post_type'][$id]) ? $GLOBALS['__post_type'][$id] : 'product'; }
function get_transient($k) { return isset($GLOBALS['__transients'][$k]) ? $GLOBALS['__transients'][$k] : false; }
function set_transient($k, $v, $ttl) { $GLOBALS['__transients'][$k] = $v; return true; }
function wc_add_notice($m, $type = 'success') { $GLOBALS['__notices'][] = [$type, $m]; }
function is_wp_error($x) { return $x instanceof WP_Error; }
function wc_price($amount, $args = []) { $d = isset($args['decimals']) ? $args['decimals'] : 2; return '<span class="amount">RM' . number_format((float) $amount, $d, '.', ',') . '</span>'; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function add_action() {}
function add_filter() {}
function add_shortcode() {}

class WP_Error {
    private $m;
    public function __construct($code = '', $message = '') { $this->m = $message; }
    public function get_error_message() { return $this->m; }
}

/** Minimal $wpdb: answers the two queries the pure paths make (coupon by code, lock). */
class Galado_Test_WPDB {
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $prefix = 'wp_';
    public function prepare($q, ...$args) { return [$q, $args]; }
    public function get_var($prepared) {
        list($q, $args) = $prepared;
        if (false !== strpos($q, "post_type = 'shop_coupon'")) {
            $code = strtolower($args[0]);
            return isset($GLOBALS['__coupon_posts'][$code]) ? $GLOBALS['__coupon_posts'][$code] : null;
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new Galado_Test_WPDB();

class WC_Product {
    public $id; public $parent;
    public function __construct($id, $parent = 0) { $this->id = $id; $this->parent = $parent; }
    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent; }
}
class WC_Coupon {
    public $code; public $meta = []; public $held = 0;
    public function __construct($code = '', $meta = [], $held = 0) { $this->code = strtolower($code); $this->meta = $meta; $this->held = $held; }
    public function get_id() { return 1; }
    public function get_code() { return $this->code; }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
    public function get_data_store() { return new WC_Coupon_Data_Store_Stub($this->held); }
}
/** WooCommerce returns the held count from $wpdb->get_var(), so as a string. */
class WC_Coupon_Data_Store_Stub {
    private $held;
    public function __construct($held) { $this->held = $held; }
    public function get_tentative_usage_count($id) { return (string) $this->held; }
}
class WC_Discounts {
    private $items;
    public function __construct($items) { $this->items = $items; }
    public function get_items_to_validate() { return $this->items; }
}

require GALADO_GC_DIR . 'includes/class-galado-gc-config.php';
require GALADO_GC_DIR . 'includes/class-galado-gc-time.php';
require GALADO_GC_DIR . 'includes/class-galado-gc-codes.php';
require GALADO_GC_DIR . 'includes/class-galado-gc-product.php';
require GALADO_GC_DIR . 'includes/class-galado-gc-cart.php';
require GALADO_GC_DIR . 'includes/class-galado-gc-spending.php';
require GALADO_GC_DIR . 'includes/class-galado-gc-remainder.php';
require GALADO_GC_DIR . 'includes/class-galado-gc-checker.php';

/** Mark a product (and optional variations) as the gift card. */
function galado_test_gift_product($id, array $variations = []) {
    $GLOBALS['__post_meta'][$id]['_galado_gift_card'] = 'yes';
    foreach ($variations as $vid) {
        $GLOBALS['__post_parent'][$vid] = $id;
        $GLOBALS['__post_type'][$vid] = 'product_variation';
    }
}

/** Register an issued gift card code as a coupon post. */
function galado_test_gift_code($code, $id) {
    $GLOBALS['__coupon_posts'][strtolower($code)] = $id;
    $GLOBALS['__post_meta'][$id]['_galado_gift_card'] = 'yes';
}

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
