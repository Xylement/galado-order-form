<?php
/**
 * The discount engine (spec 6): a plugin-owned negative cart fee, applied only
 * when a complete set is present, with the full stacking, member-offset and
 * never-negative rules. Only loaded when the storefront flag is on.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Discount {

    const FLOOR = 1.00; // never let a gateway see below this (owner decision 5)

    /** The savings actually applied on the last fee pass, AFTER the clamp, keyed
     * by slug. Analytics reads this so the order records what was really given,
     * not the pre-clamp map. */
    private static $applied = [];
    public static function applied() { return self::$applied; }

    public static function init() {
        // Priority 99 runs after Club Bridge fees (default 10), so the clamp can read them.
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'apply_fees'], 99);
        // Tier coupons do not stack on satisfied-bundle lines (rule B).
        add_filter('woocommerce_coupon_is_valid_for_product', [__CLASS__, 'block_tier_on_bundle_lines'], 20, 3);
    }

    /** Tier-coupon codes, from one canonical list (centralised in Club Bridge
     * when it ships the filter; a sane default otherwise). */
    private static function tier_codes() {
        return array_map('strtolower', (array) apply_filters('galado_tier_coupon_codes', ['lvlup5', 'diam10d', 'gblk15']));
    }

    /**
     * The satisfied map: per active bundle key present in the cart, how many
     * complete instances, the RM saving after the member offset and the ceiling,
     * and the line keys. Memoised per calculation pass.
     *
     * returns [ key => ['complete_instances'=>int,'saving'=>float,'line_keys'=>string[]] ]
     */
    public static function satisfied(WC_Cart $cart) {
        static $memo_hash = null, $memo = null;
        $hash = md5(wp_json_encode(array_keys($cart->get_cart())) . '|' . get_current_user_id());
        if ($memo_hash === $hash && null !== $memo) return $memo;

        $bundles = [];
        foreach ($cart->get_cart() as $key => $ci) {
            if (empty($ci['galado_bundle'])) continue;
            $slug = (string) $ci['galado_bundle'];
            $uid  = (string) ($ci['galado_bundle_uid'] ?? '');
            $slot = (string) ($ci['galado_bundle_slot'] ?? '');
            $bundles[$slug]['instances'][$uid]['slots'][$slot][] = ['key' => $key, 'qty' => (int) $ci['quantity']];
            $bundles[$slug]['instances'][$uid]['line_keys'][] = $key;
        }

        $out = [];
        foreach ($bundles as $slug => $data) {
            $desc = GALADO_Bundles_Data::get($slug);
            if (!$desc || 'publish' !== ($desc['status'] ?? '') || 'link' === $desc['mode'] || $desc['save'] <= 0) continue;

            // Required slots and their per-instance qty from the bundle definition.
            $need = [];
            foreach ($desc['items'] as $it) $need[$it['slot']] = ($need[$it['slot']] ?? 0) + max(1, (int) $it['qty']);

            $complete = 0; $complete_line_keys = [];
            foreach ($data['instances'] as $inst) {
                $ok = true;
                foreach ($need as $slot => $need_qty) {
                    $have = 0;
                    foreach (($inst['slots'][$slot] ?? []) as $l) $have += $l['qty'];
                    if ($have < $need_qty) { $ok = false; break; }
                }
                if ($ok) { $complete++; $complete_line_keys = array_merge($complete_line_keys, $inst['line_keys']); }
            }
            if ($complete < 1) continue;

            $base = $desc['stack_qty'] ? $desc['save'] * $complete : $desc['save'];

            // Rule A, member "whichever cheaper": reduce the flat saving by the RM
            // a member/hero price already took off this bundle's lines, so the
            // member ends at min(member-priced total, normal - save).
            $member_offset = 0.0;
            foreach ($complete_line_keys as $lk) {
                $ci = $cart->get_cart_item($lk);
                if (!$ci) continue;
                $product = $ci['data'] ?? null;
                $member_offset += (float) apply_filters('galado_line_member_discount', 0.0, $ci, $product);
            }
            $saving = max(0, $base - $member_offset);

            // Per-bundle ceiling: never discount more than the cheapest complete
            // instance actually costs, at the price in effect (incl. member price).
            $ceiling = self::instance_subtotal($cart, $data['instances'], $need, $desc['stack_qty'] ? $complete : 1);
            $saving = min($saving, max(0, $ceiling - 0.01));

            if ($saving <= 0) continue;
            $out[$slug] = [
                'complete_instances' => $complete,
                'saving'             => round($saving, 2),
                'name'               => $desc['title'],
                'line_keys'          => $complete_line_keys,
            ];
        }

        $memo_hash = $hash; $memo = $out;
        return $out;
    }

    /** Line subtotal of the cheapest complete instance (or the sum of the n
     * cheapest, for stack_qty), at current effective prices. */
    private static function instance_subtotal(WC_Cart $cart, $instances, $need, $n) {
        $totals = [];
        foreach ($instances as $inst) {
            $ok = true; $sub = 0.0;
            foreach ($need as $slot => $need_qty) {
                $have = 0;
                foreach (($inst['slots'][$slot] ?? []) as $l) {
                    $ci = $cart->get_cart_item($l['key']);
                    if ($ci) $sub += (float) $ci['data']->get_price() * $l['qty'];
                    $have += $l['qty'];
                }
                if ($have < $need_qty) { $ok = false; break; }
            }
            if ($ok) $totals[] = $sub;
        }
        sort($totals);
        return array_sum(array_slice($totals, 0, max(1, (int) $n)));
    }

    public static function apply_fees(WC_Cart $cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (null === $cart) return;

        $map = self::satisfied($cart);
        if (!$map) return;

        // Cart-level never-negative clamp (runs here at priority 99, after Club
        // fees). headroom = subtotal - discounts - existing negative fees.
        $existing_neg = 0.0;
        foreach ($cart->get_fees() as $fee) {
            if ($fee->total < 0) $existing_neg += $fee->total; // negative
        }
        $headroom = (float) $cart->get_subtotal()
                  - (float) $cart->get_discount_total()
                  + $existing_neg;                 // existing_neg is <= 0
        $allowed = max(0, $headroom - self::FLOOR);

        $total_saving = array_sum(array_map(function ($m) { return $m['saving']; }, $map));
        $scale = ($total_saving > $allowed && $total_saving > 0) ? ($allowed / $total_saving) : 1.0;

        self::$applied = [];
        foreach ($map as $slug => $m) {
            $amount = round($m['saving'] * $scale, 2);
            if ($amount <= 0) continue;
            $cart->add_fee(sprintf(__('Bundle saving (%s)', 'galado-bundles'), $m['name']), -1 * $amount, false);
            self::$applied[$slug] = ['name' => $m['name'], 'complete_instances' => $m['complete_instances'], 'saving' => $amount];
        }
    }

    /** Rule B: a tier coupon gives nothing on a satisfied-bundle line, but stays
     * valid on the rest of the cart. Only blocks while the bundle is complete. */
    public static function block_tier_on_bundle_lines($valid, $product, $coupon) {
        if (!$valid) return $valid;
        if (!($coupon instanceof WC_Coupon)) return $valid;
        if (!in_array(strtolower($coupon->get_code()), self::tier_codes(), true)) return $valid;
        $cart = WC()->cart;
        if (!$cart) return $valid;

        $map = self::satisfied($cart);
        if (!$map) return $valid;
        $blocked_keys = [];
        foreach ($map as $m) $blocked_keys = array_merge($blocked_keys, $m['line_keys']);

        $pid = $product ? $product->get_id() : 0;
        foreach ($blocked_keys as $lk) {
            $ci = $cart->get_cart_item($lk);
            if (!$ci) continue;
            $line_pid = $ci['variation_id'] ? $ci['variation_id'] : $ci['product_id'];
            if ((int) $line_pid === (int) $pid || (int) $ci['product_id'] === (int) $pid) return false;
        }
        return $valid;
    }
}
