<?php
/**
 * Native-app surface: the iOS app renders the SAME combos + shelves the web
 * PDP does, from the same server-computed payloads, and prices staged
 * purchases through the same validators. Two entry points:
 *
 *   GET  galado-bundles/v1/app-page?product_id=N   -> render payload
 *   POST galado-bundles/v1/app-quote               -> priced staged purchase
 *
 * Both are PUBLIC and stateless (no Woo session): app-page is the data
 * already rendered into public PDPs; app-quote validates and prices but
 * carts nothing. The app NEVER computes or sends prices - at order time the
 * bridge re-runs quote() server-side and prices the order lines from it.
 *
 * Dark parity: everything gates on the storefront master + module toggles,
 * so the app goes dark with the web (no staff-preview surface here).
 */
defined('ABSPATH') || exit;

class GALADO_Bundles_App {

    // ---- page payload -------------------------------------------------------

    public static function rest_page(WP_REST_Request $req) {
        $pid = absint($req->get_param('product_id'));
        $product = $pid ? wc_get_product($pid) : null;
        if (!$product || 'publish' !== $product->get_status()) {
            return new WP_Error('not_found', 'unknown product', ['status' => 404]);
        }
        return rest_ensure_response(self::page($product));
    }

    public static function page($product) {
        $on = galado_bundles_storefront_enabled();
        $out = [
            'enabled'   => (bool) $on,
            'is_case'   => false,
            'is_anchor' => false,
            'models'    => (object) [],
            'combos'    => [],
            'shelves'   => [],
            'tiers'     => (object) [],
            'wcpa_hide' => [],
        ];
        if (!$on) return $out;

        $out['is_case']   = GALADO_Bundles_Combos::is_case_pdp($product);
        $out['is_anchor'] = GALADO_Bundles_Combos::is_pwp_anchor($product);
        // The app hides the legacy WCPA accessory/protection groups exactly
        // like the web does (WCPA keeps name personalisation only).
        $out['wcpa_hide'] = array_values(array_unique(array_merge(
            galado_bundles_wcpa_hide_keys(),
            galado_bundles_wcpa_hide_keys_addons()
        )));

        if (galado_bundles_combos_enabled() && $out['is_case']) {
            $data = GALADO_Bundles_Combos::page_data($product);
            if ($data) {
                $out['models'] = $data['models'];
                $out['combos'] = $data['cards'];
            }
        }
        if (galado_bundles_addons_enabled()) {
            $groups = GALADO_Bundles_Addons::page_groups($product);
            if ($groups) $out['shelves'] = $groups;
        }
        $out['tiers'] = (object) GALADO_Bundles_Addons::shelf_tiers();
        return $out;
    }

    // ---- quote --------------------------------------------------------------

    public static function rest_quote(WP_REST_Request $req) {
        $body = $req->get_json_params();
        $result = self::quote(is_array($body) ? $body : []);
        return rest_ensure_response($result);
    }

    /**
     * Validate + price a staged purchase: one anchor line plus combo/addon
     * picks. STRICT: any invalid pick fails the whole quote (the app quotes
     * interactively, so a clean error beats a silently-dropped pick).
     *
     * body = [
     *   'anchor' => ['product_id' => N, 'variation_id' => N|0, 'qty' => N],
     *   'picks'  => [
     *     ['kind' => 'combo', 'slug' => s, 'model' => model_slug, 'axes' => [slot => [axis => value]]],
     *     ['kind' => 'addon', 'product_id' => N, 'variation_id' => N|0, 'qty' => N],
     *   ],
     * ]
     */
    public static function quote(array $body) {
        if (!galado_bundles_storefront_enabled()) {
            return ['ok' => false, 'message' => __('Bundles are not available right now.', 'galado-bundles')];
        }

        $anchor = isset($body['anchor']) && is_array($body['anchor']) ? $body['anchor'] : [];
        $apid = absint($anchor['product_id'] ?? 0);
        $avid = absint($anchor['variation_id'] ?? 0);
        $aqty = max(1, absint($anchor['qty'] ?? 1));

        $parent = $apid ? wc_get_product($apid) : null;
        if (!$parent || 'publish' !== $parent->get_status()) {
            return ['ok' => false, 'message' => __('Please pick your product first.', 'galado-bundles')];
        }
        $anchor_obj = $parent;
        if ($parent->is_type('variable')) {
            $v = $avid ? wc_get_product($avid) : null;
            if (!$v || (int) $v->get_parent_id() !== $apid || !$v->is_purchasable() || !$v->is_in_stock()) {
                return ['ok' => false, 'message' => __('Please select your model and colour first.', 'galado-bundles')];
            }
            $anchor_obj = $v;
        } elseif (!$parent->is_purchasable() || !$parent->is_in_stock()) {
            return ['ok' => false, 'message' => __('That product is not available.', 'galado-bundles')];
        }

        $is_case   = GALADO_Bundles_Combos::is_case_pdp($parent);
        $is_anchor = GALADO_Bundles_Combos::is_pwp_anchor($parent);

        $lines = [];
        $lines[] = [
            'kind'         => 'anchor',
            'product_id'   => $apid,
            'variation_id' => $parent->is_type('variable') ? (int) $anchor_obj->get_id() : 0,
            'qty'          => $aqty,
            'name'         => $parent->get_name(),
            'unit'         => round((float) wc_get_price_to_display($anchor_obj), 2),
            'regular'      => round((float) wc_get_price_to_display($anchor_obj), 2),
        ];

        $picks = isset($body['picks']) && is_array($body['picks']) ? $body['picks'] : [];
        $combos_used = 0;
        $claimed = [];          // circle key => true (once-per-circle, in-quote)
        $shelf_lines = [];      // shelf slug => [line indexes] for tier pass

        foreach ($picks as $pick) {
            if (!is_array($pick)) continue;
            $kind = sanitize_key($pick['kind'] ?? '');

            if ('combo' === $kind) {
                // Protection stays strictly case-anchored, one set per case.
                if (!$is_case) {
                    return ['ok' => false, 'message' => __('Protection sets need a phone case.', 'galado-bundles')];
                }
                if (++$combos_used > $aqty) {
                    return ['ok' => false, 'message' => __('Each protection set needs its own case.', 'galado-bundles')];
                }
                $slug  = sanitize_key($pick['slug'] ?? '');
                $model = sanitize_title((string) ($pick['model'] ?? ''));
                $extra = GALADO_Bundles_Combos::clean_extra($pick['axes'] ?? []);
                $resolved = GALADO_Bundles_Combos::resolve_for_model($slug, $model, $extra);
                if (empty($resolved['ok'])) {
                    return ['ok' => false, 'message' => (string) ($resolved['message'] ?? __('That combo is not available.', 'galado-bundles'))];
                }
                $combo_lines = self::price_combo($resolved['desc'], $resolved['selections']);
                if (null === $combo_lines) {
                    return ['ok' => false, 'message' => __('That combo is not available.', 'galado-bundles')];
                }
                foreach ($combo_lines as $cl) {
                    $cl['combo_slug'] = $slug;
                    $cl['model']      = $model;
                    $lines[] = $cl;
                }
                continue;
            }

            if ('addon' === $kind) {
                $pid = absint($pick['product_id'] ?? 0);
                $vid = absint($pick['variation_id'] ?? 0);
                $qty = max(1, absint($pick['qty'] ?? 1));
                $info = GALADO_Bundles_Addons::lookup_addon($pid);
                if (empty($info['allowed'])) {
                    return ['ok' => false, 'message' => __('That add-on is not available.', 'galado-bundles')];
                }
                $p = wc_get_product($pid);
                if (!$p || !$p->is_purchasable() || !$p->is_in_stock()) {
                    return ['ok' => false, 'message' => __('That add-on is not available.', 'galado-bundles')];
                }
                $obj = $p;
                if ($p->is_type('variable')) {
                    $vobj = $vid ? wc_get_product($vid) : null;
                    if (!$vobj || (int) $vobj->get_parent_id() !== $pid || !$vobj->is_purchasable() || !$vobj->is_in_stock()) {
                        return ['ok' => false, 'message' => __('Please choose an option for that add-on.', 'galado-bundles')];
                    }
                    $obj = $vobj;
                }
                $regular = round((float) wc_get_price_to_display($obj), 2);
                $unit    = $regular;
                $circle  = (string) $info['circle'];
                $pwp     = false;
                // With-anchor price: ONE per circle per order, anchor must
                // qualify (r15/r16 rules), and a discounted pick is qty 1.
                if ($is_anchor && $info['addon_price'] > 0 && empty($claimed[$circle])) {
                    if ($qty > 1) {
                        return ['ok' => false, 'message' => __('The with-purchase price applies to one per order.', 'galado-bundles')];
                    }
                    $claimed[$circle] = true;
                    $unit = min($unit, round((float) $info['addon_price'], 2));
                    $pwp  = ($unit < $regular);
                }
                $idx = count($lines);
                $lines[] = [
                    'kind'         => 'addon',
                    'product_id'   => $pid,
                    'variation_id' => $p->is_type('variable') ? (int) $obj->get_id() : 0,
                    'qty'          => $qty,
                    'name'         => $p->get_name(),
                    'unit'         => $unit,
                    'regular'      => $regular,
                    'circle'       => $circle,
                    'shelf'        => (string) $info['shelf'],
                    'pwp'          => $pwp,
                ];
                if (!$pwp) $shelf_lines[$info['shelf']][] = $idx;
                continue;
            }
        }

        // Per-shelf quantity tiers (clip-ons 3/5): percentage off the shelf's
        // untagged lines only - PWP-tagged lines never double-dip.
        $tiers_map = GALADO_Bundles_Addons::shelf_tiers();
        foreach ($shelf_lines as $shelf => $idxs) {
            if (empty($tiers_map[$shelf])) continue;
            $count = 0;
            foreach ($idxs as $i) $count += (int) $lines[$i]['qty'];
            $pct = 0;
            foreach ($tiers_map[$shelf] as $tier) {
                if ($count >= (int) $tier[0]) $pct = (float) $tier[1];
            }
            if ($pct <= 0) continue;
            foreach ($idxs as $i) {
                $lines[$i]['unit']     = max(0.01, round($lines[$i]['regular'] * (1 - $pct / 100), 2));
                $lines[$i]['tier_pct'] = $pct;
            }
        }

        $regular_total = 0.0;
        $total = 0.0;
        foreach ($lines as $l) {
            $regular_total += $l['regular'] * $l['qty'];
            $total         += $l['unit'] * $l['qty'];
        }
        return [
            'ok'      => true,
            'lines'   => $lines,
            'totals'  => [
                'regular' => round($regular_total, 2),
                'total'   => round($total, 2),
                'saving'  => round(max(0, $regular_total - $total), 2),
            ],
        ];
    }

    /** Price a resolved combo's component lines: natural display prices, and
     * when combo_price applies, the SAME proportional split the cart engine
     * uses (scale, 0.01 floors, cent remainder on the first line). */
    private static function price_combo($desc, $selections) {
        $units = []; $sum = 0.0;
        foreach ($desc['items'] as $it) {
            $qty = max(1, (int) ($it['qty'] ?? 1));
            if ('variable' === $it['line_type']) {
                $vid = 'model_match' === $it['variation_mode']
                    ? (int) ($selections[$it['slot']] ?? 0)
                    : (int) ($it['default_variation_id'] ?? 0);
                $obj = $vid ? wc_get_product($vid) : null;
                if (!$obj || !$obj->is_purchasable() || !$obj->is_in_stock()) return null;
                $units[] = [
                    'product_id'   => (int) $it['product_id'],
                    'variation_id' => $vid,
                    'qty'          => $qty,
                    'name'         => $obj->get_name(),
                    'unit'         => round((float) wc_get_price_to_display($obj), 2),
                ];
            } else {
                $obj = wc_get_product((int) $it['product_id']);
                if (!$obj || !$obj->is_purchasable() || !$obj->is_in_stock()) return null;
                $units[] = [
                    'product_id'   => (int) $it['product_id'],
                    'variation_id' => 0,
                    'qty'          => $qty,
                    'name'         => $obj->get_name(),
                    'unit'         => round((float) wc_get_price_to_display($obj), 2),
                ];
            }
            $sum += end($units)['unit'] * $qty;
        }

        $price = (float) ($desc['combo']['combo_price'] ?? ($desc['combo_price'] ?? 0));
        $out = [];
        if ($price > 0 && $sum > $price) {
            // Proportional split, exactly like Discount::reprice_combos.
            $targets = []; $acc = 0.0;
            foreach ($units as $i => $u) {
                $t = max(0.01 * $u['qty'], round($u['unit'] * $u['qty'] * $price / $sum, 2));
                $targets[$i] = $t;
                $acc += $t;
            }
            $targets[0] = max(0.01, round($targets[0] + ($price - $acc), 2));
            foreach ($units as $i => $u) {
                $out[] = [
                    'kind'         => 'combo',
                    'product_id'   => $u['product_id'],
                    'variation_id' => $u['variation_id'],
                    'qty'          => $u['qty'],
                    'name'         => $u['name'],
                    'unit'         => round($targets[$i] / $u['qty'], 2),
                    'regular'      => $u['unit'],
                    'pwp'          => true,
                ];
            }
        } else {
            foreach ($units as $u) {
                $out[] = [
                    'kind'         => 'combo',
                    'product_id'   => $u['product_id'],
                    'variation_id' => $u['variation_id'],
                    'qty'          => $u['qty'],
                    'name'         => $u['name'],
                    'unit'         => $u['unit'],
                    'regular'      => $u['unit'],
                    'pwp'          => false,
                ];
            }
        }
        return $out;
    }
}
