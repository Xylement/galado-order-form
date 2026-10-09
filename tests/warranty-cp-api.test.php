<?php
/**
 * galado-warranty CP API: auth and guards, without WordPress.
 * Run: php tests/warranty-cp-api.test.php  (exits 1 on any failure)
 *
 * WordPress and the plugin's own classes are stubbed just enough to drive
 * GWARR_CP_API; what is tested is the API layer: who gets in, which states
 * each action refuses, and what it hands to the plugin's existing code.
 * Lives outside the plugin folder, so Git Sync never ships it to the store.
 */

define('ABSPATH', __DIR__);
define('GWARR_TABLE', 'galado_warranties');

// ---------------------------------------------------------------- WP stubs
$GLOBALS['options'] = [];
$GLOBALS['routes'] = [];
$GLOBALS['calls'] = [];
function add_action() {}
function register_rest_route($ns, $path, $args) { $GLOBALS['routes'][$ns . $path] = $args; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function current_time($f) { return $f === 'Y-m-d' ? '2026-10-09' : '2026-10-09 22:00:00'; }
function wp_get_attachment_url($id) { return "https://example.test/u/{$id}.jpg"; }
function wp_get_attachment_image_url($id, $size) { return "https://example.test/u/{$id}-300.jpg"; }
function get_post_mime_type($id) { return 'image/jpeg'; }
function is_wp_error($x) { return $x instanceof WP_Error; }
function error_log_stub() {}

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($c, $m, $d = []) { $this->code = $c; $this->message = $m; $this->data = $d; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public $data; public $status; public $headers = [];
    public function __construct($d, $s) { $this->data = $d; $this->status = $s; }
    public function header($k, $v) { $this->headers[$k] = $v; }
}
class WP_REST_Request implements ArrayAccess {
    private $params; private $headers;
    public function __construct($params = [], $headers = []) {
        $this->params = $params;
        $this->headers = [];
        foreach ($headers as $k => $v) $this->headers[str_replace('-', '_', strtolower($k))] = $v;
    }
    public function get_param($k) { return $this->params[$k] ?? null; }
    public function has_param($k) { return array_key_exists($k, $this->params); }
    public function get_header($k) { return $this->headers[$k] ?? null; }
    #[\ReturnTypeWillChange]
    public function offsetExists($k) { return isset($this->params[$k]); }
    #[\ReturnTypeWillChange]
    public function offsetGet($k) { return $this->params[$k]; }
    #[\ReturnTypeWillChange]
    public function offsetSet($k, $v) { $this->params[$k] = $v; }
    #[\ReturnTypeWillChange]
    public function offsetUnset($k) { unset($this->params[$k]); }
}

// ---------------------------------------------------------- plugin stubs
$GLOBALS['regs'] = [];
$GLOBALS['claims'] = [];
function reg($id, $status, $extra = []) {
    $GLOBALS['regs'][$id] = (object) array_merge([
        'id' => $id, 'status' => $status, 'source' => 'marketplace', 'marketplace' => 'shopee', 'order_number' => 'A1',
        'wc_order_id' => null, 'billing_email' => null, 'user_email' => 'aina@example.test', 'display_name' => 'Aina',
        'product_text' => 'Case', 'notes' => '', 'marketing_consent' => 0, 'purchase_date' => null, 'warranty_ends' => null,
        'coupon_code' => null, 'admin_note' => '', 'approved_at' => null, 'claimed_at' => null, 'created_at' => '2026-10-01 10:00:00',
    ], $extra);
}
function claim($id, $status, $extra = []) {
    $GLOBALS['claims'][$id] = (object) array_merge([
        'id' => $id, 'status' => $status, 'warranty_id' => 1, 'user_id' => 7, 'user_email' => 'aina@example.test', 'display_name' => 'Aina',
        'item_label' => '', 'product_text' => 'Case', 'marketplace' => 'shopee', 'source' => 'marketplace', 'order_number' => 'A1',
        'wc_order_id' => null, 'warranty_ends' => '2027-04-01', 'issue_description' => 'Cracked', 'admin_note' => '', 'shipping_fee' => null,
        'shipping_order_id' => null, 'resolved_at' => null, 'created_at' => '2026-10-02 10:00:00', 'media_ids' => '[3]',
        'delivery_name' => 'Aina', 'delivery_phone' => '', 'delivery_address_1' => '', 'delivery_address_2' => '', 'delivery_city' => '',
        'delivery_state' => '', 'delivery_postcode' => '',
    ], $extra);
}
class GWARR_DB {
    public static function table() { return 'wp_galado_warranties'; }
    public static function find($id) { return $GLOBALS['regs'][$id] ?? null; }
    public static function status_counts() { return ['pending' => 1]; }
    public static function list($a) { $GLOBALS['calls'][] = ['list', $a]; return ['rows' => array_values($GLOBALS['regs']), 'total' => count($GLOBALS['regs'])]; }
    public static function update($id, $a) { $GLOBALS['calls'][] = ['update', $id, $a]; return self::find($id); }
}
class GWARR_Approval {
    public static function approve($id, $date, $note) {
        $GLOBALS['calls'][] = ['approve', $id, $date, $note];
        $GLOBALS['regs'][$id]->status = 'approved';
        $GLOBALS['regs'][$id]->coupon_code = 'W-TEST01';
        return $GLOBALS['regs'][$id];
    }
    public static function reject($id, $reason) {
        $GLOBALS['calls'][] = ['reject', $id, $reason];
        $GLOBALS['regs'][$id]->status = 'rejected';
        return $GLOBALS['regs'][$id];
    }
}
class GWARR_Claims {
    public static $order_on_approve = 501;
    public static $boom = false;
    public static function table() { return 'wp_galado_warranty_claims'; }
    public static function find($id) {
        if (self::$boom) throw new RuntimeException('database went away');
        return $GLOBALS['claims'][$id] ?? null;
    }
    public static function status_counts() { return ['submitted' => 1]; }
    public static function list($a) { return ['rows' => array_values($GLOBALS['claims']), 'total' => count($GLOBALS['claims'])]; }
    public static function latest_for_warranty($id) { return null; }
    public static function media_ids($c) { return json_decode($c->media_ids, true) ?: []; }
    public static function last_order_error() { return 'gateway down'; }
    public static function record_email_status($id, $ok) { $GLOBALS['calls'][] = ['email_status', $id, $ok]; }
    public static function ensure_order_billing($o, $u) {}
    public static function ensure_order_line_item($o) {}
    public static function approve($id, $note, $fee) {
        $GLOBALS['calls'][] = ['claim_approve', $id, $note, $fee];
        $GLOBALS['claims'][$id]->status = 'approved';
        if ($fee > 0) {
            $GLOBALS['claims'][$id]->shipping_fee = $fee;
            $GLOBALS['claims'][$id]->shipping_order_id = self::$order_on_approve;
        }
        return $GLOBALS['claims'][$id];
    }
    public static function reject($id, $note) {
        $GLOBALS['calls'][] = ['claim_reject', $id, $note];
        $GLOBALS['claims'][$id]->status = 'rejected';
        $GLOBALS['claims'][$id]->admin_note = $note;
        return $GLOBALS['claims'][$id];
    }
    public static function set_shipping_fee($id, $fee) {
        $GLOBALS['calls'][] = ['set_fee', $id, $fee];
        $GLOBALS['claims'][$id]->shipping_order_id = 777;
        return $GLOBALS['claims'][$id];
    }
}
class GWARR_Email {
    public static function send_claim_approved($c) { $GLOBALS['calls'][] = ['mail_approved', $c->id]; return true; }
    public static function send_claim_rejected($c) { $GLOBALS['calls'][] = ['mail_rejected', $c->id]; return true; }
}
class GWARR_Marketplaces { public static function label($s) { return ucfirst($s); } }
class GWARR_Auto_Approve { public static function lookup_cache($m, $o) { return null; } }
$GLOBALS['lock_answer'] = '1';
$GLOBALS['locks'] = [];
class WPDB_Stub {
    public $users = 'wp_users';
    public $prefix = 'wp_';
    public function prepare($sql, ...$a) { return [$sql, $a]; }
    public function get_var($q) {
        [$sql, $a] = $q;
        if (strpos($sql, 'GET_LOCK') !== false) { $GLOBALS['locks'][] = ['get', $a[0]]; return $GLOBALS['lock_answer']; }
        return null;
    }
    public function query($q) { [$sql, $a] = $q; if (strpos($sql, 'RELEASE_LOCK') !== false) $GLOBALS['locks'][] = ['release', $a[0]]; return 1; }
    public function get_row($q) {
        [$sql, $a] = $q;
        $id = (int) $a[0];
        return strpos($sql, 'warranty_claims') !== false ? ($GLOBALS['claims'][$id] ?? null) : ($GLOBALS['regs'][$id] ?? null);
    }
}
$GLOBALS['wpdb'] = new WPDB_Stub();

require __DIR__ . '/../galado-warranty/includes/class-warranty-lock.php';
require __DIR__ . '/../galado-warranty/includes/class-warranty-cp-api.php';

// ----------------------------------------------------------------- runner
$failed = 0;
function check($label, $cond) {
    global $failed;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$cond) $failed++;
}
function status_of($r) { return $r instanceof WP_Error ? (int) $r->data['status'] : (int) $r->status; }
function req($params = [], $headers = []) { return new WP_REST_Request($params, $headers + ['X-GWARR-Actor' => 'Crystal']); }
function reset_state() { $GLOBALS['regs'] = []; $GLOBALS['claims'] = []; $GLOBALS['calls'] = []; $GLOBALS['locks'] = []; $GLOBALS['lock_answer'] = '1'; }
function last_call($name) { foreach (array_reverse($GLOBALS['calls']) as $c) if ($c[0] === $name) return $c; return null; }

echo "auth\n";
GWARR_CP_API::routes();
check('12 routes, every one behind the key check', count($GLOBALS['routes']) === 12
    && count(array_filter($GLOBALS['routes'], function ($r) { return $r['permission_callback'] === ['GWARR_CP_API', 'authorized']; })) === 12);
check('none of them listed in the public /wp-json index', count(array_filter($GLOBALS['routes'], function ($r) { return ($r['show_in_index'] ?? true) === false; })) === 12);
check('no fingerprint stored: refused even with a key', GWARR_CP_API::authorized(req([], ['X-GWARR-CP-Key' => 'k'])) === false);
update_option('gwarr_cp_key_sha256', hash('sha256', 'right-key'));
check('wrong key refused', GWARR_CP_API::authorized(req([], ['X-GWARR-CP-Key' => 'wrong'])) === false);
check('no key refused', GWARR_CP_API::authorized(req()) === false);
check('the right key is let in', GWARR_CP_API::authorized(req([], ['X-GWARR-CP-Key' => 'right-key'])) === true);
update_option('gwarr_cp_key_sha256', hash('sha256', ''));
check('a stored fingerprint of an empty key never lets a keyless call in', GWARR_CP_API::authorized(req()) === false);
update_option('gwarr_cp_key_sha256', strtoupper(hash('sha256', 'right-key')) . "\n");
check('a fingerprint stored in capitals with a newline still matches', GWARR_CP_API::authorized(req([], ['X-GWARR-CP-Key' => 'right-key'])) === true);
update_option('gwarr_cp_key_sha256', hash('sha256', 'right-key'));

echo "registrations\n";
reset_state(); reg(1, 'pending');
$r = GWARR_CP_API::run_approve_registration(req(['id' => 1, 'purchase_date' => '2026-09-30']));
check('a pending registration is approved with the date given', status_of($r) === 200 && last_call('approve')[2] === '2026-09-30');
check('a blank note becomes an internal "Approved in CP by" line', last_call('approve')[3] === 'Approved in CP by Crystal');
check('the answer carries the new coupon', $r->data['data']['couponCode'] === 'W-TEST01');
check('and tells caches not to keep it (customer details)', ($r->headers['Cache-Control'] ?? '') === 'no-store, private');
check('approving it again is refused (409)', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 1, 'purchase_date' => '2026-09-30']))) === 409);
reset_state(); reg(2, 'rejected', ['coupon_code' => 'W-OLD']);
check('a rejected one is not re-approved here (409)', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 2, 'purchase_date' => '2026-09-30']))) === 409);
reset_state(); reg(3, 'pending', ['coupon_code' => 'W-OLD']);
check('a pending one that already has a coupon is refused, no second coupon (409)', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 3, 'purchase_date' => '2026-09-30']))) === 409 && !last_call('approve'));
reset_state(); reg(6, 'approved', ['source' => 'website']); reg(7, 'rejected');
check('a website registration (approved on capture, no coupon) is not approved again (409)', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 6, 'purchase_date' => '2026-09-30']))) === 409);
check('a rejected one without a coupon is not approved here either (409)', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 7, 'purchase_date' => '2026-09-30']))) === 409 && !last_call('approve'));
reset_state(); reg(4, 'pending');
check('a malformed date is refused (400)', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 4, 'purchase_date' => '30/09/2026']))) === 400);
check('an impossible date is refused (400)', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 4, 'purchase_date' => '2026-02-30']))) === 400);
check('a future date is refused (400)', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 4, 'purchase_date' => '2026-10-10']))) === 400);
check('nothing was approved by the refusals', !last_call('approve'));
check('a missing registration is a 404', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 99, 'purchase_date' => '2026-09-30']))) === 404);
check('rejecting needs the reason the customer reads (400)', status_of(GWARR_CP_API::run_reject_registration(req(['id' => 4, 'reason' => '  ']))) === 400);
$r = GWARR_CP_API::run_reject_registration(req(['id' => 4, 'reason' => 'Order not found on Shopee']));
check('a pending one is rejected with that reason', status_of($r) === 200 && last_call('reject')[2] === 'Order not found on Shopee');
check('rejecting it again is refused (409)', status_of(GWARR_CP_API::run_reject_registration(req(['id' => 4, 'reason' => 'x']))) === 409);
reset_state(); reg(8, 'pending', ['coupon_code' => 'W-OLD']);
check('a pending one that already has a coupon is not rejected here either (409)', status_of(GWARR_CP_API::run_reject_registration(req(['id' => 8, 'reason' => 'x']))) === 409 && !last_call('reject'));
reset_state(); reg(5, 'approved');
GWARR_CP_API::run_edit_registration(req(['id' => 5, 'order_number' => ' B2 ', 'notes' => 'fixed']));
check('edit passes only the fields given', last_call('update')[2] === ['order_number' => 'B2', 'notes' => 'fixed']);
check('edit refuses a bad date (400)', status_of(GWARR_CP_API::run_edit_registration(req(['id' => 5, 'purchase_date' => 'soon']))) === 400);
GWARR_CP_API::run_registrations(req(['status' => 'pending']));
check('the pending queue lists oldest first', last_call('list')[1]['order'] === 'ASC');
check('an unknown status filter is refused (400)', status_of(GWARR_CP_API::run_registrations(req(['status' => 'deleted']))) === 400);

echo "claims\n";
reset_state(); claim(1, 'submitted');
$r = GWARR_CP_API::run_approve_claim(req(['id' => 1, 'note' => 'Replacement on its way', 'shipping_fee' => '']));
check('a submitted claim is approved without a fee', status_of($r) === 200 && last_call('claim_approve')[3] === 0.0);
check('its approval email goes out and is recorded', last_call('mail_approved') && last_call('email_status')[2] === true && $r->data['emailSent'] === true);
check('approving it again is refused (409)', status_of(GWARR_CP_API::run_approve_claim(req(['id' => 1]))) === 409);
check('declining an approved claim is refused (409)', status_of(GWARR_CP_API::run_decline_claim(req(['id' => 1, 'reason' => 'x']))) === 409);
reset_state(); claim(2, 'submitted');
check('a fee over RM 500 is refused (400)', status_of(GWARR_CP_API::run_approve_claim(req(['id' => 2, 'shipping_fee' => '900']))) === 400);
check('a negative fee is refused (400)', status_of(GWARR_CP_API::run_approve_claim(req(['id' => 2, 'shipping_fee' => '-5']))) === 400);
foreach (['RM 8', 'abc', '0.004'] as $bad) {
    check("a fee written as '{$bad}' is refused, never read as free shipping (400)", status_of(GWARR_CP_API::run_approve_claim(req(['id' => 2, 'shipping_fee' => $bad]))) === 400);
}
check('nothing was approved by those', !last_call('claim_approve'));
GWARR_Claims::$order_on_approve = null;
$r = GWARR_CP_API::run_approve_claim(req(['id' => 2, 'shipping_fee' => '8.5']));
check('a fee whose order failed still approves, with a warning', status_of($r) === 200 && strpos($r->data['warning'], 'gateway down') !== false);
GWARR_Claims::$order_on_approve = 501;
reset_state(); claim(3, 'submitted');
check('declining needs the reason the customer reads (400)', status_of(GWARR_CP_API::run_decline_claim(req(['id' => 3, 'reason' => '']))) === 400);
$r = GWARR_CP_API::run_decline_claim(req(['id' => 3, 'reason' => 'Damage from a drop is not covered']));
check('a submitted claim is declined and the customer emailed', status_of($r) === 200 && last_call('mail_rejected') && last_call('claim_reject')[2] === 'Damage from a drop is not covered');
reset_state(); claim(4, 'submitted');
check('resend needs an approved claim (409)', status_of(GWARR_CP_API::run_resend_claim(req(['id' => 4]))) === 409);
check('shipping needs an approved claim (409)', status_of(GWARR_CP_API::run_charge_shipping(req(['id' => 4, 'shipping_fee' => '8']))) === 409);
reset_state(); claim(5, 'approved', ['shipping_order_id' => 640]);
check('no second shipping order (409)', status_of(GWARR_CP_API::run_charge_shipping(req(['id' => 5, 'shipping_fee' => '8']))) === 409 && !last_call('set_fee'));
reset_state(); claim(6, 'approved');
check('shipping on an approved claim needs a fee (400)', status_of(GWARR_CP_API::run_charge_shipping(req(['id' => 6, 'shipping_fee' => '0']))) === 400);
$r = GWARR_CP_API::run_charge_shipping(req(['id' => 6, 'shipping_fee' => '8']));
check('shipping on an approved claim creates the order and emails the pay link', status_of($r) === 200 && last_call('set_fee')[2] === 8.0 && last_call('mail_approved'));
$r = GWARR_CP_API::run_claim(req(['id' => 6]));
check('a claim reads back with its photos', $r->data['data']['media'][0]['thumb'] === 'https://example.test/u/3-300.jpg');

echo "locks\n";
reset_state(); reg(20, 'pending');
$GLOBALS['lock_answer'] = '0';
$r = GWARR_CP_API::run_approve_registration(req(['id' => 20, 'purchase_date' => '2026-09-30']));
check('while another request holds the registration, approving answers 409 busy', $r instanceof WP_Error && $r->code === 'gwarr_busy' && status_of($r) === 409);
check('and nothing was approved', !last_call('approve'));
reset_state(); claim(21, 'approved');
$GLOBALS['lock_answer'] = '0';
check('a busy claim is not charged shipping', status_of(GWARR_CP_API::run_charge_shipping(req(['id' => 21, 'shipping_fee' => '8']))) === 409 && !last_call('set_fee'));
reset_state(); reg(22, 'pending');
GWARR_CP_API::run_approve_registration(req(['id' => 22, 'purchase_date' => '2026-09-30']));
check('the lock is per registration and released after the approval', $GLOBALS['locks'] === [['get', 'gwarr_wp_reg_22'], ['release', 'gwarr_wp_reg_22']]);
reset_state(); reg(23, 'approved');
GWARR_CP_API::run_approve_registration(req(['id' => 23, 'purchase_date' => '2026-09-30']));
check('and released when a check refuses too', end($GLOBALS['locks']) === ['release', 'gwarr_wp_reg_23']);
reset_state(); reg(24, 'pending');
$GLOBALS['lock_answer'] = null;
check('a database that cannot lock at all still approves, as before', status_of(GWARR_CP_API::run_approve_registration(req(['id' => 24, 'purchase_date' => '2026-09-30']))) === 200);

echo "errors\n";
reset_state(); claim(9, 'submitted');
GWARR_Claims::$boom = true;
$r = GWARR_CP_API::run_approve_claim(req(['id' => 9]));
GWARR_Claims::$boom = false;
check('a crash inside an action comes back as a plain 500', $r instanceof WP_Error && status_of($r) === 500);
check('an unknown action name is a 404, not a crash', status_of(GWARR_CP_API::run_no_such_thing(req())) === 404);

echo $failed ? "\n{$failed} FAILED\n" : "\nall passed\n";
exit($failed ? 1 : 0);
