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
        // Atomic Buy Now (owner 2026-08-04 r7): PDP selections are STAGED
        // client-side; this adds case + every staged PWP item in one request,
        // so a caseless PWP cart can never exist in the honest flow.
        add_action('wc_ajax_galado_pwp_checkout', [__CLASS__, 'ajax_pwp_checkout']);
        add_action('wc_ajax_nopriv_galado_pwp_checkout', [__CLASS__, 'ajax_pwp_checkout']);
        add_action('wp_loaded', [__CLASS__, 'handle_get'], 20);

        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'rehydrate'], 20, 2);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'line_meta'], 10, 2);
        add_filter('woocommerce_cart_item_class', [__CLASS__, 'line_class'], 10, 3);
        // PWP items travel WITH the case (owner r8, matching the old WCPA
        // behaviour): removing the last case sweeps every PWP line out too,
        // with a notice. No caseless PWP cart state can then ever render -
        // fresh, stale, or mid-AJAX.
        add_action('woocommerce_cart_item_removed', [__CLASS__, 'case_removed_sweep'], 10, 2);
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
        return self::case_count($cart) > 0;
    }

    /** How many cases anchor PWP deals: the sum of untagged case-line
     * quantities. One protection set is funded per case (owner r10: two
     * cases + two sets, remove one case, both sets kept the deal). */
    public static function case_count($cart = null) {
        static $memo_hash = null, $memo = null;
        $cart = $cart ?: (function_exists('WC') ? WC()->cart : null);
        if (!$cart) return 0;
        $sig = [];
        foreach ($cart->get_cart() as $k => $ci) $sig[] = $k . ':' . (int) $ci['quantity'];
        $hash = md5(implode('|', $sig));
        if ($memo_hash === $hash && null !== $memo) return $memo;

        $count = 0;
        foreach ($cart->get_cart() as $ci) {
            if (!empty($ci['galado_bundle']) || !empty($ci['galado_addon_key']) || !empty($ci['galado_addon_price'])) continue;
            $parent = wc_get_product((int) $ci['product_id']);
            if ($parent && GALADO_Bundles_Combos::is_case_pdp($parent)) {
                $count += max(1, (int) $ci['quantity']);
            }
        }
        $memo_hash = $hash; $memo = $count;
        return $count;
    }

    /** Cart/checkout notice when with-case priced items sit in a caseless
     * basket: the prices have already reverted (the gates above), this just
     * says why. Legacy save-based sets are not with-case and stay silent. */
    public static function caseless_notice() {
        if (!galado_bundles_can_transact()) return;
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) return;
        $map = self::combo_instances($cart);
        if (!self::cart_has_case($cart)) {
            $tagged = false;
            foreach ($cart->get_cart() as $ci) {
                if (!empty($ci['galado_addon_price']) || isset($map[(string) ($ci['galado_bundle_uid'] ?? '')])) { $tagged = true; break; }
            }
            if ($tagged) {
                wc_add_notice(__('PWP prices need a phone case in your basket. Add your case to unlock the PWP prices.', 'galado-bundles'), 'notice');
            }
            return;
        }
        // Case budget exceeded: a set past the budget sits at normal price.
        foreach ($map as $e) {
            if ($e['complete'] && empty($e['repriced'])) {
                wc_add_notice(__('Each protection set needs its own case for the PWP price, so the extra set is at normal price.', 'galado-bundles'), 'notice');
                break;
            }
        }
    }

    /**
     * Atomic Buy Now: validate + add the case FIRST (nothing proceeds without
     * it), then every staged PWP item. The case form fields arrive as normal
     * POST data, so add-on plugins that read posted fields at add time (the
     * WCPA name personalisation) work exactly as a native form submit.
     * Staged items are pure INTENT: every id is re-validated against the
     * curated shelves/combos, stock and the once-per-circle rule server-side.
     */
    public static function ajax_pwp_checkout() {
        self::no_cache();
        if (!galado_bundles_can_transact()) {
            wp_send_json(['ok' => false, 'message' => __('Preview mode. Turn the storefront on to enable checkout.', 'galado-bundles')]);
        }
        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json(['ok' => false, 'message' => __('Could not reach the basket, please try again.', 'galado-bundles')]);
        }

        // Anything fatal below (a plugin hooked into add-to-cart, a data
        // surprise) must still answer JSON: a white-screen here reads as a
        // generic client error with zero clues. The code in the message
        // pinpoints the throw site from a single owner report.
        try {
            self::pwp_checkout_body();
        } catch (Throwable $e) {
            error_log('[galado-bundles] pwp_checkout fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json([
                'ok'      => false,
                'message' => sprintf(
                    /* translators: %s: internal error code */
                    __('Something went wrong adding your set (code %s). Please try again.', 'galado-bundles'),
                    basename(str_replace('\\', '/', get_class($e))) . '#' . $e->getLine()
                ),
            ]);
        }
    }

    private static function pwp_checkout_body() {
        // --- the anchor product (owner r14: ANY product page stages the same
        // way; a non-case anchor simply funds no PWP discounts - the case
        // gates at totals time stay the money authority) -------------------
        $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $vid = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
        $qty = isset($_POST['quantity']) ? max(1, (int) $_POST['quantity']) : 1;
        $parent = $pid ? wc_get_product($pid) : null;
        if (!$parent || !$parent->is_purchasable() || !$parent->is_in_stock()) {
            wp_send_json(['ok' => false, 'message' => __('Please select your product first.', 'galado-bundles')]);
        }
        $is_case_anchor = GALADO_Bundles_Combos::is_case_pdp($parent);
        if ($parent->is_type('variable')) {
            $v = $vid ? wc_get_product($vid) : null;
            if (!$v || $v->get_parent_id() !== $pid || !$v->is_purchasable() || !$v->is_in_stock()) {
                wp_send_json(['ok' => false, 'message' => $is_case_anchor
                    ? __('Please select your case model and colour first.', 'galado-bundles')
                    : __('Please select your options first.', 'galado-bundles')]);
            }
            $case_key = WC()->cart->add_to_cart($pid, $qty, $vid, $v->get_variation_attributes());
        } else {
            $case_key = WC()->cart->add_to_cart($pid, $qty);
        }
        if (!$case_key) {
            // Woo queued the reason (stock, validation) as a notice; surface it.
            wc_clear_notices();
            wp_send_json(['ok' => false, 'message' => __('Could not add it, please try again.', 'galado-bundles')]);
        }

        // --- staged PWP items -------------------------------------------
        $raw = isset($_POST['gld_stage']) ? json_decode(wp_unslash((string) $_POST['gld_stage']), true) : [];
        $skipped = [];
        $claimed = [];
        if (is_array($raw)) {
            foreach (array_slice($raw, 0, 30) as $item) {
                if (!is_array($item)) continue;
                $type = isset($item['type']) ? (string) $item['type'] : '';
                if ('combo' === $type) {
                    $res = GALADO_Bundles_Combos::add_for_model(
                        sanitize_title((string) ($item['slug'] ?? '')),
                        sanitize_title((string) ($item['model'] ?? '')),
                        GALADO_Bundles_Combos::clean_extra($item['extra'] ?? [])
                    );
                    if (!$res['ok']) $skipped[] = sanitize_text_field((string) ($item['name'] ?? __('Protection set', 'galado-bundles')));
                } elseif ('addon' === $type) {
                    $res = GALADO_Bundles_Addons::add_addon_line(
                        (int) ($item['product_id'] ?? 0),
                        (int) ($item['variation_id'] ?? 0),
                        $claimed
                    );
                    if (!$res['ok']) $skipped[] = sanitize_text_field((string) ($item['name'] ?? __('Add-on', 'galado-bundles')));
                }
            }
        }
        if ($skipped) {
            wc_add_notice(sprintf(
                /* translators: %s: comma-separated item names */
                __('Heads up: %s just sold out and could not be added. Everything else is in your basket.', 'galado-bundles'),
                implode(', ', array_unique($skipped))
            ), 'notice');
        }
        wp_send_json([
            'ok'       => true,
            'redirect' => wc_get_cart_url(),
            'skipped'  => array_values(array_unique($skipped)),
        ]);
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

        // Case budget (owner r10): each complete set needs its OWN case for
        // the deal. First-come order; the flag is computed here so pricing,
        // grouping, counts and notices all read the same verdict.
        $budget = self::case_count($cart);
        foreach ($out as $uid => $e) {
            $out[$uid]['repriced'] = $e['complete'] && $budget > 0;
            if ($out[$uid]['repriced']) $budget--;
        }

        $memo_hash = $hash; $memo = $out;
        return $out;
    }

    /** The case-funded instance a line belongs to, or null. Instances beyond
     * the case budget (and everything in a caseless cart) return null, so
     * those pieces ungroup into plain full-price lines that match the
     * unrepriced totals. */
    private static function instance_for($cart_item) {
        if (empty($cart_item['galado_bundle_uid'])) return null;
        $map = self::combo_instances();
        $e = $map[(string) $cart_item['galado_bundle_uid']] ?? null;
        return ($e && !empty($e['repriced'])) ? $e : null;
    }

    /** What the whole instance costs right now (repriced line subtotals). */
    private static function instance_total($e) {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) return 0.0;
        $sum = 0.0;
        foreach ($e['keys'] as $k) {
            $ci = $cart->get_cart_item($k);
            if ($ci && isset($ci['line_subtotal'])) $sum += (float) $ci['line_subtotal'];
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

    /** Original price struck to the PWP price (owner r7). Runs through
     * wc_format_sale_price so the brand-skin sale ordering/styling applies. */
    private static function sale_html($own, $paid) {
        if ($own > $paid + 0.004) return wc_format_sale_price($own, $paid);
        return wc_price($paid);
    }

    /** Full shelf price of an addon line (display basis). */
    private static function addon_own($cart_item) {
        $p = wc_get_product(!empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id']);
        return $p ? (float) wc_get_price_to_display($p) : 0.0;
    }

    /** Sum of the components' full prices for a set instance. */
    private static function instance_own_total($e) {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart) return 0.0;
        $sum = 0.0;
        foreach ($e['keys'] as $k) {
            $ci = $cart->get_cart_item($k);
            if ($ci) $sum += self::addon_own($ci) * max(1, (int) $ci['quantity']);
        }
        return $sum;
    }

    /** Is this line an addon selling at an APPLIED PWP override right now? */
    private static function addon_override_active($cart_item) {
        return !empty($cart_item['galado_addon_price'])
            && galado_bundles_can_transact()
            && self::cart_has_case();
    }

    public static function set_line_price($price, $cart_item, $cart_item_key = '') {
        if (!galado_bundles_can_transact()) return $price;
        $e = self::instance_for($cart_item);
        if ($e && $cart_item_key === $e['lead']) {
            return self::sale_html(self::instance_own_total($e), self::instance_total($e));
        }
        if (self::addon_override_active($cart_item)) {
            return self::sale_html(self::addon_own($cart_item), (float) $cart_item['galado_addon_price']);
        }
        return $price;
    }

    public static function set_line_subtotal($subtotal, $cart_item, $cart_item_key = '') {
        if (!galado_bundles_can_transact()) return $subtotal;
        $e = self::instance_for($cart_item);
        if ($e && $cart_item_key === $e['lead']) {
            return self::sale_html(self::instance_own_total($e), self::instance_total($e));
        }
        if (self::addon_override_active($cart_item)) {
            $qty = max(1, (int) $cart_item['quantity']);
            return self::sale_html(self::addon_own($cart_item) * $qty, (float) $cart_item['galado_addon_price'] * $qty);
        }
        return $subtotal;
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
            if (empty($e['repriced'])) continue;
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

    /** When the LAST case leaves the basket, the PWP configuration leaves
     * with it. Module lines re-fire this hook as they are swept; they are
     * tagged, so the early return keeps it from recursing. */
    public static function case_removed_sweep($removed_key, $cart) {
        if (!galado_bundles_can_transact()) return;
        if (!($cart instanceof WC_Cart)) return;
        $removed = $cart->removed_cart_contents[$removed_key] ?? null;
        if (!$removed) return;
        if (!empty($removed['galado_bundle']) || !empty($removed['galado_addon_key']) || !empty($removed['galado_addon_price'])) return;
        $parent = wc_get_product((int) ($removed['product_id'] ?? 0));
        if (!$parent || !GALADO_Bundles_Combos::is_case_pdp($parent)) return;
        if (self::cart_has_case($cart)) return; // another case still anchors the deal

        $swept = 0;
        foreach ($cart->get_cart() as $k => $ci) {
            if (!empty($ci['galado_bundle']) || !empty($ci['galado_addon_key']) || !empty($ci['galado_addon_price'])) {
                $cart->remove_cart_item($k);
                $swept++;
            }
        }
        if ($swept) {
            wc_add_notice(__('Your PWP items go together with the case, so they were removed too. Add a case to pick them again at PWP prices.', 'galado-bundles'), 'notice');
        }
    }

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
        } elseif (!empty($cart_item['galado_addon_key']) || !empty($cart_item['galado_addon_price'])) {
            $class .= ' galado-addon-line';
        } else {
            // Case rows get a marker so the cart JS can ask "removing this
            // takes the PWP items too - sure?" before the sweep runs.
            $parent = wc_get_product((int) $cart_item['product_id']);
            if ($parent && GALADO_Bundles_Combos::is_case_pdp($parent)) {
                $class .= ' galado-case-line';
            }
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
