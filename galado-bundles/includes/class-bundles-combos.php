<?php
/**
 * PDP protector combos (ADDON-COMBOS-SPEC): the "Protect your [model]" module
 * on phone-case product pages, model-aware, mobile-first.
 *
 * Render rules (spec section 3):
 *  - case PDPs only: the product varies by pa_model, sits in a device/prints
 *    category tree, and is not itself a combo component or an excluded product;
 *  - a combo card exists for a model only when EVERY component has a published,
 *    purchasable, in-stock variation for that model (or is model-agnostic);
 *  - all cards empty for every model -> the module does not render at all.
 *
 * Reality check against the live catalogue (2026-08-04): the G-Armor lens
 * protector also varies by phone COLOUR, so a pure one-tap add would guess the
 * customer's phone finish. Cards with leftover axes (colour) show a compact
 * swatch row once the model is known; Add stays gated until resolved.
 *
 * The server owns all resolution: the client sends (combo, model slug, extra
 * attribute choices) and every variation id is looked up and validated here.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Combos {

    /** Rendered-once guard: the module hooks several placements because theme
     * and builder layouts differ in which hooks they actually fire. */
    private static $rendered = false;

    public static function init() {
        // Primary: directly below the variations form. Fallbacks: the summary
        // hook after the add-to-cart block (fires on virtually every Woo
        // theme), then after the whole summary. First one that fires wins.
        add_action('woocommerce_after_add_to_cart_form', [__CLASS__, 'render']);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render'], 39);
        add_action('woocommerce_after_single_product_summary', [__CLASS__, 'render'], 5);
        add_action('wc_ajax_galado_combo_add', [__CLASS__, 'ajax_add']);
        add_action('wc_ajax_nopriv_galado_combo_add', [__CLASS__, 'ajax_add']);
    }

    /** Customers need both flags; staff preview while dark (same gate as the
     * home band, so the whole surface can be reviewed before cutover). */
    private static function visible() {
        if (galado_bundles_storefront_enabled() && galado_bundles_combos_enabled()) return true;
        return galado_bundles_combos_enabled() && GALADO_Bundles_Storefront::can_preview();
    }

    // ---- PDP gate -----------------------------------------------------------

    /** Is this product a phone-case PDP the module belongs on? */
    public static function is_case_pdp($product) {
        if (!$product || !$product->is_type('variable')) return false;
        $pid = $product->get_id();
        if (in_array($pid, galado_bundles_excluded_products(), true)) return false;

        // Never on a page that IS one of the combo components.
        foreach (GALADO_Bundles_Data::get_combos() as $combo) {
            foreach ($combo['items'] as $it) {
                if ((int) $it['product_id'] === $pid) return false;
            }
        }

        // Must vary by phone model.
        if (!array_key_exists('pa_model', $product->get_variation_attributes())) return false;

        // Category screen: protectors and other non-case surfaces are excluded
        // by slug; device/prints categories are allowed. Both lists filterable.
        $blocked = apply_filters('galado_bundles_combo_blocked_cats', [
            'camera-lens-protector', 'screen-protector', 'tempered-glass', 'accessories',
        ]);
        $terms = get_the_terms($pid, 'product_cat');
        $slugs = [];
        foreach ((array) $terms as $t) {
            if ($t instanceof WP_Term) $slugs[] = $t->slug;
        }
        if (array_intersect($slugs, (array) $blocked)) return false;

        return (bool) apply_filters('galado_bundles_is_case_pdp', true, $product, $slugs);
    }

    // ---- model matching -----------------------------------------------------

    /**
     * All purchasable variations of a component that fit a model, honouring the
     * item's pinned match_attrs. A variation with no pa_model (or the "any"
     * wildcard) is model-agnostic and fits every model.
     * Returns [variation_id => leftover-attrs map] where leftover attrs are the
     * axes still unresolved after model + pins (e.g. G-Armor colour).
     */
    public static function fitting_variations($item, $model_slug) {
        $parent = wc_get_product((int) $item['product_id']);
        if (!$parent || !$parent->is_type('variable')) return [];

        $pins = [];
        foreach (($item['match_attrs'] ?? []) as $k => $v) {
            $pins[strtolower(self::attr_key($k))] = strtolower((string) $v);
        }

        $out = [];
        foreach ($parent->get_children() as $cid) {
            $v = wc_get_product($cid);
            if (!$v || !$v->is_purchasable() || !$v->is_in_stock()) continue;

            $attrs = $v->get_variation_attributes(); // attribute_pa_model => slug
            $leftover = [];
            $ok = true;
            foreach ($attrs as $ak => $av) {
                $key = strtolower(self::attr_key($ak));
                $val = strtolower((string) $av);
                if ('pa_model' === $key) {
                    if ('' !== $val && $val !== strtolower($model_slug)) { $ok = false; break; }
                    continue;
                }
                if (isset($pins[$key])) {
                    if ('' !== $val && $val !== $pins[$key]) { $ok = false; break; }
                    continue;
                }
                if ('' === $val) continue; // any-wildcard: not a real leftover axis
                $leftover[self::attr_key($ak)] = (string) $av;
            }
            if ($ok) $out[$cid] = $leftover;
        }
        return $out;
    }

    private static function attr_key($k) {
        return 0 === strpos($k, 'attribute_') ? substr($k, strlen('attribute_')) : $k;
    }

    /**
     * The per-model map for one combo on one PDP:
     * [model_slug => ['ok'=>bool,'sum'=>float,'save'=>float,'total'=>float,
     *                 'axes'=>[slot=>[attr=>[value=>label]]]]]
     */
    public static function combo_model_map($combo, $models) {
        $map = [];
        foreach ($models as $slug => $label) {
            $sum = 0.0; $ok = true; $axes = [];
            foreach ($combo['items'] as $it) {
                $qty = max(1, (int) $it['qty']);
                if ('variable' !== $it['line_type']) {
                    $p = wc_get_product((int) $it['product_id']);
                    if (!$p || !$p->is_purchasable() || !$p->is_in_stock()) { $ok = false; break; }
                    $sum += (float) wc_get_price_to_display($p) * $qty;
                    continue;
                }
                if ('model_match' !== $it['variation_mode']) {
                    // pinned inside a combo: use the pinned variation as-is
                    $v = !empty($it['default_variation_id']) ? wc_get_product((int) $it['default_variation_id']) : null;
                    if (!$v || !$v->is_purchasable() || !$v->is_in_stock()) { $ok = false; break; }
                    $sum += (float) wc_get_price_to_display($v) * $qty;
                    continue;
                }
                $fits = self::fitting_variations($it, $slug);
                if (!$fits) { $ok = false; break; }
                // Price from the cheapest fitting variation (they are uniform in
                // practice); leftover axes become card swatches.
                $prices = [];
                $leftover_axes = [];
                foreach ($fits as $vid => $leftover) {
                    $v = wc_get_product($vid);
                    if ($v) $prices[] = (float) wc_get_price_to_display($v);
                    foreach ($leftover as $ak => $av) {
                        $leftover_axes[$ak][$av] = self::attr_label($ak, $av);
                    }
                }
                $sum += ($prices ? min($prices) : 0.0) * $qty;
                if ($leftover_axes) $axes[$it['slot']] = $leftover_axes;
            }

            if (!$ok) { $map[$slug] = ['ok' => false]; continue; }

            $combo_price = (float) $combo['combo_price'];
            $save = ($combo_price > 0 && $combo_price < $sum) ? $sum - $combo_price : (float) $combo['save'];
            if ($save >= $sum) $save = 0.0;
            $map[$slug] = [
                'ok'    => true,
                'sum'   => round($sum, 2),
                'save'  => round($save, 2),
                'total' => round($sum - $save, 2),
                'axes'  => $axes,
            ];
        }
        return $map;
    }

    private static function attr_label($attr_key, $value) {
        if (taxonomy_exists($attr_key)) {
            $term = get_term_by('slug', $value, $attr_key);
            if ($term && !is_wp_error($term)) return $term->name;
        }
        return $value;
    }

    // ---- render -------------------------------------------------------------

    public static function render() {
        if (self::$rendered) return;
        if (!self::visible()) return;
        if (!function_exists('is_product') || !is_product()) return;

        global $product;
        $data = self::page_data($product);
        if (!$data) return;

        self::$rendered = true;
        self::enqueue($data['models'], $data['cards']);
        echo self::markup($data['cards']);
    }

    /**
     * Everything the module needs for one PDP: the model universe and the
     * per-combo cards with per-model maps, exclusivity already applied.
     * Shared by render() and the admin probe route. Returns null when the
     * module does not belong on this product.
     */
    public static function page_data($product) {
        if (!self::is_case_pdp($product)) return null;

        $combos = GALADO_Bundles_Data::get_combos();
        if (!$combos) return null;

        // The PDP's own model terms are the model universe for this page.
        $models = [];
        $attrs = $product->get_variation_attributes();
        foreach ((array) ($attrs['pa_model'] ?? []) as $slug) {
            $term = get_term_by('slug', $slug, 'pa_model');
            $models[$slug] = $term && !is_wp_error($term) ? $term->name : $slug;
        }
        if (!$models) return null;

        // Build every combo's per-model map first, then apply the mutual
        // exclusivity rule (spec section 2): a FALLBACK combo is hidden for any
        // model where a fuller non-fallback combo (whose components are a
        // superset of the fallback's) is available. Today: glass + G-Armor
        // shows only where the plateau combo cannot; when plateau coverage
        // expands, pages flip to the full set automatically, no config change.
        $built = [];
        foreach ($combos as $combo) {
            $built[] = ['combo' => $combo, 'map' => self::combo_model_map($combo, $models)];
        }
        foreach ($built as $i => $entry) {
            if (empty($entry['combo']['combo_fallback'])) continue;
            $mine = wp_list_pluck($entry['combo']['items'], 'product_id');
            foreach ($built as $j => $other) {
                if ($i === $j || !empty($other['combo']['combo_fallback'])) continue;
                $theirs = wp_list_pluck($other['combo']['items'], 'product_id');
                if (array_diff($mine, $theirs)) continue; // not a superset of us
                foreach ($entry['map'] as $slug => $m) {
                    if (!empty($m['ok']) && !empty($other['map'][$slug]['ok'])) {
                        $built[$i]['map'][$slug] = ['ok' => false];
                    }
                }
            }
        }

        $cards = [];
        foreach ($built as $entry) {
            $combo = $entry['combo'];
            $map   = $entry['map'];
            $any = false;
            foreach ($map as $m) { if (!empty($m['ok'])) { $any = true; break; } }
            if (!$any) continue; // never show an un-addable combo (spec section 3)

            $thumbs = [];
            $names  = [];
            foreach ($combo['items'] as $it) {
                $p = wc_get_product((int) $it['product_id']);
                if (!$p) continue;
                $names[] = $p->get_name();
                $thumbs[] = wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src();
            }

            $cards[] = [
                'slug'     => $combo['slug'],
                'title'    => $combo['title'],
                'fallback' => !empty($combo['combo_fallback']),
                'names'    => $names,
                'thumbs'   => $thumbs,
                'models'   => $map,
            ];
        }
        if (!$cards) return null;

        return ['models' => $models, 'cards' => $cards];
    }

    /** Admin probe (ops REST): the module exactly as it would render on this
     * product, regardless of the visibility toggles, for dark verification. */
    public static function probe($product) {
        $data = self::page_data($product);
        if (!$data) return ['is_case_pdp' => self::is_case_pdp($product), 'renders' => false];
        return [
            'is_case_pdp' => true,
            'renders'     => true,
            'models'      => $data['models'],
            'cards'       => array_map(function ($c) {
                return [
                    'slug' => $c['slug'], 'title' => $c['title'], 'fallback' => $c['fallback'],
                    'names' => $c['names'], 'models' => $c['models'],
                ];
            }, $data['cards']),
            'html'        => self::markup($data['cards']),
        ];
    }

    private static function enqueue($models, $cards) {
        wp_enqueue_style('galado-combos', GALADO_BUNDLES_URL . 'public/combos.css', [], GALADO_BUNDLES_VERSION);
        wp_enqueue_script('galado-combos', GALADO_BUNDLES_URL . 'public/combos.js', [], GALADO_BUNDLES_VERSION, true);
        wp_localize_script('galado-combos', 'GALADO_COMBOS', [
            'ajax'    => class_exists('WC_AJAX') ? WC_AJAX::get_endpoint('galado_combo_add') : '',
            'models'  => $models,
            'cards'   => wp_list_pluck($cards, 'models', 'slug'),
            'preview' => !galado_bundles_can_transact(),
            'hide'    => galado_bundles_wcpa_hide_keys(),
            'i18n'    => [
                'pick_model' => __('Select your model first', 'galado-bundles'),
                'pick_axis'  => __('Choose your option above first', 'galado-bundles'),
                'added'      => __('Added', 'galado-bundles'),
                'adding'     => __('Adding...', 'galado-bundles'),
                'preview'    => __('Preview mode. Turn the storefront on to enable adds.', 'galado-bundles'),
                'failed'     => __('Could not add the set, please try again.', 'galado-bundles'),
                'na'         => __('Not available for this model', 'galado-bundles'),
            ],
        ]);
    }

    /** Server-rendered shell: cacheable, identical for every visitor, hydrated
     * by JS off the variation events. Height reserved so injection causes no
     * layout shift (acceptance 7). */
    private static function markup($cards) {
        ob_start(); ?>
        <section class="gld-protect" id="gld-protect" aria-label="<?php esc_attr_e('Protector combos', 'galado-bundles'); ?>">
          <h3 class="gld-protect__head"><?php esc_html_e('Protect your', 'galado-bundles'); ?> <span data-gld-model><?php esc_html_e('phone', 'galado-bundles'); ?></span></h3>
          <div class="gld-protect__row" role="list">
            <?php foreach ($cards as $c) : ?>
            <article class="gld-combo" role="listitem" data-combo="<?php echo esc_attr($c['slug']); ?>"<?php echo $c['fallback'] ? ' data-fallback="1" hidden' : ''; ?>>
              <span class="gld-combo__chip" data-gld-chip hidden></span>
              <div class="gld-combo__imgs">
                <?php foreach (array_slice($c['thumbs'], 0, 3) as $t) : ?>
                  <img src="<?php echo esc_url($t); ?>" alt="" loading="lazy" width="64" height="64">
                <?php endforeach; ?>
              </div>
              <h4 class="gld-combo__name"><?php echo esc_html($c['title']); ?></h4>
              <p class="gld-combo__list"><?php echo esc_html(implode(' + ', $c['names'])); ?></p>
              <div class="gld-combo__axes" data-gld-axes></div>
              <p class="gld-combo__price"><b class="now" data-gld-now></b> <s class="was" data-gld-was aria-hidden="true"></s></p>
              <button type="button" class="gld-combo__cta" data-gld-add disabled><?php esc_html_e('Add set +', 'galado-bundles'); ?></button>
              <p class="gld-combo__note" data-gld-note aria-live="polite"></p>
            </article>
            <?php endforeach; ?>
          </div>
        </section>
        <?php
        return ob_get_clean();
    }

    // ---- add to cart --------------------------------------------------------

    /** wc-ajax: resolve every component for (combo, model, extra choices)
     * server-side, then hand the validated plan to the shared bundle adder. */
    public static function ajax_add() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!galado_bundles_can_transact()) {
            wp_send_json(['ok' => false, 'message' => __('Preview mode. Turn the storefront on to enable adds.', 'galado-bundles')]);
        }

        $slug  = isset($_REQUEST['combo']) ? sanitize_title(wp_unslash($_REQUEST['combo'])) : '';
        $model = isset($_REQUEST['model']) ? sanitize_title(wp_unslash($_REQUEST['model'])) : '';
        $extra = [];
        if (!empty($_REQUEST['extra']) && is_array($_REQUEST['extra'])) {
            foreach (wp_unslash($_REQUEST['extra']) as $slot => $pairs) {
                if (!is_array($pairs)) continue;
                foreach ($pairs as $ak => $av) {
                    $extra[sanitize_key($slot)][sanitize_text_field($ak)] = sanitize_text_field($av);
                }
            }
        }

        $desc = GALADO_Bundles_Data::get($slug);
        if (!$desc || empty($desc['combo']) || 'publish' !== $desc['status'] || '' === $model) {
            wp_send_json(['ok' => false, 'message' => __('That combo is not available.', 'galado-bundles')]);
        }

        $selections = [];
        foreach ($desc['items'] as $it) {
            if ('variable' !== $it['line_type'] || 'model_match' !== $it['variation_mode']) continue;
            $vid = self::pick_variation($it, $model, $extra[$it['slot']] ?? []);
            if (!$vid) {
                wp_send_json(['ok' => false, 'message' => __('Not available for your model.', 'galado-bundles')]);
            }
            $selections[$it['slot']] = $vid;
        }

        $res = GALADO_Bundles_Cart::add_bundle($desc, $selections);
        if (!$res['ok']) {
            wp_send_json(['ok' => false, 'message' => $res['message']]);
        }
        WC_AJAX::get_refreshed_fragments();
    }

    /** One concrete variation for a model_match item: fits the model and pins,
     * and matches every extra axis choice the shopper made (colour). Exactly
     * one leftover-axis combination must remain, otherwise the choice is
     * incomplete and the add is refused rather than guessed. */
    private static function pick_variation($item, $model_slug, $extra_choices) {
        $fits = self::fitting_variations($item, $model_slug);
        if (!$fits) return 0;

        $choices = [];
        foreach ((array) $extra_choices as $k => $v) {
            $choices[strtolower(self::attr_key($k))] = strtolower((string) $v);
        }

        $matched = [];
        foreach ($fits as $vid => $leftover) {
            $ok = true;
            foreach ($leftover as $ak => $av) {
                $key = strtolower(self::attr_key($ak));
                if (!isset($choices[$key]) || $choices[$key] !== strtolower((string) $av)) { $ok = false; break; }
            }
            if ($ok) $matched[] = $vid;
        }
        return 1 === count($matched) ? (int) $matched[0] : 0;
    }

    // ---- launch seed --------------------------------------------------------

    /** Idempotent: creates the four combos CONFIRMED in ADDON-COMBOS-SPEC
     * section 2 (2026-08-04) as drafts if absent; the owner publishes.
     * Combo 4 is the fallback for models with no plateau SKU and is mutually
     * exclusive with combos 1/3 via the fallback rule. */
    public static function seed_launch_combos() {
        $plateau_pin = ['option' => 'Camera Plateau Protector ONLY'];
        $seeds = [
            [
                'slug'  => 'combo-protect-complete',
                'title' => 'Complete Protection',
                'price' => 110.00,
                'items' => [
                    ['product_id' => 40884,  'mode' => 'model_match', 'match' => []],
                    ['product_id' => 229654, 'mode' => 'model_match', 'match' => []],
                    ['product_id' => 401981, 'mode' => 'model_match', 'match' => $plateau_pin],
                ],
            ],
            [
                'slug'  => 'combo-protect-screen',
                'title' => 'Screen Protection',
                'price' => 55.00,
                'items' => [
                    ['product_id' => 40884, 'mode' => 'model_match', 'match' => []],
                ],
            ],
            [
                'slug'  => 'combo-protect-camera',
                'title' => 'Camera Protection',
                'price' => 75.00,
                'items' => [
                    ['product_id' => 229654, 'mode' => 'model_match', 'match' => []],
                    ['product_id' => 401981, 'mode' => 'model_match', 'match' => $plateau_pin],
                ],
            ],
            [
                'slug'     => 'combo-protect-screen-lens',
                'title'    => 'Screen & Lens Protection',
                'price'    => 99.00,
                'fallback' => true,
                'items' => [
                    ['product_id' => 40884,  'mode' => 'model_match', 'match' => []],
                    ['product_id' => 229654, 'mode' => 'model_match', 'match' => []],
                ],
            ],
        ];

        $made = 0;
        foreach ($seeds as $i => $seed) {
            if (get_page_by_path($seed['slug'], OBJECT, GALADO_BUNDLES_CPT)) continue;

            $items = [];
            foreach ($seed['items'] as $n => $line) {
                $p = wc_get_product($line['product_id']);
                if (!$p) continue;
                $items[] = [
                    'slot'                 => 'combo' . $n,
                    'product_id'           => (int) $line['product_id'],
                    'line_type'            => $p->is_type('variable') ? 'variable' : 'simple',
                    'qty'                  => 1,
                    'variation_mode'       => $line['mode'],
                    'default_variation_id' => 0,
                    'match_attrs'          => $line['match'],
                    'label'                => '',
                    'name_cache'           => $p->get_name(),
                    'price_cache'          => (float) wc_get_price_to_display($p),
                ];
            }
            if (!$items) continue;

            $post_id = wp_insert_post([
                'post_type'   => GALADO_BUNDLES_CPT,
                'post_status' => 'draft',
                'post_title'  => $seed['title'],
                'post_name'   => $seed['slug'],
                'menu_order'  => $i,
            ]);
            if (!$post_id || is_wp_error($post_id)) continue;

            update_post_meta($post_id, GALADO_BUNDLES_META . 'items', wp_json_encode($items));
            update_post_meta($post_id, GALADO_BUNDLES_META . 'combo', '1');
            update_post_meta($post_id, GALADO_BUNDLES_META . 'combo_fallback', empty($seed['fallback']) ? '0' : '1');
            update_post_meta($post_id, GALADO_BUNDLES_META . 'combo_price', $seed['price']);
            update_post_meta($post_id, GALADO_BUNDLES_META . 'fee_label', 'Protect Set');
            update_post_meta($post_id, GALADO_BUNDLES_META . 'featured', '0');
            update_post_meta($post_id, GALADO_BUNDLES_META . 'save', 0);
            update_post_meta($post_id, GALADO_BUNDLES_META . 'mode', 'click');
            $made++;
        }
        return $made;
    }
}
