<?php
/**
 * External product add-to-cart, served by galado-studio-cart because
 * something on the site empties get_button_text() at render time (REST
 * shows the stored label, the standard template prints the default).
 * The raw _button_text meta is the source of truth here.
 */

if (!defined('ABSPATH')) exit;

global $product;
$gd_label = get_post_meta($product->get_id(), '_button_text', true);
if (!$gd_label) $gd_label = isset($button_text) && $button_text ? $button_text : __('Buy product', 'woocommerce');

do_action('woocommerce_before_add_to_cart_form'); ?>

<form class="cart" action="<?php echo esc_url($product_url); ?>" method="get">
    <?php do_action('woocommerce_before_add_to_cart_button'); ?>
    <button type="submit" class="single_add_to_cart_button button alt"><?php echo esc_html($gd_label); ?></button>
    <?php wc_query_string_form_fields($product_url); ?>
    <?php do_action('woocommerce_after_add_to_cart_button'); ?>
</form>

<?php do_action('woocommerce_after_add_to_cart_form');
