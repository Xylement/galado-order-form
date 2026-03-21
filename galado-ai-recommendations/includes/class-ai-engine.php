<?php
/**
 * AI Engine — calls Claude or GPT API for personalised recommendations
 * Supports both Anthropic and OpenAI providers
 */

if (!defined('ABSPATH')) exit;

class GAIR_AI_Engine {

    /**
     * Get AI-powered product recommendations
     * Returns HTML of product cards or WP_Error
     */
    public static function get_recommendations($context = 'homepage', $current_product_id = 0) {
        $settings = get_option('gair_settings', []);

        if (empty($settings['enabled'])) {
            return new WP_Error('disabled', 'AI Recommendations is disabled');
        }

        // Check cache first
        $cached = self::get_cached_recommendations($context);
        if ($cached !== false) {
            return $cached;
        }

        // Get customer profile summary
        $profile_summary = GAIR_Profile_Builder::get_profile_summary();

        // Get available products catalog summary
        $catalog = self::get_catalog_summary($current_product_id);

        // Build the AI prompt
        $prompt = self::build_prompt($profile_summary, $catalog, $context, $current_product_id);

        // Call AI provider
        $provider = $settings['provider'] ?? 'anthropic';
        $product_ids = self::call_ai($prompt, $provider, $settings);

        if (is_wp_error($product_ids)) {
            // Fallback: return popular products
            $product_ids = self::get_fallback_products($current_product_id);
        }

        // Render product cards
        $html = self::render_recommendations($product_ids, $settings);

        // Cache the result
        $cache_hours = intval($settings['cache_hours'] ?? 6);
        self::cache_recommendations($context, $html, $cache_hours);

        return $html;
    }

    /**
     * Build the AI prompt
     */
    private static function build_prompt($profile, $catalog, $context, $current_product_id) {
        $max = intval(get_option('gair_settings', [])['max_products'] ?? 4);
        $current_name = '';

        if ($current_product_id > 0) {
            $p = wc_get_product($current_product_id);
            $current_name = $p ? $p->get_name() : '';
        }

        $prompt = "You are a product recommendation engine for GALADO, a premium phone case customisation brand in Malaysia.\n\n";
        $prompt .= "CUSTOMER PROFILE:\n{$profile}\n\n";

        if ($current_name) {
            $prompt .= "CURRENTLY VIEWING: {$current_name} (ID: {$current_product_id})\n\n";
        }

        $prompt .= "AVAILABLE PRODUCTS:\n{$catalog}\n\n";

        $prompt .= "CONTEXT: Customer is on the {$context} page.\n\n";

        $prompt .= "TASK: Select exactly {$max} product IDs that this customer is most likely to buy. ";
        $prompt .= "Consider their colour preferences, device model, browsing history, and style. ";
        $prompt .= "Don't recommend products they've already purchased. ";

        if ($current_product_id > 0) {
            $prompt .= "Don't recommend the product they're currently viewing. ";
            $prompt .= "Prefer complementary products (accessories if viewing a case, cases if viewing an accessory). ";
        }

        $prompt .= "\nRESPOND WITH ONLY a JSON array of product IDs, nothing else. Example: [123, 456, 789, 101]";

        return $prompt;
    }

    /**
     * Get catalog summary for the AI prompt
     */
    private static function get_catalog_summary($exclude_id = 0) {
        // Get a mix of products: popular + recent + on-sale
        $products = [];

        // Popular products
        $popular = wc_get_products([
            'limit'    => 30,
            'status'   => 'publish',
            'orderby'  => 'meta_value_num',
            'meta_key' => 'total_sales',
            'order'    => 'DESC',
            'stock_status' => 'instock',
            'exclude'  => $exclude_id ? [$exclude_id] : [],
        ]);
        $products = array_merge($products, $popular);

        // Recent products
        $recent = wc_get_products([
            'limit'    => 15,
            'status'   => 'publish',
            'orderby'  => 'date',
            'order'    => 'DESC',
            'stock_status' => 'instock',
            'exclude'  => $exclude_id ? [$exclude_id] : [],
        ]);
        $products = array_merge($products, $recent);

        // Deduplicate
        $seen = [];
        $unique = [];
        foreach ($products as $p) {
            if (!in_array($p->get_id(), $seen)) {
                $seen[] = $p->get_id();
                $unique[] = $p;
            }
        }

        // Build catalog string
        $lines = [];
        foreach (array_slice($unique, 0, 40) as $product) {
            $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
            $price = $product->get_price();
            $sales = get_post_meta($product->get_id(), 'total_sales', true);

            $lines[] = sprintf(
                "ID:%d | %s | RM%s | Categories: %s | Sales: %s",
                $product->get_id(),
                $product->get_name(),
                $price,
                implode(', ', $cats),
                $sales ?: '0'
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Call AI provider
     */
    private static function call_ai($prompt, $provider, $settings) {
        if ($provider === 'openai') {
            return self::call_openai($prompt, $settings);
        }
        return self::call_anthropic($prompt, $settings);
    }

    /**
     * Call Anthropic Claude API
     */
    private static function call_anthropic($prompt, $settings) {
        $api_key = $settings['anthropic_key'] ?? '';
        if (empty($api_key)) {
            return new WP_Error('no_key', 'Anthropic API key not configured');
        }

        $model = $settings['anthropic_model'] ?? 'claude-sonnet-4-20250514';

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version'  => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => 200,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'Unknown API error');
        }

        $text = $body['content'][0]['text'] ?? '';
        return self::parse_product_ids($text);
    }

    /**
     * Call OpenAI GPT API
     */
    private static function call_openai($prompt, $settings) {
        $api_key = $settings['openai_key'] ?? '';
        if (empty($api_key)) {
            return new WP_Error('no_key', 'OpenAI API key not configured');
        }

        $model = $settings['openai_model'] ?? 'gpt-4o-mini';

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => 200,
                'messages'   => [
                    ['role' => 'system', 'content' => 'You are a product recommendation engine. Respond only with a JSON array of product IDs.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'Unknown API error');
        }

        $text = $body['choices'][0]['message']['content'] ?? '';
        return self::parse_product_ids($text);
    }

    /**
     * Parse product IDs from AI response
     */
    private static function parse_product_ids($text) {
        // Extract JSON array from response
        if (preg_match('/\[[\d,\s]+\]/', $text, $matches)) {
            $ids = json_decode($matches[0], true);
            if (is_array($ids)) {
                return array_map('absint', $ids);
            }
        }
        return new WP_Error('parse_error', 'Could not parse AI response: ' . $text);
    }

    /**
     * Fallback: get popular products when AI fails
     */
    private static function get_fallback_products($exclude_id = 0) {
        $max = intval(get_option('gair_settings', [])['max_products'] ?? 4);

        $products = wc_get_products([
            'limit'    => $max * 2,
            'status'   => 'publish',
            'orderby'  => 'meta_value_num',
            'meta_key' => 'total_sales',
            'order'    => 'DESC',
            'stock_status' => 'instock',
            'exclude'  => $exclude_id ? [$exclude_id] : [],
        ]);

        $ids = [];
        foreach ($products as $p) {
            $ids[] = $p->get_id();
        }

        shuffle($ids);
        return array_slice($ids, 0, $max);
    }

    /**
     * Render product cards from IDs
     */
    private static function render_recommendations($product_ids, $settings) {
        if (empty($product_ids)) return '';

        $max = intval($settings['max_products'] ?? 4);
        $product_ids = array_slice($product_ids, 0, $max);

        ob_start();
        echo '<div class="gair-products-grid">';

        foreach ($product_ids as $id) {
            $product = wc_get_product($id);
            if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) continue;

            $image = $product->get_image('woocommerce_thumbnail');
            $name = $product->get_name();
            $price = $product->get_price_html();
            $permalink = $product->get_permalink();
            $rating = $product->get_average_rating();
            ?>
            <div class="gair-product-card">
                <a href="<?php echo esc_url($permalink); ?>" class="gair-product-card__image">
                    <?php echo $image; ?>
                    <?php if ($product->is_on_sale()): ?>
                        <span class="gair-product-card__badge">Sale</span>
                    <?php endif; ?>
                </a>
                <div class="gair-product-card__info">
                    <a href="<?php echo esc_url($permalink); ?>" class="gair-product-card__name"><?php echo esc_html($name); ?></a>
                    <?php if ($rating > 0): ?>
                        <div class="gair-product-card__rating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span class="star <?php echo $i <= round($rating) ? 'filled' : ''; ?>">★</span>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                    <div class="gair-product-card__price"><?php echo $price; ?></div>
                </div>
            </div>
            <?php
        }

        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Cache management
     */
    private static function get_cached_recommendations($context) {
        global $wpdb;
        $session_id = GAIR_Tracker::get_session_id();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT recommendations_cache FROM {$wpdb->prefix}gair_profiles
                 WHERE session_id = %s AND cache_expires > NOW()",
                $session_id
            )
        );

        if ($row && !empty($row->recommendations_cache)) {
            $cache = json_decode($row->recommendations_cache, true);
            if (isset($cache[$context])) {
                return $cache[$context];
            }
        }

        return false;
    }

    private static function cache_recommendations($context, $html, $hours = 6) {
        global $wpdb;
        $session_id = GAIR_Tracker::get_session_id();

        // Get existing cache
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT recommendations_cache FROM {$wpdb->prefix}gair_profiles WHERE session_id = %s",
                $session_id
            )
        );

        $cache = [];
        if ($row && !empty($row->recommendations_cache)) {
            $cache = json_decode($row->recommendations_cache, true) ?: [];
        }

        $cache[$context] = $html;

        $wpdb->update(
            $wpdb->prefix . 'gair_profiles',
            [
                'recommendations_cache' => wp_json_encode($cache),
                'cache_expires' => gmdate('Y-m-d H:i:s', time() + ($hours * 3600)),
            ],
            ['session_id' => $session_id],
            ['%s', '%s'],
            ['%s']
        );
    }
}
