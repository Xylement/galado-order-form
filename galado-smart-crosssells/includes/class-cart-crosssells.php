<?php
/**
 * Cart page cross-sells display
 */

if (!defined('ABSPATH')) exit;

class Galado_Cart_Crosssells {

    public static function init() {
        if (get_option('galado_cs_enable_cart', 'yes') !== 'yes') return;

        // Replace default WooCommerce cross-sells with ours
        remove_action('woocommerce_cart_collaterals', 'woocommerce_cross_sell_display');
        add_action('woocommerce_cart_collaterals', [__CLASS__, 'display'], 10);
    }

    public static function display() {
        $recommendations = Galado_Crosssell_Engine::get_recommendations();

        if (empty($recommendations)) return;

        $title = get_option('galado_cs_cart_title', 'Complete Your Setup');
        ?>
        <div class="galado-cs-section galado-cs-cart">
            <h2 class="galado-cs-title"><?php echo esc_html($title); ?></h2>
            <p class="galado-cs-subtitle">These pair perfectly with your order</p>
            <div class="galado-cs-grid">
                <?php foreach ($recommendations as $product): ?>
                    <?php echo Galado_Crosssell_Engine::render_product_card($product); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
