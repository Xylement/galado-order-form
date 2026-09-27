<?php
/**
 * Gift card email to the recipient (plain text).
 * Override: yourtheme/woocommerce/emails/plain/galado-gift-card-recipient.php
 *
 * @var string  $email_heading
 * @var string  $recipient_name
 * @var string  $buyer_name
 * @var string  $message
 * @var string  $design_headline
 * @var array[] $cards
 * @var string  $where_to_use
 * @var string  $single_use_rule
 * @var string  $additional_content
 */

if (!defined('ABSPATH')) {
    exit;
}

echo '= ' . wp_strip_all_tags($email_heading) . " =\n\n";
if (!empty($design_headline)) {
    echo wp_strip_all_tags($design_headline) . "\n\n";
}
/* translators: %s: recipient name */
echo sprintf(__('Hi %s,', 'galado-gift-cards'), wp_strip_all_tags($recipient_name)) . "\n\n";
/* translators: %s: buyer name */
echo sprintf(__('%s sent you a GALADO gift card.', 'galado-gift-cards'), wp_strip_all_tags($buyer_name)) . "\n\n";
if ('' !== $message) {
    echo '"' . wp_strip_all_tags($message) . "\"\n\n";
}
foreach ($cards as $card) {
    echo __('Card value', 'galado-gift-cards') . ': ' . Galado_GC_Cart::rm($card['value']) . "\n";
    echo __('Your code', 'galado-gift-cards') . ': ' . $card['code'] . "\n";
    echo __('Valid until', 'galado-gift-cards') . ': ' . Galado_GC_Time::human_date($card['expires']) . "\n\n";
}
echo $where_to_use . "\n\n";
echo $single_use_rule . "\n\n";
if ($additional_content) {
    echo wp_strip_all_tags(wptexturize($additional_content)) . "\n\n";
}
echo wp_strip_all_tags(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
