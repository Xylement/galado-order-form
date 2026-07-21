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
    }

    public static function noop() {}

    /** Primary path: AJAX, all-or-nothing, stay on page. */
    public static function ajax_add() {
        self::no_cache();
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

    /** "Part of: The Icons Duo" under each tagged line. */
    public static function line_meta($data, $cart_item) {
        if (empty($cart_item['galado_bundle'])) return $data;
        $desc = GALADO_Bundles_Data::get((string) $cart_item['galado_bundle']);
        if ($desc) {
            $data[] = ['key' => __('Part of', 'galado-bundles'), 'value' => $desc['title'], 'display' => esc_html($desc['title'])];
        }
        return $data;
    }

    public static function line_class($class, $cart_item, $cart_item_key) {
        if (!empty($cart_item['galado_bundle'])) {
            $class .= ' galado-bundle-line galado-bundle-' . sanitize_html_class($cart_item['galado_bundle']);
        }
        return $class;
    }

    /** "Remove set" (owner decision 6): delete all lines sharing one uid. */
    public static function handle_remove_set() {
        if (empty($_GET['galado_remove_set'])) return;
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
