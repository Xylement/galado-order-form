<?php
/**
 * "Your gift card was sent", to the buyer (plain text).
 * Override: yourtheme/woocommerce/emails/plain/galado-gift-card-sent.php
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $recipient_name
 * @var string   $recipient_email
 * @var array[]  $cards
 * @var string   $single_use_rule
 * @var string   $additional_content
 */

if (!defined('ABSPATH')) {
    exit;
}

echo '= ' . wp_strip_all_tags($email_heading) . " =\n\n";
/* translators: 1: recipient name, 2: recipient email */
echo sprintf(__('We have emailed your gift card to %1$s (%2$s).', 'galado-gift-cards'), wp_strip_all_tags($recipient_name), wp_strip_all_tags($recipient_email)) . "\n\n";
foreach ($cards as $card) {
    echo __('Card value', 'galado-gift-cards') . ': ' . Galado_GC_Cart::rm($card['value']) . "\n";
    /* translators: %s: last four characters */
    echo __('Code', 'galado-gift-cards') . ': ' . sprintf(__('ending %s', 'galado-gift-cards'), Galado_GC_Codes::tail($card['code'])) . "\n";
    echo __('Valid until', 'galado-gift-cards') . ': ' . Galado_GC_Time::human_date($card['expires']) . "\n\n";
}
echo $single_use_rule . "\n\n";
/* translators: %s: order number */
echo sprintf(__('Order #%s', 'galado-gift-cards'), $order->get_order_number()) . "\n\n";
if ($additional_content) {
    echo wp_strip_all_tags(wptexturize($additional_content)) . "\n\n";
}
echo wp_strip_all_tags(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
