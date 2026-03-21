<?php
/**
 * Renders AI recommendation sections on frontend pages
 */

if (!defined('ABSPATH')) exit;

class GAIR_Recommendation_Widget {

    public static function init() {
        $settings = get_option('gair_settings', []);
        if (empty($settings['enabled'])) return;

        // Homepage
        if (!empty($settings['show_homepage'])) {
            add_action('woocommerce_after_main_content', [__CLASS__, 'show_homepage_recs'], 5);
            // Also hook into common theme locations
            add_action('storefront_homepage', [__CLASS__, 'show_homepage_recs'], 60);
        }

        // Product page
        if (!empty($settings['show_product'])) {
            add_action('woocommerce_after_single_product_summary', [__CLASS__, 'show_product_recs'], 25);
        }

        // Cart page
        if (!empty($settings['show_cart'])) {
            add_action('woocommerce_after_cart', [__CLASS__, 'show_cart_recs'], 10);
        }

        // Register shortcode for flexible placement
        add_shortcode('galado_recommendations', [__CLASS__, 'shortcode']);
    }

    /**
     * Homepage recommendations
     */
    public static function show_homepage_recs() {
        if (!is_front_page() && !is_shop()) return;
        self::render_section('homepage');
    }

    /**
     * Product page recommendations
     */
    public static function show_product_recs() {
        if (!is_product()) return;
        global $product;
        $product_id = $product ? $product->get_id() : 0;
        self::render_section('product', $product_id);
    }

    /**
     * Cart page recommendations
     */
    public static function show_cart_recs() {
        if (!is_cart()) return;
        self::render_section('cart');
    }

    /**
     * Shortcode: [galado_recommendations context="homepage"]
     */
    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'context' => 'homepage',
            'product_id' => 0,
        ], $atts);

        ob_start();
        self::render_section($atts['context'], absint($atts['product_id']));
        return ob_get_clean();
    }

    /**
     * Render the recommendation section
     * Uses AJAX loading to not block page render
     */
    private static function render_section($context, $product_id = 0) {
        $settings = get_option('gair_settings', []);
        $title = $settings['widget_title'] ?? 'Recommended for You';

        // Check for cached recommendations first (instant display)
        $cached_html = GAIR_AI_Engine::get_recommendations($context, $product_id);

        ?>
        <div class="gair-section" data-context="<?php echo esc_attr($context); ?>" data-product-id="<?php echo esc_attr($product_id); ?>">
            <div class="gair-section__header">
                <h2 class="gair-section__title"><?php echo esc_html($title); ?></h2>
                <p class="gair-section__subtitle">
                    <?php
                    switch ($context) {
                        case 'product':
                            esc_html_e('You might also like', 'galado-ai-rec');
                            break;
                        case 'cart':
                            esc_html_e('Complete your collection', 'galado-ai-rec');
                            break;
                        default:
                            esc_html_e('Picked just for you', 'galado-ai-rec');
                    }
                    ?>
                </p>
            </div>
            <div class="gair-section__content">
                <?php if (!is_wp_error($cached_html) && !empty($cached_html)): ?>
                    <?php echo $cached_html; ?>
                <?php else: ?>
                    <!-- Loading skeleton -->
                    <div class="gair-products-grid gair-loading">
                        <?php for ($i = 0; $i < ($settings['max_products'] ?? 4); $i++): ?>
                            <div class="gair-skeleton-card">
                                <div class="gair-skeleton gair-skeleton--image"></div>
                                <div class="gair-skeleton gair-skeleton--text" style="width:80%"></div>
                                <div class="gair-skeleton gair-skeleton--text" style="width:50%"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <script>
                    (function() {
                        var section = document.querySelector('.gair-section[data-context="<?php echo esc_js($context); ?>"]');
                        jQuery.post(gairData.ajaxurl, {
                            action: 'gair_get_recs',
                            nonce: gairData.nonce,
                            context: '<?php echo esc_js($context); ?>',
                            product_id: <?php echo intval($product_id); ?>
                        }, function(response) {
                            if (response.success && response.data.html) {
                                section.querySelector('.gair-section__content').innerHTML = response.data.html;
                            } else {
                                section.style.display = 'none';
                            }
                        }).fail(function() {
                            section.style.display = 'none';
                        });
                    })();
                    </script>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
