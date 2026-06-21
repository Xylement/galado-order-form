<?php
/**
 * Orchestrates the on-approval and on-rejection side-effects so the
 * admin list table and (later) the auto-approve sheet sync don't have
 * to duplicate the sequence of: coupon → email → Klaviyo.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Approval {

    /**
     * Approve a registration end-to-end:
     *   1. Generate a unique WooCommerce coupon for this customer
     *   2. Persist purchase_date + warranty_ends + coupon code (DB::approve)
     *   3. Send the approval email with coupon details
     *   4. Push profile + list + event to Klaviyo (best-effort)
     *
     * @return object|WP_Error The updated row on success, WP_Error otherwise.
     */
    public static function approve($id, $purchase_date, $admin_note = '') {
        $row = GWARR_DB::find($id);
        if (!$row) {
            return new WP_Error('gwarr_not_found', 'Registration not found.');
        }
        if ($row->status === 'approved') {
            return new WP_Error('gwarr_already_approved', 'This registration is already approved.');
        }

        $coupon_code = GWARR_Coupon::create_for_registration($row);
        if (is_wp_error($coupon_code)) {
            return $coupon_code;
        }

        $updated = GWARR_DB::approve($id, $purchase_date, $coupon_code, $admin_note);
        if (is_wp_error($updated)) {
            return $updated;
        }

        // Email + Klaviyo are slow (SMTP + 3 API calls) but the customer doesn't
        // need to wait on them — the coupon and warranty dates are already saved.
        // Defer them past the response flush so registration feels instant.
        self::dispatch(function () use ($updated) {
            GWARR_Email::send_approved($updated);
            GWARR_Klaviyo::on_approval($updated);
        });

        return $updated;
    }

    public static function reject($id, $reason) {
        $row = GWARR_DB::find($id);
        if (!$row) {
            return new WP_Error('gwarr_not_found', 'Registration not found.');
        }

        $updated = GWARR_DB::reject($id, $reason);
        if (is_wp_error($updated)) {
            return $updated;
        }

        self::dispatch(function () use ($updated) {
            GWARR_Email::send_rejected($updated);
        });
        return $updated;
    }

    /**
     * Run post-approval/rejection side-effects after the response flushes when
     * possible, falling back to inline execution if the deferral helper isn't
     * available (e.g. partial load).
     */
    private static function dispatch($callback) {
        if (class_exists('GWARR_Deferred')) {
            GWARR_Deferred::add($callback);
        } else {
            call_user_func($callback);
        }
    }
}
