<?php
/**
 * PDP accessory add-on shelves: the successor to the WCPA purchase-with-
 * purchase rows (owner decision 2026-08-04: WCPA stays for name customisation
 * only). Each shelf is a bundle post flagged addon_group; its items are single
 * products sold at their own price. Rendered on case PDPs BELOW the protector
 * combos; variable items are chosen through circular image chips or colour
 * dots, the same language as the bundles picker.
 *
 * No fee, no combo pricing: adds are plain cart lines, so nothing here touches
 * the discount engine.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Addons {

    private static $rendered = false;

    public static function init() {
        // Same placements as the combos module, one priority later, so
        // whichever hook the theme fires, combos render first, this below.
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'render'], 21);
        add_action('woocommerce_after_add_to_cart_form', [__CLASS__, 'render'], 12);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render'], 40);
        add_action('woocommerce_after_single_product_summary', [__CLASS__, 'render'], 6);
        add_action('wc_ajax_galado_addon_add', [__CLASS__, 'ajax_add']);
        add_action('wc_ajax_nopriv_galado_addon_add', [__CLASS__, 'ajax_add']);
        // Cart-derived PWP state (used circles + counters for the floating
        // bar). Cart data must never bake into cacheable page markup, so the
        // client fetches this per visitor, uncached.
        add_action('wc_ajax_galado_pwp_state', [__CLASS__, 'ajax_state']);
        add_action('wc_ajax_nopriv_galado_pwp_state', [__CLASS__, 'ajax_state']);
        // The with-case price is one piece per circle: block cart quantity
        // bumps on override lines (a second piece is welcome at normal price).
        add_filter('woocommerce_update_cart_validation', [__CLASS__, 'limit_qty'], 10, 4);
        // With-case add-on pricing: shelf items can sell below their standalone
        // price (the old WCPA rows were priced this way). The override is set
        // server-side at add time and applied on every totals pass.
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_addon_prices'], 20);
        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'rehydrate'], 20, 2);
    }

    /** Keep the add-on price across sessions. */
    public static function rehydrate($item, $values) {
        if (!empty($values['galado_addon_price'])) $item['galado_addon_price'] = $values['galado_addon_price'];
        if (!empty($values['galado_addon_key'])) $item['galado_addon_key'] = $values['galado_addon_key'];
        return $item;
    }

    /** One piece per circle at the PWP price (owner 2026-08-04). */
    public static function limit_qty($passed, $cart_item_key, $values, $quantity) {
        if (!empty($values['galado_addon_price']) && $quantity > 1) {
            wc_add_notice(__('The PWP price is limited to 1 piece. You can add another at the normal price.', 'galado-bundles'), 'error');
            return false;
        }
        return $passed;
    }

    /** Is this product curated into an active shelf, and on what terms?
     * Shared by the per-item AJAX add and the atomic Buy Now checkout. */
    public static function lookup_addon($pid) {
        $pid = (int) $pid;
        foreach (GALADO_Bundles_Data::get_addon_groups() as $group) {
            foreach ($group['items'] as $it) {
                if ((int) $it['product_id'] === $pid) {
                    $lbl = trim((string) ($it['label'] ?? ''));
                    return [
                        'allowed'     => true,
                        'addon_price' => max(0.0, (float) ($it['addon_price'] ?? 0)),
                        'circle'      => '' !== $lbl ? 'g-' . sanitize_title($lbl) : (string) $pid,
                    ];
                }
            }
        }
        return ['allowed' => false, 'addon_price' => 0.0, 'circle' => ''];
    }

    /** Add one curated shelf item to the cart, honouring once-per-circle
     * against both the cart and the circles already claimed in this same
     * request. Returns [ok, message, reused]. */
    public static function add_addon_line($pid, $vid, array &$claimed) {
        $info = self::lookup_addon($pid);
        if (!$info['allowed']) {
            return ['ok' => false, 'message' => __('That add-on is not available.', 'galado-bundles'), 'reused' => false];
        }
        $circle = $info['circle'];
        $reused = isset($claimed[$circle]);
        if (!$reused && function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $ci) {
                if (($ci['galado_addon_key'] ?? '') === $circle) { $reused = true; break; }
            }
        }
        $p = wc_get_product((int) $pid);
        if (!$p || !$p->is_purchasable() || !$p->is_in_stock()) {
            return ['ok' => false, 'message' => __('That add-on is not available.', 'galado-bundles'), 'reused' => false];
        }
        $data = (!$reused && $info['addon_price'] > 0)
            ? ['galado_addon_price' => $info['addon_price'], 'galado_addon_key' => $circle]
            : [];
        if ($p->is_type('variable')) {
            $v = $vid ? wc_get_product((int) $vid) : null;
            if (!$v || $v->get_parent_id() !== (int) $pid || !$v->is_purchasable() || !$v->is_in_stock()) {
                return ['ok' => false, 'message' => __('That option just sold out, please pick another.', 'galado-bundles'), 'reused' => false];
            }
            $key = WC()->cart->add_to_cart((int) $pid, 1, (int) $vid, $v->get_variation_attributes(), $data);
        } else {
            $key = WC()->cart->add_to_cart((int) $pid, 1, 0, [], $data);
        }
        if (!$key) {
            return ['ok' => false, 'message' => __('Could not add it, please try again.', 'galado-bundles'), 'reused' => false];
        }
        if (!$reused) $claimed[$circle] = true;
        return ['ok' => true, 'message' => '', 'reused' => $reused];
    }

    /** Cart-derived module state: which circles already claimed their
     * with-case price, plus the floating-bar counters. */
    public static function state_payload() {
        $used = []; $count = 0; $saved = 0.0; $total = 0.0; $bundle_total = 0.0;
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->calculate_totals();

            // The bar's numbers are the PROMISE, not the interim cart state:
            // with-case prices only APPLY once the case line exists (it
            // arrives via Buy Now, after the add-ons), so mid-flow the cart
            // briefly holds add-ons at natural prices. Computing from the
            // curated with-case tags keeps the bar telling the shopper what
            // they WILL pay; cart and checkout always show the honest,
            // gate-checked totals.
            $combo_line = [];
            foreach (GALADO_Bundles_Cart::combo_instances(WC()->cart) as $e) {
                // Only case-funded sets carry the promise; an over-budget set's
                // lines fall through to the per-line loop at real prices.
                if (empty($e['repriced'])) continue;
                $count += 1;
                $own_sum = 0.0;
                foreach ($e['keys'] as $k) {
                    $combo_line[$k] = true;
                    $ci = WC()->cart->get_cart_item($k);
                    if (!$ci) continue;
                    $p = wc_get_product(!empty($ci['variation_id']) ? $ci['variation_id'] : $ci['product_id']);
                    if ($p) $own_sum += (float) wc_get_price_to_display($p) * max(1, (int) $ci['quantity']);
                }
                // Whichever is cheaper, same rule as the repricer.
                $promised = ($own_sum > 0 && $own_sum < (float) $e['combo_price']) ? $own_sum : (float) $e['combo_price'];
                $bundle_total += $promised;
                if ($own_sum > $promised) $saved += $own_sum - $promised;
            }

            foreach (WC()->cart->get_cart() as $key => $ci) {
                if (isset($combo_line[$key])) continue; // counted at set level
                $qty = max(1, (int) $ci['quantity']);
                $is_module = !empty($ci['galado_bundle']) || !empty($ci['galado_addon_key']) || !empty($ci['galado_addon_price']);
                if ($is_module) {
                    $count += $qty;
                    $p = wc_get_product(!empty($ci['variation_id']) ? $ci['variation_id'] : $ci['product_id']);
                    $own = $p ? (float) wc_get_price_to_display($p) : 0.0;
                    if (!empty($ci['galado_addon_price'])) {
                        $promised = min($own > 0 ? $own : (float) $ci['galado_addon_price'], (float) $ci['galado_addon_price']);
                        $bundle_total += $promised * $qty;
                        if ($own > $promised) $saved += ($own - $promised) * $qty;
                    } else {
                        // Untagged module lines (legacy sets, reused adds): real numbers.
                        $bundle_total += isset($ci['line_total']) ? (float) $ci['line_total'] : 0.0;
                    }
                }
                if (!empty($ci['galado_addon_key'])) $used[] = (string) $ci['galado_addon_key'];
            }
            // Legacy save-based sets still discount via a fee.
            foreach (WC()->cart->get_fees() as $fee) {
                if (0 === strpos((string) $fee->id, 'galado-bundle-') && $fee->total < 0) {
                    $saved += -1 * (float) $fee->total;
                    $bundle_total += (float) $fee->total; // negative: nets the saving into what the items add
                }
            }
            $total = (float) WC()->cart->get_total('edit');
        }
        return [
            'used'         => array_values(array_unique($used)),
            'count'        => $count,
            'saved'        => round($saved, 2),
            'total'        => round($total, 2),
            'bundle_total' => round($bundle_total, 2),
        ];
    }

    public static function ajax_state() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        wp_send_json(self::state_payload());
    }

    /** Fragments + our state in one response (get_refreshed_fragments dies
     * before we could append anything, so the payload is built directly). */
    private static function respond_added($extra = []) {
        wp_send_json(array_merge([
            'ok'        => true,
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', []),
            'cart_hash' => WC()->cart->get_cart_hash(),
            'state'     => self::state_payload(),
        ], $extra));
    }

    /** Set the with-case price on lines added from a shelf. The key is only
     * ever written by ajax_add from the CURATED item list, never from client
     * input, so this cannot reprice arbitrary lines. */
    public static function apply_addon_prices($cart) {
        if (!$cart) return;
        // With-case means WITH A CASE (owner 2026-08-04 r6): no case line in
        // the basket, no override. Re-checked on every totals pass, so
        // removing the case reverts these prices instantly and re-adding it
        // restores them - nothing to strip, nothing to un-strip.
        if (!GALADO_Bundles_Cart::cart_has_case($cart)) return;
        foreach ($cart->get_cart() as $ci) {
            if (empty($ci['galado_addon_price']) || empty($ci['data'])) continue;
            $ci['data']->set_price((float) $ci['galado_addon_price']);
        }
    }

    private static function visible() {
        if (galado_bundles_storefront_enabled() && galado_bundles_addons_enabled()) return true;
        return galado_bundles_addons_enabled() && GALADO_Bundles_Storefront::can_preview();
    }

    public static function render() {
        if (self::$rendered) return;
        if (!self::visible()) return;
        if (!function_exists('is_product') || !is_product()) return;

        global $product;
        $groups = self::page_groups($product);
        if (!$groups) return;

        self::$rendered = true;
        self::enqueue($groups, GALADO_Bundles_Combos::is_case_pdp($product));
        foreach ($groups as $g) echo self::markup($g);
    }

    /** Shelves for this PDP, or null. Same case gate as the combos module.
     * Cached 15 minutes per (product, catalogue version), same reasoning and
     * same invalidation salt as the combos module. */
    public static function page_groups($product) {
        $ck = 'gldag_' . $product->get_id() . '_' . GALADO_Bundles_Combos::cat_ver();
        $cached = get_transient($ck);
        if (false !== $cached) return $cached ?: null;

        $data = self::build_page_groups($product);
        set_transient($ck, $data ?: '', 15 * MINUTE_IN_SECONDS);
        return $data;
    }

    /** Does this shelf belong on this product page? Case pages by default,
     * plus any per-shelf extra categories / product ids (owner r12). */
    private static function group_matches($group, $product, $is_case) {
        if (!empty($group['show_on_cases']) && $is_case) return true;
        if (!empty($group['audience_ids']) && in_array((int) $product->get_id(), $group['audience_ids'], true)) return true;
        if (!empty($group['audience_cats'])) {
            $terms = get_the_terms($product->get_id(), 'product_cat');
            $slugs = [];
            foreach ((array) $terms as $t) {
                if ($t instanceof WP_Term) $slugs[] = $t->slug;
            }
            if (array_intersect($group['audience_cats'], $slugs)) return true;
        }
        return false;
    }

    private static function build_page_groups($product) {
        // Non-case surfaces (charm and Stylink pages) get their shelves at
        // NORMAL prices - PWP pricing always needs a case - and add through
        // the direct per-item path (no staging bar).
        $is_case = GALADO_Bundles_Combos::is_case_pdp($product);

        $out = [];
        foreach (GALADO_Bundles_Data::get_addon_groups() as $group) {
            if (!self::group_matches($group, $product, $is_case)) continue;
            // Sibling grouping (owner 2026-08-04): SIMPLE item rows sharing a
            // "Group as" label collapse into ONE circle whose options are those
            // products (the old WCPA rows were exactly this: one offer, many
            // real standalone products behind it). Order of first appearance
            // is preserved; variable products always stand alone.
            $items = [];
            $group_index = [];
            foreach ($group['items'] as $it) {
                $item = self::item_data($it, $product->get_id(), $is_case);
                if (!$item) continue;
                $lbl = trim((string) ($it['label'] ?? ''));
                if ('' === $lbl || 'variable' === $item['type']) {
                    $items[] = $item;
                    continue;
                }
                if (!isset($group_index[$lbl])) {
                    $items[] = [
                        'key'         => 'g-' . sanitize_title($lbl),
                        'product_id'  => 0,
                        'name'        => $lbl,
                        'thumb'       => $item['thumb'],
                        'price'       => $item['price'],
                        'was'         => $item['was'],
                        // The members' PWP price: the bar computes its staged
                        // Discount from this (a hardcoded 0 here was why
                        // accessory savings never reached the bar - owner r10).
                        'addon_price' => $item['addon_price'],
                        'type'        => 'group',
                        'options'     => [],
                    ];
                    $group_index[$lbl] = count($items) - 1;
                }
                $gi = $group_index[$lbl];
                $items[$gi]['options'][] = [
                    'id'    => $item['product_id'],
                    'label' => self::short_label($item['name']),
                    'price' => $item['price'],
                    'thumb' => $item['thumb'],
                ];
                if ($item['price'] < $items[$gi]['price']) {
                    $items[$gi]['price'] = $item['price'];
                    $items[$gi]['was']   = $item['was'];
                }
            }
            // Mixed-price groups (the Clip-On circle spans RM29-35) show
            // "From RM29" and price every option chip (owner r13).
            foreach ($items as $i => $item2) {
                if ('group' !== $item2['type'] || empty($item2['options'])) continue;
                $prices = array_map('floatval', wp_list_pluck($item2['options'], 'price'));
                $items[$i]['varies'] = count($prices) > 1 && (max($prices) - min($prices)) > 0.004;
            }
            if ($items) {
                $out[] = ['slug' => $group['slug'], 'title' => $group['title'], 'items' => $items];
            }
        }
        return $out ?: null;
    }

    /** Compact option label: colour part after " - " when present, otherwise
     * the name minus common charm suffixes. */
    private static function short_label($name) {
        // "Clip-On Charm (360 Starfish)" -> "360 Starfish"
        if (preg_match('/\(([^)]+)\)\s*$/', $name, $m)) {
            return trim($m[1]);
        }
        if (false !== strpos($name, ' - ')) {
            $parts = explode(' - ', $name);
            return trim(end($parts));
        }
        return trim(preg_replace('/\s+(Phone|Pearl)?\s*Charm$/i', '', $name));
    }

    /** One shelf item: display data plus, for variable products, the option
     * circles (own-image chips when every variation has its own image, colour
     * dots otherwise; labels always carried for a11y). */
    private static function item_data($it, $current_pid, $apply_pwp = true) {
        $pid = (int) $it['product_id'];
        if ($pid === (int) $current_pid) return null; // never sell the page to itself
        $p = wc_get_product($pid);
        if (!$p || 'publish' !== $p->get_status() || !$p->is_purchasable() || !$p->is_in_stock()) return null;

        // On non-case surfaces the with-case price does not apply: everything
        // downstream (price, strike, option flattening) follows from zeroing it.
        $addon_price = $apply_pwp ? max(0.0, (float) ($it['addon_price'] ?? 0)) : 0.0;
        $own_price   = (float) wc_get_price_to_display($p);
        $base = [
            'key'         => (string) $pid,
            'product_id'  => $pid,
            'name'        => $p->get_name(),
            'thumb'       => wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src(),
            'price'       => $addon_price > 0 ? $addon_price : $own_price,
            'was'         => ($addon_price > 0 && $addon_price < $own_price) ? $own_price : 0,
            'addon_price' => $addon_price,
            'type'        => $p->is_type('variable') ? 'variable' : 'simple',
            'options'     => [],
        ];

        if ('variable' === $base['type']) {
            $opts = [];
            foreach ($p->get_children() as $cid) {
                $v = wc_get_product($cid);
                if (!$v || !$v->is_purchasable() || !$v->is_in_stock()) continue;
                $labels = [];
                foreach ($v->get_variation_attributes() as $ak => $av) {
                    if ('' === $av) continue;
                    $key = 0 === strpos($ak, 'attribute_') ? substr($ak, strlen('attribute_')) : $ak;
                    if (taxonomy_exists($key)) {
                        $term = get_term_by('slug', $av, $key);
                        $labels[] = $term && !is_wp_error($term) ? $term->name : $av;
                    } else {
                        $labels[] = $av;
                    }
                }
                $own_img = $v->get_image_id('edit');
                $opts[] = [
                    'id'    => $cid,
                    'label' => $labels ? implode(' / ', $labels) : ('#' . $cid),
                    'price' => (float) wc_get_price_to_display($v),
                    'thumb' => $own_img ? (wp_get_attachment_image_url($own_img, 'woocommerce_thumbnail') ?: '') : '',
                ];
            }
            if (!$opts) return null;
            if ($addon_price > 0) {
                foreach ($opts as $i => $o) $opts[$i]['price'] = $addon_price;
            }
            $base['options'] = $opts;
            $base['price']   = min(wp_list_pluck($opts, 'price'));
        }

        return $base;
    }

    private static function enqueue($groups, $is_case = true) {
        wp_enqueue_style('galado-addons', GALADO_BUNDLES_URL . 'public/addons.css', [], GALADO_BUNDLES_VERSION);
        // The staged-Buy-Now bar belongs to case pages only; non-case shelves
        // add straight to the basket through the per-item endpoint (the JS
        // falls back automatically when GALADO_PWP is absent).
        $deps = [];
        if ($is_case) {
            self::enqueue_bar();
            $deps = ['galado-pwp-bar'];
        }
        wp_enqueue_script('galado-addons', GALADO_BUNDLES_URL . 'public/addons.js', $deps, GALADO_BUNDLES_VERSION, true);
        wp_localize_script('galado-addons', 'GALADO_ADDONS', [
            'ajax'     => class_exists('WC_AJAX') ? WC_AJAX::get_endpoint('galado_addon_add') : '',
            'state_url' => class_exists('WC_AJAX') ? WC_AJAX::get_endpoint('galado_pwp_state') : '',
            'groups'  => $groups,
            'preview' => !galado_bundles_can_transact(),
            'hide'    => galado_bundles_wcpa_hide_keys_addons(),
            'i18n'    => [
                'add'     => __('Add +', 'galado-bundles'),
                'added'   => __('Added', 'galado-bundles'),
                'adding'  => __('Adding...', 'galado-bundles'),
                'pick'    => __('Choose an option first', 'galado-bundles'),
                'preview' => __('Preview mode. Turn the storefront on to enable adds.', 'galado-bundles'),
                'failed'  => __('Could not add it, please try again.', 'galado-bundles'),
                'added_lbl'   => __('added', 'galado-bundles'),
                'add_basket'  => __('Add to Basket', 'galado-bundles'),
                'you_saved'   => __('You saved', 'galado-bundles'),
            ],
        ]);
    }

    /** The PWP summary in the sticky Buy Now bar (owner 2026-08-04, Casetify
     * reference): the decorator script takes over snippet #7's info column
     * with "N items" + combined price once there is something to say. Enqueued
     * by whichever module renders first; localized once. Only loads when a
     * module renders, so while dark this never reaches customers. */
    public static function enqueue_bar() {
        if (wp_script_is('galado-pwp-bar', 'enqueued')) return;
        wp_enqueue_style('galado-pwp-bar', GALADO_BUNDLES_URL . 'public/pwp-bar.css', [], GALADO_BUNDLES_VERSION);
        wp_enqueue_script('galado-pwp-bar', GALADO_BUNDLES_URL . 'public/pwp-bar.js', [], GALADO_BUNDLES_VERSION, true);
        wp_localize_script('galado-pwp-bar', 'GALADO_PWP_BAR', [
            'state_url'    => class_exists('WC_AJAX') ? WC_AJAX::get_endpoint('galado_pwp_state') : '',
            'checkout_url' => class_exists('WC_AJAX') ? WC_AJAX::get_endpoint('galado_pwp_checkout') : '',
            'i18n'         => [
                'items'         => __('items', 'galado-bundles'),
                'item'          => __('item', 'galado-bundles'),
                'discount'      => __('Discount', 'galado-bundles'),
                'pick_case'     => __('Please select your case model and colour first.', 'galado-bundles'),
                'adding'        => __('Adding...', 'galado-bundles'),
                'failed'        => __('Could not add to basket, please try again.', 'galado-bundles'),
                'combo_dropped' => __('Protection set removed because the model changed. Pick it again below.', 'galado-bundles'),
            ],
        ]);
    }

    /** Server-rendered, cacheable, hydrated by addons.js. Height reserved. */
    private static function markup($group) {
        ob_start(); ?>
        <section class="gld-addons" data-group="<?php echo esc_attr($group['slug']); ?>" aria-label="<?php echo esc_attr($group['title']); ?>">
          <h3 class="gld-addons__head"><?php echo esc_html($group['title']); ?></h3>
          <div class="gld-addons__row" role="list">
            <?php foreach ($group['items'] as $it) : ?>
            <article class="gld-addon" role="listitem" data-key="<?php echo esc_attr($it['key']); ?>" data-type="<?php echo esc_attr($it['type']); ?>">
              <span class="gld-addon__circle"><img src="<?php echo esc_url($it['thumb']); ?>" alt="" loading="lazy" width="72" height="72"></span>
              <span class="gld-addon__name"><?php echo esc_html($it['name']); ?></span>
              <?php if (!empty($it['varies'])) : ?>
              <span class="gld-addon__price"><?php esc_html_e('From', 'galado-bundles'); ?> RM<?php echo esc_html(self::rm($it['price'])); ?></span>
              <?php else : ?>
              <span class="gld-addon__price">+RM<?php echo esc_html(self::rm($it['price'])); ?><?php if (!empty($it['was'])) : ?> <s>RM<?php echo esc_html(self::rm($it['was'])); ?></s><?php endif; ?></span>
              <?php endif; ?>
              <button type="button" class="gld-addon__add" data-gld-addon-add disabled><?php esc_html_e('Add +', 'galado-bundles'); ?></button>
            </article>
            <?php endforeach; ?>
          </div>
          <div class="gld-addons__opts" data-gld-opts hidden></div>
          <p class="gld-addons__note" data-gld-note aria-live="polite"></p>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function rm($n) {
        $s = number_format((float) $n, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    // ---- add to cart --------------------------------------------------------

    public static function ajax_add() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!galado_bundles_can_transact()) {
            wp_send_json(['ok' => false, 'message' => __('Preview mode. Turn the storefront on to enable adds.', 'galado-bundles')]);
        }

        $pid = isset($_REQUEST['product_id']) ? (int) $_REQUEST['product_id'] : 0;
        $vid = isset($_REQUEST['variation_id']) ? (int) $_REQUEST['variation_id'] : 0;

        // Only products curated into an active add-on group are addable here;
        // this endpoint is not a generic add-to-cart.
        $claimed = [];
        $res = self::add_addon_line($pid, $vid, $claimed);
        if (!$res['ok']) {
            wp_send_json(['ok' => false, 'message' => $res['message']]);
        }
        self::respond_added(['reused' => $res['reused'] ? 1 : 0]);
    }

    // ---- launch seed --------------------------------------------------------

    /** Idempotent: the starter Accessories shelf (owner 2026-08-04), ordered
     * hot-sellers first from verified live products. Draft; ops publishes. */
    public static function seed_accessories_group() {
        $slug = 'addons-accessories';

        // The confirmed line-up (owner 2026-08-04): the old WCPA rows mapped
        // to real standalone products. Rows sharing a label render as ONE
        // circle with those products as options at the with-case price.
        //   Mini Phone Charm  RM55 (own RM69)  10 charm products
        //   360 Ultra Slim Card RM35 (own price, colour variants)
        //   Mini Wrist Strap  RM59 (own RM75)  8 colours
        //   Crossbody Strap (7mm) RM79 (own RM89)  5 colours
        //   Cloud MagSafe Grip RM55 (own RM69)
        $lineup = [
            [326853, 55.0, 'Mini Phone Charm'],
            [316138, 55.0, 'Mini Phone Charm'],
            [287236, 55.0, 'Mini Phone Charm'],
            [249931, 55.0, 'Mini Phone Charm'],
            [281857, 55.0, 'Mini Phone Charm'],
            [317569, 55.0, 'Mini Phone Charm'],
            [305025, 55.0, 'Mini Phone Charm'],
            [301438, 55.0, 'Mini Phone Charm'],
            [313944, 55.0, 'Mini Phone Charm'],
            [287759, 55.0, 'Mini Phone Charm'],
            [317620, 0.0,  ''],
            [321013, 59.0, 'Mini Wrist Strap'],
            [321238, 59.0, 'Mini Wrist Strap'],
            [331862, 59.0, 'Mini Wrist Strap'],
            [331868, 59.0, 'Mini Wrist Strap'],
            [331875, 59.0, 'Mini Wrist Strap'],
            [329812, 59.0, 'Mini Wrist Strap'],
            [329212, 59.0, 'Mini Wrist Strap'],
            [329172, 59.0, 'Mini Wrist Strap'],
            [384007, 79.0, 'Crossbody Strap (7mm)'],
            [384044, 79.0, 'Crossbody Strap (7mm)'],
            [384065, 79.0, 'Crossbody Strap (7mm)'],
            [404021, 79.0, 'Crossbody Strap (7mm)'],
            [288133, 79.0, 'Crossbody Strap (7mm)'],
            [277284, 55.0, 'MagSafe Grip'],
            [316373, 55.0, 'MagSafe Grip'],
            [316200, 55.0, 'MagSafe Grip'],
            [316192, 55.0, 'MagSafe Grip'],
            [315788, 55.0, 'MagSafe Grip'],
            [315780, 55.0, 'MagSafe Grip'],
            [314178, 55.0, 'MagSafe Grip'],
        ];

        $items = [];
        foreach ($lineup as $n => $row) {
            $pid = $row[0];
            $p = wc_get_product($pid);
            if (!$p || 'publish' !== $p->get_status()) continue;
            $items[] = [
                'slot'                 => 'addon' . $n,
                'product_id'           => (int) $pid,
                'line_type'            => $p->is_type('variable') ? 'variable' : 'simple',
                'qty'                  => 1,
                'variation_mode'       => $p->is_type('variable') ? 'shopper_choice' : 'fixed',
                'default_variation_id' => 0,
                'match_attrs'          => [],
                'addon_price'          => (float) $row[1],
                'label'                => (string) $row[2],
                'name_cache'           => $p->get_name(),
                'price_cache'          => (float) wc_get_price_to_display($p),
            ];
        }
        if (!$items) return 0;

        // Audiences (owner r12): all case pages PLUS charm pages and the
        // Stylink product (at normal prices there). MacBook never qualifies.
        $audience = [
            'show_on_cases' => '1',
            'audience_cats' => 'phone-charm,bag-charm',
            'audience_ids'  => '389955',
        ];

        $existing = get_page_by_path($slug, OBJECT, GALADO_BUNDLES_CPT);
        if ($existing) {
            // Idempotent line-up sync: the seed button re-asserts the canonical
            // shelf; staff tweaks beyond it live in wp-admin edits afterwards.
            update_post_meta($existing->ID, GALADO_BUNDLES_META . 'items', wp_json_encode($items));
            foreach ($audience as $k => $v) update_post_meta($existing->ID, GALADO_BUNDLES_META . $k, $v);
            do_action('galado_bundles_changed', [$existing->ID]);
            return 0;
        }

        $post_id = wp_insert_post([
            'post_type'   => GALADO_BUNDLES_CPT,
            'post_status' => 'draft',
            'post_title'  => 'Complete your setup',
            'post_name'   => $slug,
            'menu_order'  => 0,
        ]);
        if (!$post_id || is_wp_error($post_id)) return 0;

        update_post_meta($post_id, GALADO_BUNDLES_META . 'items', wp_json_encode($items));
        update_post_meta($post_id, GALADO_BUNDLES_META . 'addon_group', '1');
        update_post_meta($post_id, GALADO_BUNDLES_META . 'combo', '0');
        update_post_meta($post_id, GALADO_BUNDLES_META . 'featured', '0');
        update_post_meta($post_id, GALADO_BUNDLES_META . 'save', 0);
        update_post_meta($post_id, GALADO_BUNDLES_META . 'combo_price', 0);
        update_post_meta($post_id, GALADO_BUNDLES_META . 'mode', 'link');
        foreach ($audience as $k => $v) update_post_meta($post_id, GALADO_BUNDLES_META . $k, $v);
        return 1;
    }

    /** Idempotent: the Stylink Clip-On shelf (owner r12): one circle holding
     * every clip-on charm at its own price, shown ONLY on the Stylink
     * product page (id targeting - Stylink has no category of its own). */
    public static function seed_clipons_group() {
        $slug = 'addons-clipons';
        $pids = [
            408078, 408067, 404402, 404401, 402574, 402573, 398828, 398827,
            393088, 392630, 392545, 392476, 390788, 390738, 390737, 390736,
            390715, 390706, 390697, 390688, 390679,
        ];
        $items = [];
        foreach ($pids as $n => $pid) {
            $p = wc_get_product($pid);
            if (!$p || 'publish' !== $p->get_status()) continue;
            $items[] = [
                'slot'                 => 'clipon' . $n,
                'product_id'           => (int) $pid,
                'line_type'            => $p->is_type('variable') ? 'variable' : 'simple',
                'qty'                  => 1,
                'variation_mode'       => $p->is_type('variable') ? 'shopper_choice' : 'fixed',
                'default_variation_id' => 0,
                'match_attrs'          => [],
                'addon_price'          => 0.0,
                'label'                => 'Clip-On Charm',
                'name_cache'           => $p->get_name(),
                'price_cache'          => (float) wc_get_price_to_display($p),
            ];
        }
        if (!$items) return 0;

        $audience = ['show_on_cases' => '0', 'audience_cats' => '', 'audience_ids' => '389955'];
        $existing = get_page_by_path($slug, OBJECT, GALADO_BUNDLES_CPT);
        if ($existing) {
            update_post_meta($existing->ID, GALADO_BUNDLES_META . 'items', wp_json_encode($items));
            foreach ($audience as $k => $v) update_post_meta($existing->ID, GALADO_BUNDLES_META . $k, $v);
            do_action('galado_bundles_changed', [$existing->ID]);
            return 0;
        }

        $post_id = wp_insert_post([
            'post_type'   => GALADO_BUNDLES_CPT,
            'post_status' => 'draft',
            'post_title'  => 'Clip-On Charms',
            'post_name'   => $slug,
            'menu_order'  => 0,
        ]);
        if (!$post_id || is_wp_error($post_id)) return 0;

        update_post_meta($post_id, GALADO_BUNDLES_META . 'items', wp_json_encode($items));
        update_post_meta($post_id, GALADO_BUNDLES_META . 'addon_group', '1');
        update_post_meta($post_id, GALADO_BUNDLES_META . 'combo', '0');
        update_post_meta($post_id, GALADO_BUNDLES_META . 'featured', '0');
        update_post_meta($post_id, GALADO_BUNDLES_META . 'save', 0);
        update_post_meta($post_id, GALADO_BUNDLES_META . 'combo_price', 0);
        update_post_meta($post_id, GALADO_BUNDLES_META . 'mode', 'link');
        foreach ($audience as $k => $v) update_post_meta($post_id, GALADO_BUNDLES_META . $k, $v);
        return 1;
    }
}
