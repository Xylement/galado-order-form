<?php
/**
 * The gift card is an ordinary variable product, marked by the product meta flag
 * _galado_gift_card = yes on the parent (variations inherit it through their parent). The option
 * galado_gift_cards_product_id records the id the creator made, for convenience only: the meta
 * flag is the source of truth, so a duplicated product is a gift card too.
 *
 * Variations: RM50, RM100, RM150, RM200, RM300 and "Custom amount" (RM30 up to the per-card
 * limit, whole ringgit, priced from the cart). All virtual, not taxable, sold individually so
 * each cart line is exactly one card to one recipient.
 *
 * It sits in its own "Gift cards" category (so catalogue feeds can leave it out by category) and
 * is on GALADO Bundles' never-bundle list: a card always costs its full price.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Product {

    const META_FLAG = '_galado_gift_card';
    const META_CUSTOM = '_galado_gc_custom';     // on the custom-amount variation
    const META_AMOUNT = '_galado_gc_amount';     // on each preset variation: its RM value
    const OPT_PRODUCT_ID = 'galado_gift_cards_product_id';
    const ATTRIBUTE = 'Amount';
    const ATTRIBUTE_KEY = 'amount';              // sanitize_title( 'Amount' ): WooCommerce's key for a local attribute
    const CUSTOM_LABEL = 'Custom amount';
    const CATEGORY_SLUG = 'gift-cards';

    public static function init() {
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'render_fields']);
        add_filter('woocommerce_get_price_html', [__CLASS__, 'price_html'], 20, 2);
        add_filter('woocommerce_available_variation', [__CLASS__, 'custom_variation_price'], 20, 3);
        add_filter('galado_bundles_excluded_products', [__CLASS__, 'exclude_from_bundles']);
    }

    /** GALADO Bundles: the card can never be put in a bundle (a bundle discount would cut its price). */
    public static function exclude_from_bundles($ids) {
        static $card = null;
        if (null === $card) {
            $card = self::find_product_id();
        }
        $ids = (array) $ids;
        if ($card && !in_array($card, $ids, true)) {
            $ids[] = $card;
        }
        return $ids;
    }

    /** The "Gift cards" product category, created if it does not exist yet. 0 if it cannot be made. */
    public static function category_id() {
        $term = get_term_by('slug', self::CATEGORY_SLUG, 'product_cat');
        if ($term) {
            return (int) $term->term_id;
        }
        $made = wp_insert_term(__('Gift cards', 'galado-gift-cards'), 'product_cat', ['slug' => self::CATEGORY_SLUG]);
        return is_wp_error($made) ? 0 : (int) $made['term_id'];
    }

    /**
     * "RM30.00 to RM1,000.00" instead of WooCommerce's variable range, which has an en dash
     * (customers read it) and stops at the dearest preset although a custom card can go higher.
     * Amounts in the shopper's currency when CURCY is active.
     */
    public static function price_html($html, $product) {
        if (!$product instanceof WC_Product || $product->is_type('variation') || !self::is_gift_card_product($product)) {
            return $html;
        }
        return '<span class="galado-gc-price">' . esc_html(self::range_text()) . '</span>';
    }

    /** The custom-amount choice has no price of its own (its stored RM30 is only a floor). */
    public static function custom_variation_price($data, $product = null, $variation = null) {
        if (is_array($data) && !empty($data['variation_id']) && self::is_custom_variation((int) $data['variation_id'])) {
            $data['price_html'] = '<span class="price">' . esc_html(self::range_text()) . '</span>';
        }
        return $data;
    }

    public static function range_text() {
        $show = function ($rm) {
            $amount = function_exists('wmc_get_price') ? (float) wmc_get_price($rm) : (float) $rm;
            return html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
        };
        $low = min(Galado_GC_Config::CUSTOM_MIN, min(Galado_GC_Config::PRESET_AMOUNTS));
        /* translators: 1: lowest amount, 2: highest amount */
        return sprintf(__('%1$s to %2$s', 'galado-gift-cards'), $show($low), $show(Galado_GC_Config::max_card()));
    }

    /** @param WC_Product|int $product */
    public static function is_gift_card_product($product) {
        static $memo = [];
        if ($product instanceof WC_Product) {
            $id = $product->get_parent_id() ?: $product->get_id();
        } else {
            $id = (int) $product;
            if ($id <= 0) {
                return false;
            }
            $parent = (int) wp_get_post_parent_id($id);
            if ($parent && 'product_variation' === get_post_type($id)) {
                $id = $parent;
            }
        }
        if (!isset($memo[$id])) {
            $memo[$id] = $id > 0 && 'yes' === get_post_meta($id, self::META_FLAG, true);
        }
        return $memo[$id];
    }

    public static function is_custom_variation($variation_id) {
        return 'yes' === get_post_meta((int) $variation_id, self::META_CUSTOM, true);
    }

    /** RM value of a preset variation, from its own meta (never a converted display price). */
    public static function preset_value($variation_id) {
        $v = get_post_meta((int) $variation_id, self::META_AMOUNT, true);
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** Id of the gift card product, any status except trash, or 0. */
    public static function find_product_id() {
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
            'meta_key'       => self::META_FLAG,
            'meta_value'     => 'yes',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
        return $ids ? (int) $ids[0] : 0;
    }

    /**
     * Launch day: create the product as Private (Clement can preview it; customers cannot see it)
     * and return its id. Running it again returns the existing product instead of a second one.
     */
    public static function create_product() {
        $existing = self::find_product_id();
        if ($existing) {
            return $existing;
        }

        $labels = [];
        foreach (Galado_GC_Config::PRESET_AMOUNTS as $amount) {
            $labels[] = 'RM' . $amount;
        }
        $labels[] = self::CUSTOM_LABEL;

        $attribute = new WC_Product_Attribute();
        $attribute->set_name(self::ATTRIBUTE);
        $attribute->set_options($labels);
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $product = new WC_Product_Variable();
        $product->set_name('GALADO Gift Card');
        $product->set_status('private');
        $product->set_tax_status('none');
        $product->set_sold_individually(true);
        $product->set_reviews_allowed(false);
        $product->set_short_description(self::terms_text());
        $product->set_description(self::terms_text());
        $product->set_attributes([$attribute]);
        $category = self::category_id();
        if ($category) {
            $product->set_category_ids([$category]);
        }
        $product->update_meta_data(self::META_FLAG, 'yes');
        $product_id = $product->save();

        foreach (Galado_GC_Config::PRESET_AMOUNTS as $amount) {
            self::add_variation($product_id, 'RM' . $amount, $amount, false);
        }
        self::add_variation($product_id, self::CUSTOM_LABEL, Galado_GC_Config::CUSTOM_MIN, true);

        WC_Product_Variable::sync($product_id);
        update_option(self::OPT_PRODUCT_ID, $product_id, false);
        return $product_id;
    }

    private static function add_variation($product_id, $label, $amount, $custom) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product_id);
        $variation->set_attributes([self::ATTRIBUTE_KEY => $label]);
        $variation->set_regular_price((string) $amount);
        $variation->set_virtual(true);
        $variation->set_tax_status('none');
        $variation->set_status('publish');
        if ($custom) {
            $variation->update_meta_data(self::META_CUSTOM, 'yes');
        } else {
            $variation->update_meta_data(self::META_AMOUNT, (string) $amount);
        }
        return $variation->save();
    }

    /** The terms every surface repeats (product page, email, card). No dashes: customers read this. */
    public static function terms_text() {
        return sprintf(
            /* translators: %d: years */
            __('Valid for %d years from purchase. Single use: spend it in one order. Any unused value is lost. Non-refundable once delivered.', 'galado-gift-cards'),
            Galado_GC_Config::VALID_YEARS
        );
    }

    /** Recipient, message and delivery date fields inside the add to cart form. */
    public static function render_fields() {
        global $product;
        if (!$product instanceof WC_Product || !self::is_gift_card_product($product)) {
            return;
        }
        $today = Galado_GC_Time::today();
        $last = Galado_GC_Time::last_delivery_date();
        $max_card = Galado_GC_Config::max_card();
        $posted = function ($key) {
            return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        };
        ?>
        <div class="galado-gc-fields">
            <p class="form-row galado-gc-custom" data-galado-gc-custom>
                <label for="galado_gc_custom_amount"><?php
                    /* translators: 1: minimum RM, 2: maximum RM */
                    echo esc_html(sprintf(__('Amount (RM%1$d to RM%2$s, whole ringgit)', 'galado-gift-cards'), Galado_GC_Config::CUSTOM_MIN, number_format_i18n($max_card)));
                ?></label>
                <input type="number" id="galado_gc_custom_amount" name="galado_gc_custom_amount" inputmode="numeric"
                       min="<?php echo esc_attr((string) Galado_GC_Config::CUSTOM_MIN); ?>" max="<?php echo esc_attr((string) $max_card); ?>" step="1"
                       value="<?php echo esc_attr($posted('galado_gc_custom_amount')); ?>">
            </p>
            <?php Galado_GC_Designs::render_picker($posted(Galado_GC_Designs::FIELD)); ?>
            <p class="form-row">
                <label for="galado_gc_recipient_name"><?php esc_html_e("Recipient's name", 'galado-gift-cards'); ?></label>
                <input type="text" id="galado_gc_recipient_name" name="galado_gc_recipient_name" required
                       maxlength="<?php echo esc_attr((string) Galado_GC_Config::NAME_MAX); ?>" value="<?php echo esc_attr($posted('galado_gc_recipient_name')); ?>">
            </p>
            <p class="form-row">
                <label for="galado_gc_recipient_email"><?php esc_html_e("Recipient's email", 'galado-gift-cards'); ?></label>
                <input type="email" id="galado_gc_recipient_email" name="galado_gc_recipient_email" required data-clarity-mask="True"
                       value="<?php echo esc_attr($posted('galado_gc_recipient_email')); ?>">
            </p>
            <p class="form-row">
                <label for="galado_gc_message"><?php esc_html_e('Message (optional)', 'galado-gift-cards'); ?></label>
                <textarea id="galado_gc_message" name="galado_gc_message" rows="3" data-clarity-mask="True"
                          maxlength="<?php echo esc_attr((string) Galado_GC_Config::MESSAGE_MAX); ?>"><?php echo esc_textarea(isset($_POST['galado_gc_message']) ? sanitize_textarea_field(wp_unslash($_POST['galado_gc_message'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification ?></textarea>
            </p>
            <p class="form-row">
                <label for="galado_gc_delivery_date"><?php esc_html_e('Send it on', 'galado-gift-cards'); ?></label>
                <input type="date" id="galado_gc_delivery_date" name="galado_gc_delivery_date" required
                       min="<?php echo esc_attr($today); ?>" max="<?php echo esc_attr($last); ?>"
                       value="<?php echo esc_attr($posted('galado_gc_delivery_date') ?: $today); ?>">
                <small><?php esc_html_e('We email it at 9am Malaysian time on this date. Today sends it right away.', 'galado-gift-cards'); ?></small>
            </p>
            <p class="galado-gc-terms"><?php echo esc_html(self::terms_text()); ?></p>
        </div>
        <script>
        (function () {
            var form = document.currentScript && document.currentScript.closest('form');
            if (!form) { return; }
            var box = form.querySelector('[data-galado-gc-custom]');
            var input = form.querySelector('#galado_gc_custom_amount');
            var select = form.querySelector('select[name="attribute_<?php echo esc_js(self::ATTRIBUTE_KEY); ?>"]');
            function sync() {
                var custom = !!select && select.value === <?php echo wp_json_encode(self::CUSTOM_LABEL); ?>;
                box.style.display = custom ? '' : 'none';
                input.required = custom;
            }
            if (select) { select.addEventListener('change', sync); }
            if (window.jQuery) { window.jQuery(form).on('woocommerce_variation_has_changed', sync); }
            sync();
        })();
        </script>
        <?php
    }
}
