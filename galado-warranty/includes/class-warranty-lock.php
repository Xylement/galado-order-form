<?php
/**
 * One request at a time per registration or claim (v1.12.0).
 *
 * Approving takes seconds (coupon, Club coverage lookup) before the row says
 * "approved", so a double click, two staff, a retry from CP or the hourly
 * sheet sweep could each pass the status check and make a second coupon,
 * order or email. A MySQL named lock closes that window: the database frees
 * it when the request ends, even if PHP dies. Re-entrant within a request,
 * so a CP handler and the approval code it calls share one lock.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Lock {
    private static $held = [];

    /**
     * Runs $fn holding the named lock and returns what it returns, or a
     * WP_Error 'gwarr_busy' (status 409) when another request holds it.
     */
    public static function run($name, $fn) {
        global $wpdb;
        $name = substr('gwarr_' . $wpdb->prefix . $name, 0, 64);
        if (!empty(self::$held[$name])) {
            return $fn();
        }
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ($got !== null && (string) $got !== '1') {
            return new WP_Error('gwarr_busy', 'Someone else is working on this right now. Open it again in a moment.', ['status' => 409]);
        }
        // NULL: the database could not take a lock at all; carry on unlocked, as before v1.12.0.
        $locked = (string) $got === '1';
        if ($locked) {
            self::$held[$name] = true;
        }
        try {
            return $fn();
        } finally {
            if ($locked) {
                unset(self::$held[$name]);
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }
}
