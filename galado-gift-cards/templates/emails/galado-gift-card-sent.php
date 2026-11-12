<?php
/**
 * "Your gift card was sent", to the buyer (HTML). Shows only the end of each code.
 * Override: yourtheme/woocommerce/emails/galado-gift-card-sent.php
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $recipient_name
 * @var string   $recipient_email
 * @var array[]  $cards
 * @var string   $single_use_rule
 * @var string   $additional_content
 * @var WC_Email $email
 */

if (!defined('ABSPATH')) {
    exit;
}

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p><?php /* translators: 1: recipient name, 2: recipient email */ printf(esc_html__('We have emailed your gift card to %1$s (%2$s).', 'galado-gift-cards'), esc_html($recipient_name), esc_html($recipient_email)); ?></p>

<?php foreach ($cards as $card) : ?>
<table cellspacing="0" cellpadding="8" style="width:100%;margin:16px 0;border:1px solid #e5e5e5;">
    <tr><th style="text-align:left;"><?php esc_html_e('Card value', 'galado-gift-cards'); ?></th><td><?php echo esc_html(Galado_GC_Cart::rm($card['value'])); ?></td></tr>
    <tr><th style="text-align:left;"><?php esc_html_e('Code', 'galado-gift-cards'); ?></th><td><?php /* translators: %s: last four characters */ printf(esc_html__('ending %s', 'galado-gift-cards'), esc_html(Galado_GC_Codes::tail($card['code']))); ?></td></tr>
    <tr><th style="text-align:left;"><?php esc_html_e('Valid until', 'galado-gift-cards'); ?></th><td><?php echo esc_html(Galado_GC_Time::human_date($card['expires'])); ?></td></tr>
</table>
<?php endforeach; ?>

<p><strong><?php echo esc_html($single_use_rule); ?></strong></p>
<p><?php /* translators: %s: order number */ printf(esc_html__('Order #%s', 'galado-gift-cards'), esc_html($order->get_order_number())); ?></p>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
