<?php
/**
 * GWARR_Approval under GWARR_Lock, without WordPress: the real lock and
 * approval classes, the rest stubbed. Covers every caller of approve/reject
 * (wp-admin, the hourly sheet sweep and CP), not only the CP API.
 * Run: php tests/warranty-approval-lock.test.php  (exits 1 on any failure)
 */

define('ABSPATH', __DIR__);

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($c, $m, $d = []) { $this->code = $c; $this->message = $m; $this->data = $d; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }

$GLOBALS['row'] = null;
$GLOBALS['coupons'] = 0;
$GLOBALS['mails'] = [];
$GLOBALS['lock_answer'] = '1';
$GLOBALS['locks'] = [];

class WPDB_Stub {
    public $prefix = 'wp_';
    public function prepare($sql, ...$a) { return [$sql, $a]; }
    public function get_var($q) { $GLOBALS['locks'][] = ['get', $q[1][0]]; return $GLOBALS['lock_answer']; }
    public function query($q) { $GLOBALS['locks'][] = ['release', $q[1][0]]; return 1; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();

class GWARR_DB {
    public static function find($id) { return $GLOBALS['row']; }
    public static function approve($id, $date, $code, $note) {
        $GLOBALS['row']->status = 'approved';
        $GLOBALS['row']->coupon_code = $code;
        return $GLOBALS['row'];
    }
    public static function reject($id, $reason) {
        $GLOBALS['row']->status = 'rejected';
        return $GLOBALS['row'];
    }
}
class GWARR_Coupon { public static function create_for_registration($row) { $GLOBALS['coupons']++; return 'W-' . $GLOBALS['coupons']; } }
class GWARR_Email {
    public static function send_approved($row) { $GLOBALS['mails'][] = 'approved'; }
    public static function send_rejected($row) { $GLOBALS['mails'][] = 'rejected'; }
}
class GWARR_GSend { public static function maybe_subscribe($row) {} }
class GWARR_Klaviyo { public static function on_approval($row) {} }

require __DIR__ . '/../galado-warranty/includes/class-warranty-lock.php';
require __DIR__ . '/../galado-warranty/includes/class-warranty-approval.php';

$failed = 0;
function check($label, $cond) {
    global $failed;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$cond) $failed++;
}
function fresh($status = 'pending') {
    $GLOBALS['row'] = (object) ['id' => 5, 'status' => $status, 'coupon_code' => null];
    $GLOBALS['coupons'] = 0; $GLOBALS['mails'] = []; $GLOBALS['locks'] = []; $GLOBALS['lock_answer'] = '1';
}

fresh();
$r = GWARR_Approval::approve(5, '2026-09-30', 'note');
check('an approval takes the registration lock, makes one coupon and releases the lock',
    !is_wp_error($r) && $GLOBALS['coupons'] === 1 && $GLOBALS['locks'] === [['get', 'gwarr_wp_reg_5'], ['release', 'gwarr_wp_reg_5']]);
check('the approval email went out once', $GLOBALS['mails'] === ['approved']);

fresh();
$GLOBALS['lock_answer'] = '0';
$r = GWARR_Approval::approve(5, '2026-09-30', 'note');
check('while another request (wp-admin, the sweep or CP) holds it: busy, no coupon, no email',
    is_wp_error($r) && $r->get_error_code() === 'gwarr_busy' && $GLOBALS['coupons'] === 0 && $GLOBALS['mails'] === []);

fresh();
$GLOBALS['lock_answer'] = '0';
$r = GWARR_Approval::reject(5, 'reason');
check('a rejection waits for the same lock: busy, no email', is_wp_error($r) && $GLOBALS['mails'] === []);

fresh('approved');
$r = GWARR_Approval::approve(5, '2026-09-30', 'note');
check('the status is read inside the lock: an approved row is not approved again', is_wp_error($r) && $GLOBALS['coupons'] === 0);

fresh();
$r = GWARR_Lock::run('reg_5', function () { return GWARR_Approval::approve(5, '2026-09-30', 'note'); });
check('re-entrant: CP holding the lock can call approve without locking twice',
    !is_wp_error($r) && count(array_filter($GLOBALS['locks'], function ($l) { return $l[0] === 'get'; })) === 1);

fresh();
$GLOBALS['lock_answer'] = null;
$r = GWARR_Approval::approve(5, '2026-09-30', 'note');
check('a database that cannot lock at all still approves, as before', !is_wp_error($r) && $GLOBALS['coupons'] === 1);

fresh();
try {
    GWARR_Lock::run('reg_5', function () { throw new RuntimeException('boom'); });
} catch (RuntimeException $e) {
}
check('the lock is released even when the work throws', end($GLOBALS['locks']) === ['release', 'gwarr_wp_reg_5']);

echo $failed ? "\n{$failed} FAILED\n" : "\nall passed\n";
exit($failed ? 1 : 0);
