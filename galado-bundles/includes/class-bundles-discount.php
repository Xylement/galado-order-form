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
    /** Same, but for combo sets, whose saving is baked into the line prices
     * (owner 2026-08-04 r5) instead of a fee. Kept separate because apply_fees
     * resets its own map on every pass. */
    private static $applied_combos = [];
    public static function applied() { return array_merge(self::$applied_combos, self::$applied); }

    public static function init() {
        // Combo sets: the discount is baked into the component line prices, so
        // the cart shows ONE set row at the combo price with no fee line.
        // Priority 25 runs after the addon-price pass (20).
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'reprice_combos'], 25);
        // Priority 99 runs after Club Bridge fees (default 10), so the clamp can read them.
        add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'apply_fees'], 99);
        // Tier coupons do not stack on satisfied-bundle lines (rule B).
        add_filter('woocommerce_coupon_is_valid_for_product', [__CLASS__, 'block_tier_on_bundle_lines'], 20, 3);
        // Totals presentation (owner r7): subtotal reads as the ORIGINAL
        // prices, an explicit "PWP Discount" row shows what came off, and the
        // total stays the real charged amount. Display only - the accounting
        // underneath (repriced lines) is untouched.
        add_filter('woocommerce_cart_subtotal', [__CLASS__, 'display_subtotal'], 20, 3);
        add_action('woocommerce_cart_totals_before_shipping', [__CLASS__, 'display_pwp_row']);
        add_action('woocommerce_review_order_before_shipping', [__CLASS__, 'display_pwp_row']);
    }

    /** RM actually taken off by PWP pricing in this cart right now (applied,
     * not promised - zero whenever the case gate has the discounts off). */
    public static function pwp_display_saving() {
        if (!galado_bundles_can_transact()) return 0.0;
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) return 0.0;
        $saving = 0.0;
        // Protection sets: strictly case-anchored.
        if (GALADO_Bundles_Cart::cart_has_case($cart)) {
            foreach (GALADO_Bundles_Cart::combo_instances($cart) as $e) {
                if (empty($e['repriced'])) continue;
                $own = 0.0; $paid = 0.0;
                foreach ($e['keys'] as $k) {
                    $ci = $cart->get_cart_item($k);
                    if (!$ci) continue;
                    $p = wc_get_product(!empty($ci['variation_id']) ? $ci['variation_id'] : $ci['product_id']);
                    $own  += ($p ? (float) wc_get_price_to_display($p) : 0.0) * max(1, (int) $ci['quantity']);
                    $paid += isset($ci['line_subtotal']) ? (float) $ci['line_subtotal'] : 0.0;
                }
                if ($own > $paid) $saving += $own - $paid;
            }
        }
        // Accessory overrides: any anchor product qualifies (owner r15).
        if (GALADO_Bundles_Cart::cart_has_anchor($cart)) {
            foreach ($cart->get_cart() as $ci) {
                if (empty($ci['galado_addon_price'])) continue;
                $p = wc_get_product(!empty($ci['variation_id']) ? $ci['variation_id'] : $ci['product_id']);
                $own  = $p ? (float) wc_get_price_to_display($p) : 0.0;
                $paid = (float) $ci['galado_addon_price'];
                if ($own > $paid) $saving += ($own - $paid) * max(1, (int) $ci['quantity']);
            }
        }
        // Quantity tier promos (clip-ons): percentage off the member lines.
        foreach ($cart->get_cart() as $ci) {
            $pct = GALADO_Bundles_Addons::line_tier_pct($ci);
            if ($pct <= 0) continue;
            $p = wc_get_product(!empty($ci['variation_id']) ? $ci['variation_id'] : $ci['product_id']);
            $own = $p ? (float) wc_get_price_to_display($p) : 0.0;
            $saving += round($own * $pct / 100, 2) * max(1, (int) $ci['quantity']);
        }
        return round($saving, 2);
    }

    public static function display_subtotal($subtotal_html, $compound, $cart) {
        if ($compound) return $subtotal_html;
        $saving = self::pwp_display_saving();
        if ($saving <= 0) return $subtotal_html;
        return wc_price((float) $cart->get_subtotal() + $saving);
    }

    public static function display_pwp_row() {
        $saving = self::pwp_display_saving();
        if ($saving <= 0) return;
        echo '<tr class="gld-pwp-discount"><th>' . esc_html__('PWP Discount', 'galado-bundles') . '</th>'
           . '<td data-title="' . esc_attr__('PWP Discount', 'galado-bundles') . '">'
           . '<span style="color:#E4002B;font-weight:700">-' . wp_kses_post(wc_price($saving)) . '</span>'
           . '</td></tr>';
    }

    /**
     * Combo sets sell as one product (owner 2026-08-04 r5): while an instance
     * is COMPLETE, its component line prices are set so the instance sums to
     * exactly the combo price - proportional per line, cent remainder on the
     * lead. Prices are recomputed from fresh product prices on every totals
     * pass, so the pass is idempotent and member/sale prices flow in
     * naturally: if the shopper's own prices already total at or below the
     * combo price, nothing is touched (whichever is cheaper wins, the old
     * member-offset rule by construction). An incomplete instance is left
     * entirely alone - natural full prices, exactly like today's fee lapse,
     * but the shopper can only get there by session decay, not by a remove
     * click, because the set row removes as one.
     */
    public static function reprice_combos($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (null === $cart || !($cart instanceof WC_Cart)) return;
        if (!galado_bundles_can_transact()) return;

        self::$applied_combos = [];
        // Combo pricing is with-case pricing, ONE SET PER CASE (owner r6+r10):
        // combo_instances() stamps 'repriced' on complete instances up to the
        // case count; everything else keeps its natural price.
        if (!GALADO_Bundles_Cart::cart_has_case($cart)) return;
        foreach (GALADO_Bundles_Cart::combo_instances($cart) as $e) {
            if (empty($e['repriced'])) continue;

            $own = []; $sum = 0.0; $broken = false;
            foreach ($e['keys'] as $k) {
                $ci = $cart->get_cart_item($k);
                $p = $ci ? wc_get_product(!empty($ci['variation_id']) ? $ci['variation_id'] : $ci['product_id']) : null;
                if (!$p) { $broken = true; break; }
                $qty = max(1, (int) $ci['quantity']);
                $own[$k] = ['unit' => (float) $p->get_price(), 'qty' => $qty];
                $sum += $own[$k]['unit'] * $qty;
            }
            $price = (float) $e['combo_price'];
            if ($broken || $sum <= 0 || $sum <= $price) continue;

            $targets = []; $acc = 0.0;
            foreach ($own as $k => $o) {
                $t = max(0.01 * $o['qty'], round($o['unit'] * $o['qty'] * $price / $sum, 2));
                $targets[$k] = $t;
                $acc += $t;
            }
            $lead = $e['lead'];
            $targets[$lead] = max(0.01, round($targets[$lead] + ($price - $acc), 2));

            foreach ($targets as $k => $t) {
                $ci = $cart->get_cart_item($k);
                if ($ci && !empty($ci['data'])) {
                    $ci['data']->set_price($t / $own[$k]['qty']);
                }
            }

            $slug = $e['slug'];
            $prev = self::$applied_combos[$slug] ?? ['name' => $e['title'], 'complete_instances' => 0, 'saving' => 0.0];
            self::$applied_combos[$slug] = [
                'name'               => $e['title'],
                'complete_instances' => $prev['complete_instances'] + 1,
                'saving'             => round($prev['saving'] + ($sum - $price), 2),
            ];
        }
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
                // Combos carry a fee label ("Protect Set") so the cart row reads
                // "Bundle saving (Protect Set)"; legacy bundles keep their title.
                'name'               => '' !== ($desc['fee_label'] ?? '') ? $desc['fee_label'] : $desc['title'],
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
        if (!galado_bundles_can_transact()) return; // dark for this visitor

        $map = self::satisfied($cart);
        // Combo sets are repriced at line level (reprice_combos); a fee on top
        // would double-discount them. Drop them BEFORE the clamp math so the
        // scale for legacy fee sets is not diluted by savings we never pay out.
        foreach (array_keys($map) as $slug) {
            $d = GALADO_Bundles_Data::get($slug);
            if ($d && !empty($d['combo']) && $d['combo_price'] > 0) unset($map[$slug]);
        }
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
            // Explicit per-slug fee id: WC derives the id from the name and rejects
            // duplicate ids, so two bundles sharing a title would otherwise silently
            // lose one saving. The customer-facing name stays the title (#95 continuity).
            $cart->fees_api()->add_fee([
                'id'      => 'galado-bundle-' . $slug,
                'name'    => sprintf(__('Bundle saving (%s)', 'galado-bundles'), $m['name']),
                'amount'  => -1 * $amount,
                'taxable' => false,
            ]);
            self::$applied[$slug] = ['name' => $m['name'], 'complete_instances' => $m['complete_instances'], 'saving' => $amount];
        }
    }

    /** Rule B: a tier coupon gives nothing on a satisfied-bundle line, but stays
     * valid on the rest of the cart. Only blocks while the bundle is complete. */
    public static function block_tier_on_bundle_lines($valid, $product, $coupon) {
        if (!$valid) return $valid;
        if (!galado_bundles_can_transact()) return $valid;
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
