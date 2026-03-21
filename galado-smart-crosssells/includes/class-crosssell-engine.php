<?php
/**
 * Cross-sell recommendation engine
 * Determines which products to suggest based on cart contents
 */

if (!defined('ABSPATH')) exit;

class Galado_Crosssell_Engine {

    // Product category relationships for smart matching
    private static $category_rules = [];

    public static function init() {
        // Build category rules on init
        add_action('init', [__CLASS__, 'build_rules']);
    }

    public static function build_rules() {
        // These map product categories to complementary categories
        // e.g., if cart has a "phone case", suggest "screen protector", "charm", "strap"
        self::$category_rules = apply_filters('galado_cs_category_rules', [
            // Case categories -> suggest accessories
            'phone-cases'       => ['screen-protectors', 'phone-charms', 'straps', 'grips'],
            'iphone-cases'      => ['screen-protectors', 'phone-charms', 'straps', 'grips'],
            'samsung-cases'     => ['screen-protectors', 'phone-charms', 'straps', 'grips'],
            'custom-name-cases' => ['screen-protectors', 'phone-charms', 'straps', 'grips'],
            'custom-cases'      => ['screen-protectors', 'phone-charms', 'straps', 'grips'],
            // Accessories -> suggest cases or other accessories
            'phone-charms'      => ['straps', 'grips', 'phone-cases'],
            'straps'            => ['phone-charms', 'grips', 'phone-cases'],
            'screen-protectors' => ['phone-charms', 'straps', 'phone-cases'],
        ]);
    }

    /**
     * Get cross-sell recommendations based on cart contents
     *
     * @param int $max Maximum number of recommendations
     * @param array $exclude_ids Product IDs to exclude (already in cart)
     * @return array WC_Product objects
     */
    public static function get_recommendations($max = 4, $exclude_ids = []) {
        $max = absint(get_option('galado_cs_max_products', 4));
        $smart = get_option('galado_cs_smart_matching', 'yes') === 'yes';

        // Get cart product IDs and their categories
        $cart_product_ids = [];
        $cart_categories = [];

        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $product_id = $item['product_id'];
                $cart_product_ids[] = $product_id;
                $exclude_ids[] = $product_id;

                // Also exclude variations
                if (!empty($item['variation_id'])) {
                    $exclude_ids[] = $item['variation_id'];
                }

                // Get categories
                $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
                $cart_categories = array_merge($cart_categories, $terms);
            }
        }

        $cart_categories = array_unique($cart_categories);
        $exclude_ids = array_unique($exclude_ids);
        $recommendations = [];

        // Strategy 1: WooCommerce built-in cross-sells (manually set by store owner)
        $wc_crosssells = self::get_wc_crosssells($cart_product_ids, $exclude_ids);
        $recommendations = array_merge($recommendations, $wc_crosssells);

        // Strategy 2: Smart category matching
        if ($smart && count($recommendations) < $max) {
            $category_recs = self::get_category_recommendations($cart_categories, $exclude_ids, $max - count($recommendations));
            $recommendations = array_merge($recommendations, $category_recs);
        }

        // Strategy 3: Best sellers fallback
        if (count($recommendations) < $max) {
            $bestsellers = self::get_bestsellers($exclude_ids, $max - count($recommendations));
            $recommendations = array_merge($recommendations, $bestsellers);
        }

        // Deduplicate and limit
        $seen = [];
        $unique = [];
        foreach ($recommendations as $product) {
            $id = $product->get_id();
            if (!in_array($id, $seen) && !in_array($id, $exclude_ids)) {
                $seen[] = $id;
                $unique[] = $product;
            }
            if (count($unique) >= $max) break;
        }

        return $unique;
    }

    /**
     * Get WooCommerce built-in cross-sells for cart products
     */
    private static function get_wc_crosssells($cart_product_ids, $exclude_ids) {
        $crosssell_ids = [];
        foreach ($cart_product_ids as $pid) {
            $product = wc_get_product($pid);
            if ($product) {
                $crosssell_ids = array_merge($crosssell_ids, $product->get_cross_sell_ids());
            }
        }

        $crosssell_ids = array_unique(array_diff($crosssell_ids, $exclude_ids));
        $products = [];

        foreach ($crosssell_ids as $id) {
            $product = wc_get_product($id);
            if ($product && $product->is_purchasable() && $product->is_in_stock()) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Get recommendations based on category relationships
     */
    private static function get_category_recommendations($cart_categories, $exclude_ids, $limit) {
        $target_categories = [];

        foreach ($cart_categories as $cat) {
            if (isset(self::$category_rules[$cat])) {
                $target_categories = array_merge($target_categories, self::$category_rules[$cat]);
            }
        }

        $target_categories = array_unique(array_diff($target_categories, $cart_categories));

        if (empty($target_categories)) return [];

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => $exclude_ids,
            'orderby' => 'meta_value_num',
            'meta_key' => 'total_sales',
            'order' => 'DESC',
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $target_categories,
                ]
            ],
            'meta_query' => [
                [
                    'key' => '_stock_status',
                    'value' => 'instock',
                ]
            ]
        ];

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product && $product->is_purchasable()) {
                $products[] = $product;
            }
        }

        wp_reset_postdata();
        return $products;
    }

    /**
     * Get best-selling products as fallback
     */
    private static function get_bestsellers($exclude_ids, $limit) {
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => $exclude_ids,
            'orderby' => 'meta_value_num',
            'meta_key' => 'total_sales',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_stock_status',
                    'value' => 'instock',
                ]
            ]
        ];

        $query = new WP_Query($args);
        $products = [];

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product && $product->is_purchasable()) {
                $products[] = $product;
            }
        }

        wp_reset_postdata();
        return $products;
    }

    /**
     * Render a product card for cross-sell display
     */
    public static function render_product_card($product) {
        $id = $product->get_id();
        $name = $product->get_name();
        $price = $product->get_price_html();
        $image = $product->get_image('woocommerce_thumbnail');
        $permalink = $product->get_permalink();
        $is_simple = $product->is_type('simple');
        $rating = $product->get_average_rating();
        $review_count = $product->get_review_count();

        ob_start();
        ?>
        <div class="galado-cs-card" data-product-id="<?php echo esc_attr($id); ?>">
            <a href="<?php echo esc_url($permalink); ?>" class="galado-cs-card__image">
                <?php echo $image; ?>
                <?php if ($product->is_on_sale()): ?>
                    <span class="galado-cs-card__badge">Sale</span>
                <?php endif; ?>
            </a>
            <div class="galado-cs-card__info">
                <a href="<?php echo esc_url($permalink); ?>" class="galado-cs-card__name">
                    <?php echo esc_html($name); ?>
                </a>
                <?php if ($rating > 0): ?>
                    <div class="galado-cs-card__rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="star <?php echo $i <= round($rating) ? 'filled' : ''; ?>">★</span>
                        <?php endfor; ?>
                        <span class="count">(<?php echo esc_html($review_count); ?>)</span>
                    </div>
                <?php endif; ?>
                <div class="galado-cs-card__price"><?php echo $price; ?></div>
                <?php if ($is_simple): ?>
                    <button class="galado-cs-add-btn" data-product-id="<?php echo esc_attr($id); ?>">
                        <span class="btn-text">+ Add</span>
                        <span class="btn-loading" style="display:none;">Adding...</span>
                        <span class="btn-done" style="display:none;">✓ Added</span>
                    </button>
                <?php else: ?>
                    <a href="<?php echo esc_url($permalink); ?>" class="galado-cs-select-btn">Select Options</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
