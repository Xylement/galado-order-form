<?php
/**
 * Checkout page cross-sells - shows above payment section
 */

if (!defined('ABSPATH')) exit;

class Galado_Checkout_Crosssells {

    public static function init() {
        if (get_option('galado_cs_enable_checkout', 'yes') !== 'yes') return;

        // Show before order review section
        add_action('woocommerce_checkout_before_order_review', [__CLASS__, 'display'], 5);
    }

    public static function display() {
        $recommendations = Galado_Crosssell_Engine::get_recommendations(3);

        if (empty($recommendations)) return;

        $title = get_option('galado_cs_checkout_title', 'Last Chance to Add');
        ?>
        <div class="galado-cs-section galado-cs-checkout">
            <h3 class="galado-cs-title galado-cs-title--small"><?php echo esc_html($title); ?></h3>
            <div class="galado-cs-compact-list">
                <?php foreach ($recommendations as $product): ?>
                    <?php self::render_compact_card($product); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private static function render_compact_card($product) {
        $id = $product->get_id();
        $is_simple = $product->is_type('simple');
        ?>
        <div class="galado-cs-compact-card" data-product-id="<?php echo esc_attr($id); ?>">
            <div class="galado-cs-compact-card__image">
                <?php echo $product->get_image('thumbnail'); ?>
            </div>
            <div class="galado-cs-compact-card__info">
                <span class="galado-cs-compact-card__name"><?php echo esc_html($product->get_name()); ?></span>
                <span class="galado-cs-compact-card__price"><?php echo $product->get_price_html(); ?></span>
            </div>
            <?php if ($is_simple): ?>
                <button class="galado-cs-add-btn galado-cs-add-btn--compact" data-product-id="<?php echo esc_attr($id); ?>">
                    <span class="btn-text">+ Add</span>
                    <span class="btn-loading" style="display:none;">...</span>
                    <span class="btn-done" style="display:none;">✓</span>
                </button>
            <?php else: ?>
                <a href="<?php echo esc_url($product->get_permalink()); ?>" class="galado-cs-add-btn galado-cs-add-btn--compact galado-cs-add-btn--link">View</a>
            <?php endif; ?>
        </div>
        <?php
    }
}
