<?php
/**
 * The single read surface (spec 5.1). Both the storefront card and the cart
 * discount engine read bundles and prices through here, so display and cart can
 * never diverge. Prices are always computed LIVE; only `save` is stored.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Data {

    /** Featured + active + buyable descriptors, ordered by menu_order,
     * capped at GALADO_BUNDLES_FEATURED_MAX. PDP protector combos never appear
     * here, whatever their Featured flag: the home band and the PDP module are
     * separate surfaces. */
    public static function get_featured() {
        $ids = get_posts([
            'post_type'      => GALADO_BUNDLES_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => GALADO_BUNDLES_FEATURED_MAX * 3, // headroom; we filter unbuyable then cap
            'orderby'        => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'meta_query'     => [['key' => GALADO_BUNDLES_META . 'featured', 'value' => '1']],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $out = [];
        foreach ($ids as $id) {
            $b = self::get($id);
            if ($b && $b['buyable'] && !$b['combo'] && !$b['addon_group']) $out[] = $b;
            if (count($out) >= GALADO_BUNDLES_FEATURED_MAX) break;
        }
        return $out;
    }

    /** Active PDP accessory add-on groups, in menu order. Items are sold at
     * their own price (no combo pricing, no fee); the group is a curated,
     * ordered shelf. */
    public static function get_addon_groups() {
        $ids = get_posts([
            'post_type'      => GALADO_BUNDLES_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 4,
            'orderby'        => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'meta_query'     => [['key' => GALADO_BUNDLES_META . 'addon_group', 'value' => '1']],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $out = [];
        foreach ($ids as $id) {
            $b = self::get($id);
            if ($b) $out[] = $b;
        }
        return $out;
    }

    /** Active PDP protector combos, in menu order. Buyability is judged per
     * model by the PDP module, so it is not filtered here. */
    public static function get_combos() {
        $ids = get_posts([
            'post_type'      => GALADO_BUNDLES_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'orderby'        => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'meta_query'     => [['key' => GALADO_BUNDLES_META . 'combo', 'value' => '1']],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $out = [];
        foreach ($ids as $id) {
            $b = self::get($id);
            if ($b) $out[] = $b;
        }
        return $out;
    }

    /** Per-request descriptor memo: get() is called repeatedly per render
     * (combos, addons, is_case_pdp, discount), and each build walks children
     * for live pricing. One build per post per request. */
    private static $memo = [];

    /** One normalized descriptor, or null. Callers filter by status. */
    public static function get($id_or_slug) {
        $post = self::resolve($id_or_slug);
        if (!$post) return null;

        $id    = $post->ID;
        if (isset(self::$memo[$id])) return self::$memo[$id];
        $items = self::items($id);
        if (!$items) return null;

        // Pricing first: for combos the saving is DERIVED (live sum minus the
        // combo price), so mode and everything downstream must read the derived
        // figure, not the stored flat one.
        $price = self::pricing($id, []);
        $save  = $price['save'];
        $mode  = (string) get_post_meta($id, GALADO_BUNDLES_META . 'mode', true);
        if ('' === $mode) $mode = self::derive_mode($items, $save);
        $img   = (int) get_post_meta($id, GALADO_BUNDLES_META . 'image', true);

        $out = [
            'id'           => $id,
            'slug'         => $post->post_name,
            'status'       => $post->post_status,
            'title'        => get_the_title($id),
            'featured'     => '1' === get_post_meta($id, GALADO_BUNDLES_META . 'featured', true),
            'combo'          => '1' === get_post_meta($id, GALADO_BUNDLES_META . 'combo', true),
            'combo_fallback' => '1' === get_post_meta($id, GALADO_BUNDLES_META . 'combo_fallback', true),
            'addon_group'    => '1' === get_post_meta($id, GALADO_BUNDLES_META . 'addon_group', true),
            'combo_price'    => max(0.0, (float) get_post_meta($id, GALADO_BUNDLES_META . 'combo_price', true)),
            'fee_label'    => (string) get_post_meta($id, GALADO_BUNDLES_META . 'fee_label', true),
            'mode'         => $mode,
            'has_variable' => ('build' === $mode),
            'save'         => $save,
            'cta'          => self::cta($id, $mode),
            'image'        => $img ? wp_get_attachment_image_url($img, 'medium') : '',
            'blurb'        => (string) get_post_meta($id, GALADO_BUNDLES_META . 'blurb', true),
            'stack_qty'    => '1' === get_post_meta($id, GALADO_BUNDLES_META . 'stack_qty', true),
            'items'        => $items,
            'sum'          => $price['sum'],
            'total'        => $price['total'],
            'buyable'      => self::is_buyable($items),
        ];
        self::$memo[$id] = $out;
        return $out;
    }

    /** The shared pricing method both the card and the cart call. Live prices.
     * $selections maps slot => variation_id for shopper_choice/model_match lines.
     *
     * Two pricing models share this path:
     *   flat save    'save' meta holds a fixed RM off the live sum.
     *   combo price  'combo_price' meta holds the fixed price of the whole set;
     *                the saving is DERIVED as live sum minus combo price, so
     *                the shown percentage is computed from live prices and can
     *                never drift from reality (ADDON-COMBOS-SPEC section 2). */
    public static function pricing($bundle_id, $selections = []) {
        $items = self::items($bundle_id);
        $sum   = 0.0;
        foreach ($items as $it) {
            $qty   = max(1, (int) ($it['qty'] ?? 1));
            $chosen = isset($selections[$it['slot']]) ? (int) $selections[$it['slot']] : 0;
            $sum  += self::line_unit_price($it, $chosen) * $qty;
        }

        $combo_price = max(0.0, (float) get_post_meta($bundle_id, GALADO_BUNDLES_META . 'combo_price', true));
        if ($combo_price > 0) {
            $save = ($combo_price < $sum) ? $sum - $combo_price : 0.0;
        } else {
            $save = max(0.0, (float) get_post_meta($bundle_id, GALADO_BUNDLES_META . 'save', true));
        }

        $total = $sum - $save;
        if ($save >= $sum) $total = $sum; // misconfigured; render with no strike (caller logs)
        return ['sum' => round($sum, 2), 'save' => round($save, 2), 'total' => round(max(0, $total), 2)];
    }

    /** Live unit price of one line. shopper_choice on the static card uses the
     * lowest in-stock allowed variation price. */
    private static function line_unit_price($item, $chosen_variation_id = 0) {
        // Display basis (wc_get_price_to_display), matching the card line prices
        // and the picker option prices, so the header sum/total never diverges
        // from what the shopper reads. Tax is off on this store, so this equals
        // get_price today; the shared basis keeps them aligned if tax is enabled.
        $pid = (int) $item['product_id'];
        if ('variable' === $item['line_type']) {
            if ($chosen_variation_id) {
                $v = wc_get_product($chosen_variation_id);
                if ($v && $v->get_parent_id() === $pid) return (float) wc_get_price_to_display($v);
            }
            if ('pinned' === $item['variation_mode'] && !empty($item['default_variation_id'])) {
                $v = wc_get_product((int) $item['default_variation_id']);
                if ($v) return (float) wc_get_price_to_display($v);
            }
            return self::lowest_variation_price($pid);
        }
        $p = wc_get_product($pid);
        return $p ? (float) wc_get_price_to_display($p) : 0.0;
    }

    private static function lowest_variation_price($parent_id) {
        $parent = wc_get_product($parent_id);
        if (!$parent || !$parent->is_type('variable')) return 0.0;
        $lowest = null;
        foreach ($parent->get_children() as $cid) {
            $v = wc_get_product($cid);
            if (!$v || !$v->is_purchasable() || !$v->is_in_stock()) continue;
            $pr = (float) wc_get_price_to_display($v);
            if (null === $lowest || $pr < $lowest) $lowest = $pr;
        }
        return null === $lowest ? 0.0 : $lowest;
    }

    /** Every required item must be buyable, or the storefront hides the bundle. */
    public static function is_buyable($items) {
        foreach ($items as $it) {
            if (!self::is_line_buyable($it)) return false;
        }
        return count($items) > 0;
    }

    private static function is_line_buyable($item) {
        $pid = (int) $item['product_id'];
        if ('variable' === $item['line_type']) {
            $parent = wc_get_product($pid);
            if (!$parent || !$parent->is_type('variable')) return false;
            if ('pinned' === $item['variation_mode'] && !empty($item['default_variation_id'])) {
                $v = wc_get_product((int) $item['default_variation_id']);
                return $v && $v->get_parent_id() === $pid && $v->is_purchasable() && $v->is_in_stock();
            }
            foreach ($parent->get_children() as $cid) {
                $v = wc_get_product($cid);
                if ($v && $v->is_purchasable() && $v->is_in_stock()) return true;
            }
            return false;
        }
        $p = wc_get_product($pid);
        return $p && $p->is_purchasable() && $p->is_in_stock();
    }

    /** List-table + storefront health. error = referenced product gone / pinned
     * variation invalid; warn = an item is out of stock; ok otherwise. */
    public static function health($id) {
        $items = self::items($id);
        if (!$items) return ['level' => 'error', 'reason' => 'No items'];
        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            $p = wc_get_product($pid);
            if (!$p || 'trash' === get_post_status($pid)) {
                return ['level' => 'error', 'reason' => 'Product #' . $pid . ' is missing or trashed'];
            }
            if ('variable' === $it['line_type'] && 'pinned' === $it['variation_mode']) {
                $vid = (int) ($it['default_variation_id'] ?? 0);
                $v = $vid ? wc_get_product($vid) : null;
                if (!$v || $v->get_parent_id() !== $pid) {
                    return ['level' => 'error', 'reason' => 'Pinned variation for ' . $p->get_name() . ' is gone'];
                }
            }
        }
        if (!self::is_buyable($items)) {
            return ['level' => 'warn', 'reason' => 'An item is out of stock; hidden on the storefront until back'];
        }
        return ['level' => 'ok', 'reason' => 'All items buyable'];
    }

    /** Authoring warning (not a block) for items carrying WooCommerce Product
     * Add-on (WCPA/Acowebs) fields. Per the spec audit, a naive "required paid
     * field -> block" false-blocks the seed bundles (their required fields are
     * CONDITIONAL, hidden until the shopper opts in), which is why #95 adds them
     * with no add-on payload and works. We therefore warn, not block; a product
     * with a genuinely unconditional required add-on fails the all-or-nothing
     * runtime add (WCPA rejects the add), a safe failure, rather than silently
     * under-charging. Returns a note string, or '' when the item has no add-ons. */
    public static function wcpa_addon_note($product_id) {
        $form = get_post_meta((int) $product_id, '_wcpa_product_meta', true);
        return empty($form) ? '' : 'has add-on fields';
    }

    // ---- internals ----------------------------------------------------------

    /** Stored line items, decoded and shape-normalised. */
    public static function items($id) {
        $raw = get_post_meta((int) $id, GALADO_BUNDLES_META . 'items', true);
        $arr = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $r) {
            if (empty($r['product_id'])) continue;
            $line_type = (isset($r['line_type']) && 'variable' === $r['line_type']) ? 'variable' : 'simple';
            $mode = 'fixed';
            if ('variable' === $line_type) {
                $posted = (string) ($r['variation_mode'] ?? '');
                $mode = in_array($posted, ['shopper_choice', 'model_match'], true) ? $posted : 'pinned';
            }

            // model_match only: extra attribute constraints a matching variation
            // must satisfy (e.g. the Camera Plateau "Option" pinned to one value).
            $match = [];
            if ('model_match' === $mode && !empty($r['match_attrs']) && is_array($r['match_attrs'])) {
                foreach ($r['match_attrs'] as $k => $v) {
                    if (!is_scalar($v)) continue;
                    $match[sanitize_text_field((string) $k)] = sanitize_text_field((string) $v);
                }
            }

            $out[] = [
                'slot'                 => (string) ($r['slot'] ?? ('slot' . count($out))),
                'product_id'           => (int) $r['product_id'],
                'line_type'            => $line_type,
                'qty'                  => max(1, (int) ($r['qty'] ?? 1)),
                'variation_mode'       => $mode,
                'default_variation_id' => 'simple' === $line_type ? 0 : (int) ($r['default_variation_id'] ?? 0),
                'match_attrs'          => $match,
                'addon_price'          => max(0.0, (float) ($r['addon_price'] ?? 0)),
                'label'                => (string) ($r['label'] ?? ''),
                'name_cache'           => (string) ($r['name_cache'] ?? ''),
                'price_cache'          => (float) ($r['price_cache'] ?? 0),
            ];
        }
        return $out;
    }

    /** link if saving is 0; build if any shopper_choice; else click. */
    public static function derive_mode($items, $save) {
        if ((float) $save <= 0) return 'link';
        foreach ($items as $it) {
            if ('variable' === $it['line_type'] && 'shopper_choice' === $it['variation_mode']) return 'build';
        }
        return 'click';
    }

    private static function cta($id, $mode) {
        $override = trim((string) get_post_meta($id, GALADO_BUNDLES_META . 'cta', true));
        if ('' !== $override) return $override;
        return 'build' === $mode ? __('Build your kit', 'galado-bundles') : __('Add the set', 'galado-bundles');
    }

    private static function resolve($id_or_slug) {
        if (is_numeric($id_or_slug)) {
            $post = get_post((int) $id_or_slug);
            return ($post && GALADO_BUNDLES_CPT === $post->post_type) ? $post : null;
        }
        $slug = sanitize_title((string) $id_or_slug);
        if ('' === $slug) return null; // never let an empty name fall through to "newest post"
        $q = get_posts([
            'post_type'      => GALADO_BUNDLES_CPT,
            'name'           => $slug,
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        return $q ? $q[0] : null;
    }
}
