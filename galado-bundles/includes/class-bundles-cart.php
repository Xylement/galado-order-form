<?php
/**
 * Cart behaviour (spec 5.3, 5.5, 7.2, 7.3): AJAX add (all-or-nothing), the no-JS
 * and legacy GET redirect, per-line tagging, session persistence, visual
 * grouping and grouped removal. Only loaded when the storefront flag is on.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Cart {

    public static function init() {
        add_action('wc_ajax_galado_bundle_add', [__CLASS__, 'ajax_add']);
        add_action('wc_ajax_nopriv_galado_bundle_add', [__CLASS__, 'ajax_add']);
        add_action('wp_loaded', [__CLASS__, 'handle_get'], 20);

        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'rehydrate'], 20, 2);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'line_meta'], 10, 2);
        add_filter('woocommerce_cart_item_class', [__CLASS__, 'line_class'], 10, 3);
        add_action('woocommerce_cart_item_removed', [__CLASS__, 'noop']); // reserved
        add_action('woocommerce_before_cart', [__CLASS__, 'handle_remove_set']);

        // One-product presentation for COMPLETE protector combos (owner
        // 2026-08-04 r5): the components stay real cart lines underneath
        // (stock + fulfilment untouched) but cart, checkout review and the
        // mini-cart show a single set row at the combo price with ONE remove
        // control - so a shopper can never strip one piece and silently lose
        // the deal. Incomplete sets (stale session, sold-out removal) fall
        // back to plain per-line display at natural prices, self-healing.
        add_filter('woocommerce_cart_item_visible', [__CLASS__, 'set_line_visible'], 10, 3);
        add_filter('woocommerce_checkout_cart_item_visible', [__CLASS__, 'set_line_visible'], 10, 3);
        add_filter('woocommerce_widget_cart_item_visible', [__CLASS__, 'set_line_visible'], 10, 3);
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'set_line_name'], 20, 3);
        add_filter('woocommerce_cart_item_price', [__CLASS__, 'set_line_price'], 20, 3);
        add_filter('woocommerce_cart_item_subtotal', [__CLASS__, 'set_line_subtotal'], 20, 3);
        add_filter('woocommerce_cart_item_quantity', [__CLASS__, 'set_line_qty'], 20, 3);
        add_filter('woocommerce_widget_cart_item_quantity', [__CLASS__, 'set_widget_qty'], 20, 3);
        add_filter('woocommerce_cart_item_remove_link', [__CLASS__, 'set_remove_link'], 20, 2);
        add_filter('woocommerce_cart_contents_count', [__CLASS__, 'set_contents_count'], 20);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'caseless_notice']);
    }

    // ---- combo set grouping ------------------------------------------------

    /**
     * Is there a phone case line in the cart? The with-case economics (addon
     * overrides, combo repricing, set grouping) all key off this, re-checked
     * every totals pass: remove the case and the special prices revert on the
     * spot; put it back and they return. Module-tagged lines can never stand
     * in for the case itself, so a shelf item that happens to be a variable
     * pa_model product cannot self-justify its own discount.
     */
    public static function cart_has_case($cart = null) {
        static $memo_hash = null, $memo = null;
        $cart = $cart ?: (function_exists('WC') ? WC()->cart : null);
        if (!$cart) return false;
        $hash = md5(implode('|', array_keys($cart->get_cart())));
        if ($memo_hash === $hash && null !== $memo) return $memo;

        $has = false;
        foreach ($cart->get_cart() as $ci) {
            if (!empty($ci['galado_bundle']) || !empty($ci['galado_addon_key']) || !empty($ci['galado_addon_price'])) continue;
            $parent = wc_get_product((int) $ci['product_id']);
            if ($parent && GALADO_Bundles_Combos::is_case_pdp($parent)) { $has = true; break; }
        }
        $memo_hash = $hash; $memo = $has;
        return $has;
    }

    /** Cart/checkout notice when with-case priced items sit in a caseless
     * basket: the prices have already reverted (the gates above), this just
     * says why. Legacy save-based sets are not with-case and stay silent. */
    public static function caseless_notice() {
        if (!galado_bundles_can_transact()) return;
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart || self::cart_has_case($cart)) return;
        $map = self::combo_instances($cart);
        $tagged = false;
        foreach ($cart->get_cart() as $ci) {
            if (!empty($ci['galado_addon_price']) || isset($map[(string) ($ci['galado_bundle_uid'] ?? '')])) { $tagged = true; break; }
        }
        if (!$tagged) return;
        wc_add_notice(__('With-case prices need a phone case in your basket. Add your case to unlock the special prices.', 'galado-bundles'), 'notice');
    }

    /**
     * Per-request map of combo set instances in the cart, keyed by the add-time
     * uid: slug/title/combo_price, completeness against the recipe, all line
     * keys and the lead line (first key). Only sets with a combo price join;
     * legacy save-based sets keep the fee flow and per-line display.
     */
    public static function combo_instances($cart = null) {
        static $memo_hash = null, $memo = null;
        $cart = $cart ?: (function_exists('WC') ? WC()->cart : null);
        if (!$cart) return [];

        $sig = [];
        foreach ($cart->get_cart() as $k => $ci) $sig[] = $k . ':' . (int) $ci['quantity'];
        $hash = md5(implode('|', $sig));
        if ($memo_hash === $hash && null !== $memo) return $memo;

        $inst = [];
        foreach ($cart->get_cart() as $key => $ci) {
            if (empty($ci['galado_bundle']) || empty($ci['galado_bundle_uid'])) continue;
            $uid = (string) $ci['galado_bundle_uid'];
            $slot = (string) ($ci['galado_bundle_slot'] ?? '');
            $inst[$uid]['slug'] = (string) $ci['galado_bundle'];
            $inst[$uid]['slots'][$slot] = ($inst[$uid]['slots'][$slot] ?? 0) + (int) $ci['quantity'];
            $inst[$uid]['keys'][] = $key;
        }

        $out = [];
        foreach ($inst as $uid => $d) {
            $desc = GALADO_Bundles_Data::get($d['slug']);
            if (!$desc || empty($desc['combo']) || $desc['combo_price'] <= 0) continue;
            $need = [];
            foreach ($desc['items'] as $it) $need[$it['slot']] = ($need[$it['slot']] ?? 0) + max(1, (int) $it['qty']);
            $complete = true;
            foreach ($need as $slot => $q) {
                if (($d['slots'][$slot] ?? 0) < $q) { $complete = false; break; }
            }
            $out[$uid] = [
                'slug'        => $d['slug'],
                'title'       => $desc['title'],
                'combo_price' => (float) $desc['combo_price'],
                'complete'    => $complete,
                'keys'        => $d['keys'],
                'lead'        => $d['keys'][0],
            ];
        }
        $memo_hash = $hash; $memo = $out;
        return $out;
    }

    /** The COMPLETE instance a line belongs to, or null. Caseless carts get
     * null for everything: no case, no set deal, so the pieces ungroup into
     * plain full-price lines that match the reverted totals. */
    private static function instance_for($cart_item) {
        if (empty($cart_item['galado_bundle_uid'])) return null;
        if (!self::cart_has_case()) return null;
        $map = self::combo_instances();
        $e = $map[(string) $cart_item['galado_bundle_uid']] ?? null;
        return ($e && $e['complete']) ? $e : null;
    }

    /** What the whole instance costs right now (repriced line subtotals). */
    private static function instance_total($e) {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) return 0.0;
        $sum = 0.0;
        foreach ($e['keys'] as $k) {
            $ci = $cart->get_cart_item($k);
            if ($ci) $sum += (float) $ci['line_subtotal'];
        }
        return $sum;
    }

    public static function set_line_visible($visible, $cart_item, $cart_item_key = '') {
        if (!$visible || !galado_bundles_can_transact()) return $visible;
        $e = self::instance_for($cart_item);
        if ($e && '' !== (string) $cart_item_key && $cart_item_key !== $e['lead']) return false;
        return $visible;
    }

    public static function set_line_name($name, $cart_item, $cart_item_key = '') {
        if (!galado_bundles_can_transact()) return $name;
        $e = self::instance_for($cart_item);
        if (!$e || $cart_item_key !== $e['lead']) return $name;
        $cart = WC()->cart;
        $parts = [];
        foreach ($e['keys'] as $k) {
            $ci = $cart ? $cart->get_cart_item($k) : null;
            if ($ci && !empty($ci['data'])) $parts[] = $ci['data']->get_name();
        }
        $html = esc_html($e['title']);
        if ($parts) {
            $html .= '<span class="galado-set-includes" style="display:block;font-size:12px;font-weight:400;color:#6B6B66;margin-top:4px;line-height:1.5">'
                   . esc_html(implode('  +  ', $parts)) . '</span>';
        }
        return $html;
    }

    public static function set_line_price($price, $cart_item, $cart_item_key = '') {
        if (!galado_bundles_can_transact()) return $price;
        $e = self::instance_for($cart_item);
        if (!$e || $cart_item_key !== $e['lead']) return $price;
        return wc_price(self::instance_total($e));
    }

    public static function set_line_subtotal($subtotal, $cart_item, $cart_item_key = '') {
        if (!galado_bundles_can_transact()) return $subtotal;
        $e = self::instance_for($cart_item);
        if (!$e || $cart_item_key !== $e['lead']) return $subtotal;
        return wc_price(self::instance_total($e));
    }

    /** One set = qty 1, not editable piece by piece. */
    public static function set_line_qty($product_quantity, $cart_item_key, $cart_item = null) {
        if (!galado_bundles_can_transact() || null === $cart_item) return $product_quantity;
        $e = self::instance_for($cart_item);
        if (!$e || $cart_item_key !== $e['lead']) return $product_quantity;
        return '<span class="quantity">1</span>';
    }

    public static function set_widget_qty($html, $cart_item, $cart_item_key = '') {
        if (!galado_bundles_can_transact()) return $html;
        $e = self::instance_for($cart_item);
        if (!$e || $cart_item_key !== $e['lead']) return $html;
        return '<span class="quantity">1 &times; ' . wc_price(self::instance_total($e)) . '</span>';
    }

    /** The lead line's x removes the WHOLE set (existing grouped-removal path). */
    public static function set_remove_link($link, $cart_item_key) {
        if (!galado_bundles_can_transact()) return $link;
        $ci = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_item($cart_item_key) : null;
        if (!$ci) return $link;
        $e = self::instance_for($ci);
        if (!$e || $cart_item_key !== $e['lead']) return $link;
        $uid = (string) $ci['galado_bundle_uid'];
        $url = wp_nonce_url(add_query_arg('galado_remove_set', rawurlencode($uid), wc_get_cart_url()), 'galado_remove_set_' . $uid);
        return sprintf(
            '<a href="%s" class="remove" aria-label="%s">&times;</a>',
            esc_url($url),
            esc_attr(sprintf(__('Remove %s', 'galado-bundles'), $e['title']))
        );
    }

    /** Header cart bubble: a complete set counts as ONE item. */
    public static function set_contents_count($count) {
        if (!galado_bundles_can_transact()) return $count;
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart || !self::cart_has_case($cart)) return $count;
        foreach (self::combo_instances($cart) as $e) {
            if (!$e['complete']) continue;
            $qty = 0;
            foreach ($e['keys'] as $k) {
                $ci = $cart->get_cart_item($k);
                if ($ci) $qty += (int) $ci['quantity'];
            }
            $count -= max(0, $qty - 1);
        }
        return max(0, $count);
    }

    public static function noop() {}

    /** Primary path: AJAX, all-or-nothing, stay on page. */
    public static function ajax_add() {
        self::no_cache();
        if (!galado_bundles_can_transact()) {
            wp_send_json(['ok' => false, 'message' => __('Preview mode. Turn the storefront on to enable checkout.', 'galado-bundles')]);
        }
        $slug = isset($_REQUEST['slug']) ? sanitize_title(wp_unslash($_REQUEST['slug'])) : '';
        if ('' === $slug && !empty($_REQUEST['bundle_id'])) {
            $desc = GALADO_Bundles_Data::get((int) $_REQUEST['bundle_id']);
        } else {
            $desc = GALADO_Bundles_Data::get($slug);
        }
        $selections = self::clean_selections($_REQUEST['selections'] ?? []);

        $res = self::add_bundle($desc, $selections);
        if (!$res['ok']) {
            wp_send_json(['ok' => false, 'message' => $res['message']]);
        }
        WC_AJAX::get_refreshed_fragments();
    }

    /** No-JS <noscript> link and any legacy ?galado_set= / ?galado_bundle=
     * bookmark: add then redirect to the cart (direct successor to #95). */
    public static function handle_get() {
        $slug = '';
        if (!empty($_GET['galado_bundle'])) $slug = sanitize_title(wp_unslash($_GET['galado_bundle']));
        elseif (!empty($_GET['galado_set'])) $slug = sanitize_title(wp_unslash($_GET['galado_set']));
        if ('' === $slug) return;
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) return;
        self::no_cache();
        if (!galado_bundles_can_transact()) return; // dark for this visitor
        if (!function_exists('WC') || !WC()->cart) return;

        $desc = GALADO_Bundles_Data::get($slug);
        $res = self::add_bundle($desc, []);
        if (!$res['ok']) {
            wc_add_notice($res['message'], 'error');
        }
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    /** The shared adder. Pre-validates the whole kit, then adds atomically with
     * one shared instance uid. Adds nothing on any failure. */
    public static function add_bundle($desc, $selections) {
        if (!$desc) return ['ok' => false, 'message' => __('That set is not available.', 'galado-bundles')];
        if ('publish' !== ($desc['status'] ?? '')) return ['ok' => false, 'message' => __('That set is not available.', 'galado-bundles')];
        if (!$desc['buyable']) return ['ok' => false, 'message' => __('That set is currently unavailable.', 'galado-bundles')];

        // Resolve + validate every line before touching the cart.
        $plan = [];
        foreach ($desc['items'] as $it) {
            $pid = (int) $it['product_id'];
            $qty = max(1, (int) $it['qty']);
            if ('variable' === $it['line_type']) {
                $vid = self::resolve_variation($it, $selections);
                $v = $vid ? wc_get_product($vid) : null;
                if (!$v || $v->get_parent_id() !== $pid || !$v->is_purchasable() || !$v->is_in_stock() || !$v->has_enough_stock($qty)) {
                    return ['ok' => false, 'message' => __('That option just sold out, please pick another.', 'galado-bundles')];
                }
                $plan[] = ['parent' => $pid, 'variation' => $vid, 'attrs' => $v->get_variation_attributes(), 'qty' => $qty, 'slot' => $it['slot']];
            } else {
                $p = wc_get_product($pid);
                if (!$p || !$p->is_purchasable() || !$p->is_in_stock() || !$p->has_enough_stock($qty)) {
                    return ['ok' => false, 'message' => __('One of the items just went out of stock.', 'galado-bundles')];
                }
                $plan[] = ['parent' => $pid, 'variation' => 0, 'attrs' => [], 'qty' => $qty, 'slot' => $it['slot']];
            }
        }

        $uid = wp_generate_password(6, false, false);
        $added = [];
        foreach ($plan as $line) {
            $data = [
                'galado_bundle'      => $desc['slug'],
                'galado_bundle_uid'  => $uid,
                'galado_bundle_slot' => $line['slot'],
            ];
            $key = WC()->cart->add_to_cart($line['parent'], $line['qty'], $line['variation'], $line['attrs'], $data);
            if (!$key) {
                foreach ($added as $k) WC()->cart->remove_cart_item($k); // roll back
                return ['ok' => false, 'message' => __('Could not add the set, please try again.', 'galado-bundles')];
            }
            $added[] = $key;
        }
        return ['ok' => true, 'message' => '', 'keys' => $added, 'uid' => $uid];
    }

    /** From selections, else the pinned/default, self-healing an invalid choice
     * to the first purchasable variation. Never trusts a client label. */
    private static function resolve_variation($item, $selections) {
        $pid = (int) $item['product_id'];
        $candidates = [];
        if (isset($selections[$item['slot']])) $candidates[] = (int) $selections[$item['slot']];
        if (!empty($item['default_variation_id'])) $candidates[] = (int) $item['default_variation_id'];
        foreach ($candidates as $vid) {
            $v = $vid ? wc_get_product($vid) : null;
            if ($v && $v->get_parent_id() === $pid && $v->is_purchasable() && $v->is_in_stock()) return $vid;
        }

        // model_match (PDP combos) never self-heals: "any purchasable variation"
        // means shipping tempered glass for the WRONG phone. Fail the add
        // cleanly instead; the caller shows "not available for your model".
        if ('model_match' === ($item['variation_mode'] ?? '')) return 0;

        // fallback: first purchasable variation of the parent
        $parent = wc_get_product($pid);
        if ($parent && $parent->is_type('variable')) {
            foreach ($parent->get_children() as $cid) {
                $v = wc_get_product($cid);
                if ($v && $v->is_purchasable() && $v->is_in_stock()) return $cid;
            }
        }
        return 0;
    }

    private static function clean_selections($raw) {
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $slot => $vid) {
                $out[sanitize_key($slot)] = (int) $vid;
            }
        }
        return $out;
    }

    /** Keep the tags across sessions so completeness and the fee survive. */
    public static function rehydrate($item, $values) {
        foreach (['galado_bundle', 'galado_bundle_uid', 'galado_bundle_slot'] as $k) {
            if (!empty($values[$k])) $item[$k] = $values[$k];
        }
        return $item;
    }

    /** "Part of: The Icons Duo" under each tagged line. Lines of a COMPLETE
     * combo skip it: the single set row names its contents itself, and the
     * rest of the set is hidden. Incomplete combos keep the note so stray
     * pieces still explain where they came from. */
    public static function line_meta($data, $cart_item) {
        if (!galado_bundles_can_transact()) return $data; // never decorate #95's lines for dark customers
        if (empty($cart_item['galado_bundle'])) return $data;
        if (self::instance_for($cart_item)) return $data;
        $desc = GALADO_Bundles_Data::get((string) $cart_item['galado_bundle']);
        if ($desc) {
            $data[] = ['key' => __('Part of', 'galado-bundles'), 'value' => $desc['title'], 'display' => esc_html($desc['title'])];
        }
        return $data;
    }

    public static function line_class($class, $cart_item, $cart_item_key) {
        if (!galado_bundles_can_transact()) return $class;
        if (!empty($cart_item['galado_bundle'])) {
            $class .= ' galado-bundle-line galado-bundle-' . sanitize_html_class($cart_item['galado_bundle']);
        }
        return $class;
    }

    /** "Remove set" (owner decision 6): delete all lines sharing one uid. */
    public static function handle_remove_set() {
        if (empty($_GET['galado_remove_set'])) return;
        if (!galado_bundles_can_transact()) return;
        $uid = sanitize_text_field(wp_unslash($_GET['galado_remove_set']));
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'galado_remove_set_' . $uid)) return;
        foreach (WC()->cart->get_cart() as $key => $ci) {
            if (($ci['galado_bundle_uid'] ?? '') === $uid) WC()->cart->remove_cart_item($key);
        }
        wc_add_notice(__('Set removed.', 'galado-bundles'));
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    private static function no_cache() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    }
}
