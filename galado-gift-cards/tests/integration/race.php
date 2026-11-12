<?php
/**
 * Helper for race.sh. Modes:
 *   setup           a paid order with two card lines whose codes are NOT yet issued; prints its id
 *   locked <id>     issue through the plugin (named lock), slowed inside the critical section
 *   naive <id>      the same check-then-create WITHOUT the lock (negative control)
 *   count <id>      coupons that exist for the order
 * $args comes from `wp eval-file race.php <mode> [id]`.
 */
require __DIR__ . '/bootstrap.php';
$mode = $args[0] ?? '';
$id = (int) ($args[1] ?? 0);
// Widen the race window. Through the plugin: every coupon insert pauses, so a second process
// arriving meanwhile meets the lock. Negative control: the pause sits between the check and the
// create, so two processes started a few seconds apart both see an empty line, every run.
$slow = function () { usleep(4000000); };

if ('setup' === $mode) {
    gct_product();
    remove_action('woocommerce_order_status_processing', [Galado_GC_Issuer::class, 'on_paid'], 20);
    $o = gct_paid_order([['value' => 100], ['value' => 50, 'label' => 'RM50']]);
    echo $o->get_id();
} elseif ('locked' === $mode) {
    add_action('woocommerce_new_coupon', $slow);
    $t = microtime(true);
    $made = Galado_GC_Issuer::issue_for_order($id);
    printf("locked pid=%d created=%d seconds=%.1f\n", getmypid(), count($made), microtime(true) - $t);
} elseif ('naive' === $mode) {
    $order = wc_get_order($id);
    $create = new ReflectionMethod(Galado_GC_Issuer::class, 'create_coupon');
    $create->setAccessible(true);
    $made = 0;
    foreach (Galado_GC_Cart::order_gift_items($order) as $item_id => $item) {
        $existing = Galado_GC_Issuer::coupons_for_item($item_id); // check ...
        usleep(5000000); // the race window, held open: the other process checks the same line now
        if (!isset($existing[1])) {                               // ... then create, no lock
            $create->invoke(null, $order, $item_id, 1, (float) $item->get_meta('_galado_gc_value'), time() + 86400);
            $made++;
        }
    }
    printf("naive pid=%d created=%d\n", getmypid(), $made);
} elseif ('count' === $mode) {
    // Raw rows, not coupons_for_item(): that keys by card number and would hide a duplicate.
    global $wpdb;
    echo (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_galado_gc_order_id' AND meta_value = %s", (string) $id));
}
