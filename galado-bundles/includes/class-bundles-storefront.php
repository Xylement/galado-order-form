<?php
/**
 * Storefront rendering (spec 5.2, 5.4, 5.6): the [galado_bundles] shortcode, the
 * card markup (with the server-rendered native <select> the picker enhances) and
 * the rotation cache purge. Only loaded when the storefront flag is on.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Storefront {

    private static $enqueued = false;

    public static function init() {
        // The shortcode itself is registered in the main plugin (always), so a
        // dark [galado_bundles] renders nothing. init() adds only the on-flag hooks.
        add_action('galado_bundles_changed', [__CLASS__, 'purge']);
        add_action('woocommerce_product_set_stock', [__CLASS__, 'purge_for_product']);
        add_action('woocommerce_variation_set_stock', [__CLASS__, 'purge_for_product']);
        add_action('woocommerce_product_object_updated_props', [__CLASS__, 'purge_on_price_change'], 10, 2);
    }

    public static function shortcode($atts) {
        // Dark: customers see nothing, but staff can preview the band (e.g. on the
        // private home-v3 page) before go-live. The cart engine stays off, so adds
        // are disabled in preview (see the preview flag below).
        if (!galado_bundles_storefront_enabled() && !self::can_preview()) return '';
        $atts = shortcode_atts(['featured' => '1', 'limit' => GALADO_BUNDLES_FEATURED_MAX], $atts, 'galado_bundles');
        $bundles = GALADO_Bundles_Data::get_featured();
        if ((int) $atts['limit'] > 0) $bundles = array_slice($bundles, 0, (int) $atts['limit']);
        if (!$bundles) return '';

        self::enqueue();
        ob_start();
        echo '<div class="gld-bundles" role="list">';
        foreach ($bundles as $b) echo self::card($b);
        echo '</div>';
        return ob_get_clean();
    }

    /** May the current viewer see the band while the storefront is dark?
     * Any staff member who can reach a (usually private) preview page counts:
     * shop managers (manage_woocommerce) and editors/admins (edit_pages). An
     * explicit ?bundles_preview=1 also gives a deterministic, cache-busting
     * preview link for any staff user. Never true for customers, so nothing
     * leaks even if the shortcode later sits on a public page while dark. */
    private static function can_preview() {
        if (current_user_can('manage_woocommerce') || current_user_can('edit_pages')) return true;
        if (isset($_GET['bundles_preview']) && current_user_can('edit_posts')) return true;
        return false;
    }

    private static function card($b) {
        $sum = $b['sum']; $total = $b['total']; $save = $b['save'];
        if ($save >= $sum && $save > 0) {
            error_log('[galado-bundles] bundle ' . $b['slug'] . ' saving >= sum; rendering without strike');
        }
        ob_start(); ?>
        <article class="gld-bundle" role="listitem"
                 data-slug="<?php echo esc_attr($b['slug']); ?>"
                 data-mode="<?php echo esc_attr($b['mode']); ?>"
                 data-save="<?php echo esc_attr($save); ?>"
                 data-sum="<?php echo esc_attr($sum); ?>"
                 data-title="<?php echo esc_attr($b['title']); ?>">
          <div class="gld-bundle__imgs"><?php echo self::thumbs($b); ?></div>
          <h3 class="gld-bundle__name"><?php echo esc_html($b['title']); ?></h3>
          <?php if ($b['blurb']) : ?><p class="gld-bundle__blurb"><?php echo esc_html($b['blurb']); ?></p><?php endif; ?>

          <ul class="gld-bundle__items">
            <?php foreach ($b['items'] as $it) echo self::item_row($it); ?>
          </ul>

          <?php foreach ($b['items'] as $it) {
              if ('variable' === $it['line_type'] && 'shopper_choice' === $it['variation_mode']) echo self::pick_line($it);
          } ?>

          <div class="gld-bundle__price">
            <?php if ($save > 0 && $save < $sum) : ?>
              <small class="gld-bundle__save">Save RM<?php echo esc_html(self::rm($save)); ?></small>
              <span><s class="was" aria-hidden="true">RM<?php echo esc_html(self::rm($sum)); ?></s>
                    <b class="now">RM<?php echo esc_html(self::rm($total)); ?></b></span>
              <span class="screen-reader-text">Was RM<?php echo esc_html(self::rm($sum)); ?>, now RM<?php echo esc_html(self::rm($total)); ?></span>
            <?php else : ?>
              <span><b class="now">RM<?php echo esc_html(self::rm($sum)); ?></b></span>
            <?php endif; ?>
          </div>

          <button type="button" class="gld-bundle__cta" data-action="add"><?php echo esc_html($b['cta']); ?></button>
          <noscript><a class="gld-bundle__cta" href="<?php echo esc_url(home_url('/?galado_bundle=' . $b['slug'])); ?>"><?php echo esc_html($b['cta']); ?></a></noscript>
          <p class="gld-bundle__note" aria-live="polite"></p>
        </article>
        <?php
        return ob_get_clean();
    }

    private static function thumbs($b) {
        $out = '';
        if ($b['image']) {
            $out .= '<img src="' . esc_url($b['image']) . '" alt="" loading="lazy">';
        } else {
            foreach (array_slice($b['items'], 0, 3) as $it) {
                $p = wc_get_product($it['product_id']);
                if ($p) $out .= '<img src="' . esc_url(wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src()) . '" alt="" loading="lazy">';
            }
        }
        return $out;
    }

    private static function item_row($it) {
        $p = wc_get_product($it['product_id']);
        $name = $p ? $p->get_name() : $it['name_cache'];
        if ('variable' === $it['line_type'] && 'pinned' === $it['variation_mode'] && $it['default_variation_id']) {
            $v = wc_get_product($it['default_variation_id']);
            $lbl = $v ? implode(' / ', array_map('wc_clean', array_values($v->get_variation_attributes()))) : '';
            return '<li class="is-included"><span class="gld-included">Includes: ' . esc_html($lbl ?: $name) . '</span></li>';
        }
        if ('variable' === $it['line_type'] && 'shopper_choice' === $it['variation_mode']) {
            return ''; // rendered by the picker below
        }
        $price = $p ? wc_get_price_to_display($p) : 0;
        return '<li>' . esc_html($name) . ' <span>RM' . esc_html(self::rm($price)) . '</span></li>';
    }

    /** The a11y baseline: a real native <select> of purchasable variations,
     * each option carrying its full attribute map, price and thumb, so the JS
     * can build the inline strip (one axis) or the model sheet + colour swatches
     * (two axes) and write the chosen variation_id back here. */
    private static function pick_line($it) {
        $parent = wc_get_product($it['product_id']);
        if (!$parent || !$parent->is_type('variable')) return '';

        $options = '';
        $axis_counts = [];
        foreach ($parent->get_children() as $cid) {
            $v = wc_get_product($cid);
            if (!$v || !$v->is_purchasable() || !$v->is_in_stock()) continue;
            $attrs = [];
            foreach ($v->get_variation_attributes() as $k => $val) {
                if ('' === $val) continue; // "any" wildcard: not a concrete axis value
                $key = 0 === strpos($k, 'attribute_') ? substr($k, strlen('attribute_')) : $k;
                $display = self::attr_display($k, $val);
                $attrs[$key] = $display;
                $axis_counts[$key][$display] = true;
            }
            $label = $attrs ? implode(' / ', array_values($attrs)) : ('#' . $cid);
            $thumb = wp_get_attachment_image_url($v->get_image_id() ?: $parent->get_image_id(), 'woocommerce_thumbnail') ?: '';
            $options .= sprintf(
                '<option value="%d" data-attrs="%s" data-price="%s" data-thumb="%s">%s</option>',
                $cid,
                esc_attr(wp_json_encode($attrs)),
                esc_attr(wc_get_price_to_display($v)),
                esc_attr($thumb),
                esc_html($label)
            );
        }

        // Order axes so the longest (model) is picked first; label per axis.
        $axes = array_keys($axis_counts);
        usort($axes, function ($a, $b) use ($axis_counts) { return count($axis_counts[$b]) <=> count($axis_counts[$a]); });
        $labels = array_map([__CLASS__, 'axis_label'], $axes);
        $prompt = $it['label'] ?: ('Choose your ' . ($labels[0] ?? 'option'));

        return sprintf(
            '<div class="gld-pick" data-slot="%s" data-axes="%d" data-axis-keys="%s" data-axis-labels="%s">'
            . '<label class="screen-reader-text" for="gld-sel-%1$s-%5$s">%6$s</label>'
            . '<select class="gld-pick__native" id="gld-sel-%1$s-%5$s" name="v[%1$s]">'
            . '<option value="">%6$s</option>%7$s</select></div>',
            esc_attr($it['slot']),
            count($axes),
            esc_attr(wp_json_encode($axes)),
            esc_attr(wp_json_encode($labels)),
            esc_attr($it['product_id']),
            esc_html($prompt),
            $options
        );
    }

    /** Display value for a variation attribute. Taxonomy attributes
     * (attribute_pa_*) store the term slug, so resolve it to the term name;
     * custom attributes already hold the label. */
    private static function attr_display($attribute_key, $value) {
        $tax = 0 === strpos($attribute_key, 'attribute_') ? substr($attribute_key, strlen('attribute_')) : $attribute_key;
        if ($tax && taxonomy_exists($tax)) {
            $term = get_term_by('slug', $value, $tax);
            if ($term && !is_wp_error($term)) return $term->name;
        }
        return wc_clean($value);
    }

    private static function axis_label($key) {
        $k = strtolower($key);
        if (false !== strpos($k, 'colour') || false !== strpos($k, 'color')) return 'colour';
        if (false !== strpos($k, 'model') || false !== strpos($k, 'phone')) return 'model';
        if (false !== strpos($k, 'stylink') || false !== strpos($k, 'set') || false !== strpos($k, 'design')) return 'design';
        return str_replace(['-', '_'], ' ', $key);
    }

    private static function rm($n) {
        $s = number_format((float) $n, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function enqueue() {
        if (self::$enqueued) return;
        self::$enqueued = true;
        wp_enqueue_style('galado-bundles', GALADO_BUNDLES_URL . 'public/galado-bundles.css', [], GALADO_BUNDLES_VERSION);
        wp_enqueue_script('galado-bundles', GALADO_BUNDLES_URL . 'public/galado-bundles.js', [], GALADO_BUNDLES_VERSION, true);
        wp_localize_script('galado-bundles', 'GALADO_BUNDLES', [
            'ajax'     => WC_AJAX::get_endpoint('galado_bundle_add'),
            'cart_url' => wc_get_cart_url(),
            // Dark admin preview: the add endpoint is not registered, so the JS
            // shows a note instead of a broken request.
            'preview'  => !galado_bundles_storefront_enabled(),
        ]);
    }

    // ---- cache purge (spec 5.6): best-effort origin + CF ---------------------

    public static function purge($ids = []) {
        self::purge_wpfc();
        do_action('galado_bundles_purge_urls', self::shortcode_urls());
    }

    public static function purge_for_product($product_id) {
        self::purge();
    }

    public static function purge_on_price_change($product, $updated_props) {
        if (array_intersect((array) $updated_props, ['price', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity'])) {
            self::purge();
        }
    }

    private static function purge_wpfc() {
        // WP Fastest Cache: clear all (blunt but safe for a low-frequency change).
        if (class_exists('WpFastestCache')) {
            $wpfc = new WpFastestCache();
            if (method_exists($wpfc, 'deleteCache')) $wpfc->deleteCache(true);
        }
        if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
    }

    private static function shortcode_urls() {
        $urls = [home_url('/')];
        $pages = get_posts([
            'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 20,
            's' => '[galado_bundles', 'fields' => 'ids', 'no_found_rows' => true,
        ]);
        foreach ($pages as $pid) $urls[] = get_permalink($pid);
        return array_values(array_unique($urls));
    }
}
