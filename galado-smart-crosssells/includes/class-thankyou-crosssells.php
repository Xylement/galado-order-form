<?php
/**
 * Thank you / order received page cross-sells
 */

if (!defined('ABSPATH')) exit;

class Galado_Thankyou_Crosssells {

    public static function init() {
        if (get_option('galado_cs_enable_thankyou', 'yes') !== 'yes') return;

        add_action('woocommerce_thankyou', [__CLASS__, 'display'], 20);
    }

    public static function display($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Get products from the completed order to use as basis for recommendations
        $exclude_ids = [];
        foreach ($order->get_items() as $item) {
            $exclude_ids[] = $item->get_product_id();
        }

        // Get recommendations excluding what they just bought
        $recommendations = Galado_Crosssell_Engine::get_recommendations(4, $exclude_ids);

        if (empty($recommendations)) return;

        $title = get_option('galado_cs_thankyou_title', 'Customers Also Love');
        ?>
        <div class="galado-cs-section galado-cs-thankyou">
            <h2 class="galado-cs-title"><?php echo esc_html($title); ?></h2>
            <p class="galado-cs-subtitle">Add these to your collection next time</p>
            <div class="galado-cs-grid">
                <?php foreach ($recommendations as $product): ?>
                    <?php echo Galado_Crosssell_Engine::render_product_card($product); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
