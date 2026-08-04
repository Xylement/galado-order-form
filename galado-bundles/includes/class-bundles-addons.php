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
        // Same three placements as the combos module, one priority later, so
        // whichever hook the theme fires, combos render first, this below.
        add_action('woocommerce_after_add_to_cart_form', [__CLASS__, 'render'], 12);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'render'], 40);
        add_action('woocommerce_after_single_product_summary', [__CLASS__, 'render'], 6);
        add_action('wc_ajax_galado_addon_add', [__CLASS__, 'ajax_add']);
        add_action('wc_ajax_nopriv_galado_addon_add', [__CLASS__, 'ajax_add']);
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
        self::enqueue($groups);
        foreach ($groups as $g) echo self::markup($g);
    }

    /** Shelves for this PDP, or null. Same case gate as the combos module. */
    public static function page_groups($product) {
        if (!GALADO_Bundles_Combos::is_case_pdp($product)) return null;

        $out = [];
        foreach (GALADO_Bundles_Data::get_addon_groups() as $group) {
            $items = [];
            foreach ($group['items'] as $it) {
                $item = self::item_data($it, $product->get_id());
                if ($item) $items[] = $item;
            }
            if ($items) {
                $out[] = ['slug' => $group['slug'], 'title' => $group['title'], 'items' => $items];
            }
        }
        return $out ?: null;
    }

    /** One shelf item: display data plus, for variable products, the option
     * circles (own-image chips when every variation has its own image, colour
     * dots otherwise; labels always carried for a11y). */
    private static function item_data($it, $current_pid) {
        $pid = (int) $it['product_id'];
        if ($pid === (int) $current_pid) return null; // never sell the page to itself
        $p = wc_get_product($pid);
        if (!$p || 'publish' !== $p->get_status() || !$p->is_purchasable() || !$p->is_in_stock()) return null;

        $base = [
            'product_id' => $pid,
            'name'       => $p->get_name(),
            'thumb'      => wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src(),
            'price'      => (float) wc_get_price_to_display($p),
            'type'       => $p->is_type('variable') ? 'variable' : 'simple',
            'options'    => [],
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
            $base['options'] = $opts;
            $base['price']   = min(wp_list_pluck($opts, 'price'));
        }

        return $base;
    }

    private static function enqueue($groups) {
        wp_enqueue_style('galado-addons', GALADO_BUNDLES_URL . 'public/addons.css', [], GALADO_BUNDLES_VERSION);
        wp_enqueue_script('galado-addons', GALADO_BUNDLES_URL . 'public/addons.js', [], GALADO_BUNDLES_VERSION, true);
        wp_localize_script('galado-addons', 'GALADO_ADDONS', [
            'ajax'    => class_exists('WC_AJAX') ? WC_AJAX::get_endpoint('galado_addon_add') : '',
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
            <article class="gld-addon" role="listitem" data-product="<?php echo esc_attr($it['product_id']); ?>" data-type="<?php echo esc_attr($it['type']); ?>">
              <span class="gld-addon__circle"><img src="<?php echo esc_url($it['thumb']); ?>" alt="" loading="lazy" width="72" height="72"></span>
              <span class="gld-addon__name"><?php echo esc_html($it['name']); ?></span>
              <span class="gld-addon__price">+RM<?php echo esc_html(self::rm($it['price'])); ?></span>
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
        $allowed = false;
        foreach (GALADO_Bundles_Data::get_addon_groups() as $group) {
            foreach ($group['items'] as $it) {
                if ((int) $it['product_id'] === $pid) { $allowed = true; break 2; }
            }
        }
        $p = $allowed && $pid ? wc_get_product($pid) : null;
        if (!$p || !$p->is_purchasable() || !$p->is_in_stock()) {
            wp_send_json(['ok' => false, 'message' => __('That add-on is not available.', 'galado-bundles')]);
        }

        if ($p->is_type('variable')) {
            $v = $vid ? wc_get_product($vid) : null;
            if (!$v || $v->get_parent_id() !== $pid || !$v->is_purchasable() || !$v->is_in_stock()) {
                wp_send_json(['ok' => false, 'message' => __('That option just sold out, please pick another.', 'galado-bundles')]);
            }
            $key = WC()->cart->add_to_cart($pid, 1, $vid, $v->get_variation_attributes());
        } else {
            $key = WC()->cart->add_to_cart($pid, 1);
        }

        if (!$key) {
            wp_send_json(['ok' => false, 'message' => __('Could not add it, please try again.', 'galado-bundles')]);
        }
        WC_AJAX::get_refreshed_fragments();
    }

    // ---- launch seed --------------------------------------------------------

    /** Idempotent: the starter Accessories shelf (owner 2026-08-04), ordered
     * hot-sellers first from verified live products. Draft; ops publishes. */
    public static function seed_accessories_group() {
        $slug = 'addons-accessories';
        if (get_page_by_path($slug, OBJECT, GALADO_BUNDLES_CPT)) return 0;

        // Verified 2026-08-04, ordered by units sold: crossbody 6mm (94),
        // Cloud grip (87), Nova ring stand (31), mini wrist strap (29),
        // crossbody 7mm (15).
        $product_ids = [236439, 277284, 300306, 321013, 384007];

        $items = [];
        foreach ($product_ids as $n => $pid) {
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
                'label'                => '',
                'name_cache'           => $p->get_name(),
                'price_cache'          => (float) wc_get_price_to_display($p),
            ];
        }
        if (!$items) return 0;

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
        return 1;
    }
}
