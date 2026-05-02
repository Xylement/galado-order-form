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
        // GALADO actual category slugs
        // Cases (iPhone/Samsung) -> suggest ONLY accessories, never other cases
        $accessory_cats = ['screen-protector', 'lens-protector', 'phone-charm', 'phone-strap', 'magnetic-ring-stand'];

        $rules = [];

        // All iPhone model categories -> suggest accessories
        $iphone_cats = [
            'iphone', 'iphone-17-pro-max', 'iphone-17-pro', 'iphone-air', 'iphone-17',
            'iphone-16-pro-max', 'iphone-16-pro', 'iphone-16-plus', 'iphone-16',
            'iphone-15-pro-max', 'iphone-15-pro', 'iphone-15',
            'iphone-14-pro-max', 'iphone-14-pro', 'iphone-14-plus', 'iphone-14',
            'iphone-13-pro-max', 'iphone-13-pro', 'iphone-13', 'iphone-13-mini',
        ];

        // All Samsung model categories -> suggest accessories
        $samsung_cats = [
            'samsung', 'galaxy-s26-ultra', 'galaxy-s26-plus', 'galaxy-s26',
            'galaxy-s25-ultra', 'galaxy-s25-plus', 'galaxy-s25',
            'galaxy-s24-ultra', 'galaxy-s24-samsung', 'galaxy-s24',
        ];

        // Parent categories for cases
        $case_cats = array_merge(['apple'], $iphone_cats, $samsung_cats);

        // Cases -> suggest accessories only (NEVER other cases)
        foreach ($case_cats as $cat) {
            $rules[$cat] = $accessory_cats;
        }

        // AirPods categories -> suggest phone charms, straps (not screen protectors)
        $airpod_cats = ['airpods', 'airpods-4', 'airpods-3', 'airpods-pro-3', 'airpods-pro-2', 'airpods-pro'];
        foreach ($airpod_cats as $cat) {
            $rules[$cat] = ['phone-charm', 'phone-strap', 'magnetic-ring-stand'];
        }

        // MacBook categories -> suggest nothing specific (different product line)
        // They won't match any accessory categories, so bestsellers fallback kicks in

        // Accessories -> suggest other accessories (not cases)
        $rules['phone-charm']        = ['phone-strap', 'magnetic-ring-stand', 'screen-protector'];
        $rules['phone-strap']        = ['phone-charm', 'magnetic-ring-stand', 'screen-protector'];
        $rules['magnetic-ring-stand'] = ['phone-charm', 'phone-strap', 'screen-protector'];
        $rules['screen-protector']   = ['lens-protector', 'phone-charm', 'phone-strap', 'magnetic-ring-stand'];
        $rules['lens-protector']     = ['screen-protector', 'phone-charm', 'phone-strap', 'magnetic-ring-stand'];
        $rules['accessories']        = ['phone-charm', 'phone-strap', 'magnetic-ring-stand', 'screen-protector'];

        self::$category_rules = apply_filters('galado_cs_category_rules', $rules);
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
        $cart_category_slugs_in_cart = []; // track which accessory categories are already in cart

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
                $cart_category_slugs_in_cart = array_merge($cart_category_slugs_in_cart, $terms);

                // Detect product add-ons (WooCommerce Product Add-Ons / PPOM / YITH)
                // Check cart item meta for add-on selections
                $addon_names = self::detect_addons_in_cart_item($item);
                $cart_category_slugs_in_cart = array_merge($cart_category_slugs_in_cart, $addon_names);
            }
        }

        $cart_categories = array_unique($cart_categories);
        $cart_category_slugs_in_cart = array_unique($cart_category_slugs_in_cart);
        $exclude_ids = array_unique($exclude_ids);
        $recommendations = [];

        // Strategy 1: WooCommerce built-in cross-sells (manually set by store owner)
        $wc_crosssells = self::get_wc_crosssells($cart_product_ids, $exclude_ids);
        $recommendations = array_merge($recommendations, $wc_crosssells);

        // Strategy 2: Smart category matching
        if ($smart && count($recommendations) < $max) {
            $category_recs = self::get_category_recommendations($cart_categories, $exclude_ids, $max - count($recommendations), $cart_category_slugs_in_cart);
            $recommendations = array_merge($recommendations, $category_recs);
        }

        // Strategy 3: Best sellers fallback (randomised, not always same products)
        if (count($recommendations) < $max) {
            $bestsellers = self::get_bestsellers($exclude_ids, $max - count($recommendations), $cart_category_slugs_in_cart);
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
     * Detect add-on products selected via product add-on plugins
     * Returns category-like slugs for items detected as add-ons
     */
    private static function detect_addons_in_cart_item($item) {
        $addon_cats = [];

        // Map common add-on product names to category slugs
        $addon_keyword_map = [
            'tempered glass'       => 'screen-protector',
            'screen protector'     => 'screen-protector',
            'tempered'             => 'screen-protector',
            'camera lens'          => 'lens-protector',
            'lens protector'       => 'lens-protector',
            'phone charm'          => 'phone-charm',
            'charm'                => 'phone-charm',
            'wrist strap'          => 'phone-strap',
            'crossbody strap'      => 'phone-strap',
            'crossbody'            => 'phone-strap',
            'strap'                => 'phone-strap',
            'magsafe grip'         => 'magnetic-ring-stand',
            'magsafe'              => 'magnetic-ring-stand',
            'grip'                 => 'magnetic-ring-stand',
        ];

        // ============================================
        // PRIORITY: Acowebs WCPA (WooCommerce Custom Product Addons)
        // Stores data in $item['wcpa_data'] as array of objects with 'label', 'value', 'price' etc.
        // ============================================
        if (!empty($item['wcpa_data'])) {
            $wcpa_data = $item['wcpa_data'];
            if (is_string($wcpa_data)) {
                $wcpa_data = json_decode($wcpa_data, true);
            }
            if (is_array($wcpa_data)) {
                foreach ($wcpa_data as $field) {
                    // Each field can have: label, value, price, type, etc.
                    $label = strtolower($field['label'] ?? $field['name'] ?? '');
                    $value = '';
                    if (isset($field['value'])) {
                        $value = strtolower(is_array($field['value']) ? implode(' ', $field['value']) : (string)$field['value']);
                    }
                    // Also check 'option_label' for checkbox/select types
                    $option_label = strtolower($field['option_label'] ?? $field['option'] ?? '');

                    $all_text = $label . ' ' . $value . ' ' . $option_label;

                    foreach ($addon_keyword_map as $keyword => $cat) {
                        if (strpos($all_text, $keyword) !== false) {
                            $addon_cats[] = $cat;
                        }
                    }
                }
            }
        }

        // Also check wcpa_ prefixed keys (some versions use these)
        foreach ($item as $key => $value) {
            if (strpos($key, 'wcpa_') === 0 && $key !== 'wcpa_data') {
                $val = strtolower(is_array($value) ? json_encode($value) : (string)$value);
                foreach ($addon_keyword_map as $keyword => $cat) {
                    if (strpos($val, $keyword) !== false) {
                        $addon_cats[] = $cat;
                    }
                }
            }
        }

        // ============================================
        // WooCommerce Product Add-Ons (official)
        // ============================================
        if (!empty($item['addons'])) {
            foreach ($item['addons'] as $addon) {
                $name = strtolower($addon['name'] ?? '');
                $value = strtolower($addon['value'] ?? '');
                foreach ($addon_keyword_map as $keyword => $cat) {
                    if (strpos($name, $keyword) !== false || strpos($value, $keyword) !== false) {
                        $addon_cats[] = $cat;
                    }
                }
            }
        }

        // ============================================
        // Brute-force: scan ALL cart item data for keyword matches
        // This catches any add-on plugin we haven't explicitly handled
        // ============================================
        $item_json = strtolower(json_encode($item));
        foreach ($addon_keyword_map as $keyword => $cat) {
            if (strpos($item_json, $keyword) !== false) {
                $addon_cats[] = $cat;
            }
        }

        return array_unique($addon_cats);
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
     * Excludes categories already covered by cart items or add-ons
     */
    private static function get_category_recommendations($cart_categories, $exclude_ids, $limit, $covered_categories = []) {
        $target_categories = [];

        foreach ($cart_categories as $cat) {
            if (isset(self::$category_rules[$cat])) {
                $target_categories = array_merge($target_categories, self::$category_rules[$cat]);
            }
        }

        // Remove categories already in cart OR covered by add-ons
        $target_categories = array_unique(array_diff($target_categories, $cart_categories, $covered_categories));

        if (empty($target_categories)) return [];

        // Fetch more than needed so we can randomise
        $fetch_count = max($limit * 3, 12);

        // Use hybrid ranking (trending 30d → newest → lifetime bestsellers).
        $product_ids = self::get_ranked_product_ids($target_categories, $fetch_count, $exclude_ids);

        $products = [];
        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if ($product && $product->is_purchasable()) {
                $products[] = $product;
            }
        }

        // Randomise from the pool — keep top 2 trending, shuffle the rest
        if (count($products) > $limit) {
            $top = array_slice($products, 0, 2);
            $rest = array_slice($products, 2);
            shuffle($rest);
            $products = array_merge($top, $rest);
        }

        return array_slice($products, 0, $limit);
    }

    /**
     * Get best-selling products as fallback (with randomisation)
     * Excludes categories already covered by cart or add-ons
     */
    private static function get_bestsellers($exclude_ids, $limit, $covered_categories = []) {
        $fetch_count = max($limit * 3, 12);

        // Use hybrid ranking (trending 30d → newest → lifetime bestsellers).
        // Pass an extra "exclude these categories" via filter on the ranking helper.
        $product_ids = self::get_ranked_product_ids([], $fetch_count, $exclude_ids, $covered_categories);

        $products = [];
        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if ($product && $product->is_purchasable()) {
                $products[] = $product;
            }
        }

        // Randomise — keep top 1 trending, shuffle rest
        if (count($products) > $limit) {
            $top = array_slice($products, 0, 1);
            $rest = array_slice($products, 1);
            shuffle($rest);
            $products = array_merge($top, $rest);
        }

        return array_slice($products, 0, $limit);
    }

    // =========================================================================
    // HYBRID RANKING (trending 30d → newest → lifetime bestsellers)
    // =========================================================================

    /**
     * Cached list of product IDs sorted by units sold in the last 30 days.
     */
    private static function get_trending_product_ids() {
        $cache_key = 'galado_cs_trending_30d';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $sales = [];
        $orders = wc_get_orders([
            'limit'        => -1,
            'status'       => ['completed', 'processing'],
            'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS),
            'return'       => 'objects',
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $pid = method_exists($item, 'get_product_id') ? $item->get_product_id() : 0;
                if (!$pid) continue;
                $qty = method_exists($item, 'get_quantity') ? intval($item->get_quantity()) : 1;
                $sales[$pid] = ($sales[$pid] ?? 0) + max(1, $qty);
            }
        }

        arsort($sales);
        $ids = array_map('intval', array_keys($sales));
        set_transient($cache_key, $ids, 6 * HOUR_IN_SECONDS);
        return $ids;
    }

    /**
     * Hybrid ranking: trending (30d) → newest → lifetime bestsellers.
     * Mode is controlled by `galado_cs_ranking_mode` (default: hybrid).
     *
     * @param array $include_cats Category slugs to include (empty = any).
     * @param int   $limit        Max IDs to return.
     * @param array $exclude_ids  Product IDs to exclude.
     * @param array $exclude_cats Category slugs to exclude entirely.
     */
    private static function get_ranked_product_ids($include_cats = [], $limit = 4, $exclude_ids = [], $exclude_cats = []) {
        $mode = get_option('galado_cs_ranking_mode', 'hybrid');
        $include_cats = array_filter((array) $include_cats);
        $exclude_cats = array_filter((array) $exclude_cats);
        $exclude_ids = array_map('intval', (array) $exclude_ids);
        $ids = [];

        if ($mode === 'hybrid' || $mode === 'trending') {
            $trending = self::get_trending_product_ids();
            if (!empty($trending)) {
                $ids = array_merge($ids, self::filter_product_ids($trending, $include_cats, $exclude_cats, $exclude_ids));
            }
        }

        if (count($ids) < $limit && ($mode === 'hybrid' || $mode === 'newest')) {
            $newest = self::query_products(
                ['orderby' => 'date', 'order' => 'DESC'],
                $include_cats,
                $exclude_cats,
                array_merge($exclude_ids, $ids),
                $limit * 2
            );
            $ids = array_merge($ids, $newest);
        }

        if (count($ids) < $limit) {
            $lifetime = self::query_products(
                ['orderby' => 'meta_value_num', 'meta_key' => 'total_sales', 'order' => 'DESC'],
                $include_cats,
                $exclude_cats,
                array_merge($exclude_ids, $ids),
                $limit * 2
            );
            $ids = array_merge($ids, $lifetime);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        return array_slice($ids, 0, $limit);
    }

    private static function filter_product_ids($candidate_ids, $include_cats, $exclude_cats, $exclude_ids) {
        if (empty($candidate_ids)) return [];

        $candidate_ids = array_values(array_diff(array_map('intval', $candidate_ids), $exclude_ids));
        if (empty($candidate_ids)) return [];

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => count($candidate_ids),
            'post__in'       => $candidate_ids,
            'orderby'        => 'post__in',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'   => '_stock_status',
                    'value' => 'instock',
                ],
            ],
        ];

        $tax_query = [];
        if (!empty($include_cats)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $include_cats,
            ];
        }
        if (!empty($exclude_cats)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $exclude_cats,
                'operator' => 'NOT IN',
            ];
        }
        if (!empty($tax_query)) {
            if (count($tax_query) > 1) {
                $tax_query['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_query;
        }

        $q = new WP_Query($args);
        return array_map('intval', $q->posts);
    }

    private static function query_products($order_args, $include_cats, $exclude_cats, $exclude_ids, $limit) {
        $args = array_merge([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'   => '_stock_status',
                    'value' => 'instock',
                ],
            ],
        ], $order_args);

        if (!empty($exclude_ids)) {
            $args['post__not_in'] = array_map('intval', $exclude_ids);
        }

        $tax_query = [];
        if (!empty($include_cats)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $include_cats,
            ];
        }
        if (!empty($exclude_cats)) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $exclude_cats,
                'operator' => 'NOT IN',
            ];
        }
        if (!empty($tax_query)) {
            if (count($tax_query) > 1) {
                $tax_query['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_query;
        }

        $q = new WP_Query($args);
        return array_map('intval', $q->posts);
    }

    public static function clear_trending_cache() {
        delete_transient('galado_cs_trending_30d');
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
