<?php
/**
 * HYBRID AI Engine — rule-based for anonymous visitors, AI for engaged users
 * Dramatically reduces API costs while maintaining personalisation quality
 */

if (!defined('ABSPATH')) exit;

class GAIR_AI_Engine {

    // Known bot user agents to block from API calls
    private static $bot_patterns = [
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
        'yandexbot', 'facebot', 'facebookexternalhit', 'twitterbot',
        'linkedinbot', 'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot',
        'rogerbot', 'sogoubot', 'exabot', 'ia_archiver', 'archive.org',
        'uptimerobot', 'pingdom', 'gptbot', 'claudebot', 'perplexitybot',
        'applebot', 'bytespider', 'petalbot', 'headlesschrome',
        'lighthouse', 'pagespeed', 'gtmetrix', 'screaming', 'ahrefs',
        'majestic', 'blexbot', 'megaindex', 'serpstat', 'crawler',
        'spider', 'bot/', '/bot', 'python-requests', 'curl/', 'wget/',
        'go-http-client', 'java/', 'php/', 'ruby', 'cfnetwork',
    ];

    /**
     * Get product recommendations — hybrid approach
     */
    public static function get_recommendations($context = 'homepage', $current_product_id = 0) {
        $settings = get_option('gair_settings', []);

        if (empty($settings['enabled'])) {
            return new WP_Error('disabled', 'AI Recommendations is disabled');
        }

        // Block bots entirely
        if (self::is_bot()) {
            return self::render_recommendations(self::get_fallback_products($current_product_id), $settings);
        }

        // Check cache first (IP + user based, not session)
        $cache_key = self::get_cache_key($context, $current_product_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Determine if this visitor qualifies for AI recommendations
        $use_ai = self::should_use_ai($settings);

        if ($use_ai) {
            $product_ids = self::get_ai_recommendations($context, $current_product_id, $settings);
        } else {
            $product_ids = self::get_rule_based_recommendations($context, $current_product_id);
        }

        // Render and cache
        $html = self::render_recommendations($product_ids, $settings);
        $cache_hours = intval($settings['cache_hours'] ?? 24);
        set_transient($cache_key, $html, $cache_hours * HOUR_IN_SECONDS);

        return $html;
    }

    /**
     * Check if current request is from a bot
     */
    private static function is_bot() {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

        if (empty($ua)) return true; // No UA = likely bot

        foreach (self::$bot_patterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a stable cache key based on IP + user ID + context
     * (not session — survives browser restarts)
     */
    private static function get_cache_key($context, $product_id) {
        $user_id = get_current_user_id();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // For logged-in users, cache by user ID (survives IP changes)
        // For anonymous, cache by IP
        $identifier = $user_id > 0 ? 'u' . $user_id : 'ip' . md5($ip);

        return 'gair_recs_' . $identifier . '_' . $context . '_' . $product_id;
    }

    /**
     * Decide whether to use AI or rules for this visitor
     * AI only for: logged-in users OR visitors with 5+ page views
     * Also checks daily budget cap
     */
    private static function should_use_ai($settings) {
        // Check if AI keys are configured
        $provider = $settings['provider'] ?? 'anthropic';
        if ($provider === 'anthropic' && empty($settings['anthropic_key'])) return false;
        if ($provider === 'openai' && empty($settings['openai_key'])) return false;

        // Check daily budget cap
        $daily_cap = floatval($settings['daily_budget'] ?? 2.00);
        $today_spend = floatval(get_transient('gair_daily_spend') ?: 0);
        if ($today_spend >= $daily_cap) {
            return false; // Budget exhausted, fall back to rules
        }

        // Logged-in user with purchase history = always AI
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $order_count = wc_get_customer_order_count($user_id);
            if ($order_count > 0) return true;
        }

        // Anonymous: check page view count from tracker
        $profile = GAIR_Profile_Builder::get_profile();
        $total_views = intval($profile['total_views'] ?? 0);

        // Only use AI for engaged visitors (5+ product views)
        $min_views = intval($settings['min_views_for_ai'] ?? 5);
        return $total_views >= $min_views;
    }

    /**
     * Track API spend for daily budget cap
     */
    private static function track_spend($provider) {
        // Estimated cost per call
        $costs = [
            'anthropic' => 0.001,  // Haiku ~$0.001/call
            'openai'    => 0.002,  // GPT-4o-mini ~$0.002/call
        ];

        $cost = $costs[$provider] ?? 0.002;
        $current = floatval(get_transient('gair_daily_spend') ?: 0);
        $new_total = $current + $cost;

        // Set with expiry at end of day
        $seconds_until_midnight = strtotime('tomorrow') - time();
        set_transient('gair_daily_spend', $new_total, $seconds_until_midnight);

        // Also track call count for the dashboard
        $calls_today = intval(get_transient('gair_daily_calls') ?: 0);
        set_transient('gair_daily_calls', $calls_today + 1, $seconds_until_midnight);
    }

    // =========================================================================
    // RULE-BASED ENGINE (free — for anonymous / low-engagement visitors)
    // =========================================================================

    /**
     * Smart rule-based recommendations using browsing data
     */
    private static function get_rule_based_recommendations($context, $current_product_id) {
        $profile = GAIR_Profile_Builder::get_profile();
        $max = intval(get_option('gair_settings', [])['max_products'] ?? 4);
        $exclude = $current_product_id ? [$current_product_id] : [];

        // Merge purchased + carted for exclusion
        $exclude = array_merge($exclude, $profile['purchased_products'] ?? [], $profile['carted_products'] ?? []);
        $exclude = array_unique(array_filter($exclude));

        $candidates = [];

        // Strategy 1: Same device model products
        if (!empty($profile['device_models'])) {
            $top_device = array_key_first($profile['device_models']);
            $device_products = self::get_products_by_category($top_device, 8, $exclude);
            $candidates = array_merge($candidates, $device_products);
        }

        // Strategy 2: Products matching colour preferences
        if (!empty($profile['colour_preferences'])) {
            $top_colours = array_slice(array_keys($profile['colour_preferences']), 0, 3);
            foreach ($top_colours as $colour) {
                $colour_products = self::search_products_by_name($colour, 4, $exclude);
                $candidates = array_merge($candidates, $colour_products);
            }
        }

        // Strategy 3: Same category products
        if (!empty($profile['viewed_categories'])) {
            $top_cats = array_slice(array_keys($profile['viewed_categories']), 0, 3);
            foreach ($top_cats as $cat) {
                $cat_products = self::get_products_by_category($cat, 4, $exclude);
                $candidates = array_merge($candidates, $cat_products);
            }
        }

        // Strategy 4: Complementary products (if on product page)
        if ($context === 'product' && $current_product_id > 0) {
            $current_cats = wp_get_post_terms($current_product_id, 'product_cat', ['fields' => 'slugs']);
            $is_case = false;
            foreach ($current_cats as $cat) {
                if (strpos($cat, 'case') !== false || strpos($cat, 'guard') !== false) {
                    $is_case = true;
                    break;
                }
            }

            // If viewing a case, suggest accessories. If viewing accessory, suggest cases.
            $complementary_cats = $is_case
                ? ['charms', 'charm', 'strap', 'accessories', 'tempered-glass']
                : ['cases', 'custom-name', 'custom-drawing', 'custom-picture'];

            foreach ($complementary_cats as $comp_cat) {
                $comp_products = self::get_products_by_category($comp_cat, 4, $exclude);
                $candidates = array_merge($candidates, $comp_products);
            }
        }

        // Deduplicate
        $seen = [];
        $unique = [];
        foreach ($candidates as $id) {
            if (!in_array($id, $seen) && !in_array($id, $exclude)) {
                $seen[] = $id;
                $unique[] = $id;
            }
        }

        // If not enough candidates, pad with popular products
        if (count($unique) < $max) {
            $popular = self::get_fallback_products($current_product_id);
            foreach ($popular as $id) {
                if (!in_array($id, $seen) && !in_array($id, $exclude)) {
                    $unique[] = $id;
                    $seen[] = $id;
                }
            }
        }

        // Shuffle for variety, then trim
        shuffle($unique);
        return array_slice($unique, 0, $max);
    }

    /**
     * Get products by category slug
     */
    private static function get_products_by_category($cat_slug, $limit = 4, $exclude = []) {
        $args = [
            'limit'        => $limit,
            'status'       => 'publish',
            'stock_status' => 'instock',
            'category'     => [$cat_slug],
            'orderby'      => 'meta_value_num',
            'meta_key'     => 'total_sales',
            'order'        => 'DESC',
        ];

        if (!empty($exclude)) {
            $args['exclude'] = $exclude;
        }

        $products = wc_get_products($args);
        return array_map(function($p) { return $p->get_id(); }, $products);
    }

    /**
     * Search products by name keyword
     */
    private static function search_products_by_name($keyword, $limit = 4, $exclude = []) {
        global $wpdb;

        $exclude_sql = '';
        if (!empty($exclude)) {
            $placeholders = implode(',', array_fill(0, count($exclude), '%d'));
            $exclude_sql = "AND p.ID NOT IN ($placeholders)";
        }

        $sql = "SELECT p.ID FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock_status'
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND pm.meta_value = 'instock'
                AND LOWER(p.post_title) LIKE %s
                {$exclude_sql}
                ORDER BY p.menu_order ASC, p.ID DESC
                LIMIT %d";

        $params = ['%' . $wpdb->esc_like(strtolower($keyword)) . '%'];
        if (!empty($exclude)) {
            $params = array_merge($params, $exclude);
        }
        $params[] = $limit;

        $results = $wpdb->get_col($wpdb->prepare($sql, ...$params));
        return array_map('absint', $results ?: []);
    }

    // =========================================================================
    // AI ENGINE (paid — for logged-in / engaged visitors only)
    // =========================================================================

    /**
     * Get AI-powered recommendations
     */
    private static function get_ai_recommendations($context, $current_product_id, $settings) {
        $profile_summary = GAIR_Profile_Builder::get_profile_summary();
        $catalog = self::get_catalog_summary($current_product_id);
        $prompt = self::build_prompt($profile_summary, $catalog, $context, $current_product_id);

        $provider = $settings['provider'] ?? 'anthropic';
        $product_ids = self::call_ai($prompt, $provider, $settings);

        // Track the spend
        self::track_spend($provider);

        if (is_wp_error($product_ids)) {
            // Fallback to rule-based if AI fails
            return self::get_rule_based_recommendations($context, $current_product_id);
        }

        return $product_ids;
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
        $products = [];

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

        $recent = wc_get_products([
            'limit'    => 15,
            'status'   => 'publish',
            'orderby'  => 'date',
            'order'    => 'DESC',
            'stock_status' => 'instock',
            'exclude'  => $exclude_id ? [$exclude_id] : [],
        ]);
        $products = array_merge($products, $recent);

        $seen = [];
        $unique = [];
        foreach ($products as $p) {
            if (!in_array($p->get_id(), $seen)) {
                $seen[] = $p->get_id();
                $unique[] = $p;
            }
        }

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

        $model = $settings['anthropic_model'] ?? 'claude-haiku-4-5-20251001';

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 15,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version'  => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => 100,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) return $response;

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
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => 100,
                'messages'   => [
                    ['role' => 'system', 'content' => 'Respond only with a JSON array of product IDs.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) return $response;

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
        if (preg_match('/\[[\d,\s]+\]/', $text, $matches)) {
            $ids = json_decode($matches[0], true);
            if (is_array($ids)) {
                return array_map('absint', $ids);
            }
        }
        return new WP_Error('parse_error', 'Could not parse AI response: ' . $text);
    }

    // =========================================================================
    // SHARED UTILITIES
    // =========================================================================

    /**
     * Fallback: get popular products
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

        $ids = array_map(function($p) { return $p->get_id(); }, $products);
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
     * Get daily stats for admin dashboard
     */
    public static function get_daily_stats() {
        return [
            'calls_today' => intval(get_transient('gair_daily_calls') ?: 0),
            'spend_today' => round(floatval(get_transient('gair_daily_spend') ?: 0), 3),
            'budget_cap'  => floatval(get_option('gair_settings', [])['daily_budget'] ?? 2.00),
        ];
    }
}
