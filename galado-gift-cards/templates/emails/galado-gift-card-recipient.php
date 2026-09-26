<?php
/**
 * Gift card email to the recipient (HTML). Placeholder design: G-Send supplies the final HTML.
 * Override: yourtheme/woocommerce/emails/galado-gift-card-recipient.php
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $recipient_name
 * @var string   $buyer_name
 * @var string   $message         the buyer's message, raw: escape on output
 * @var array[]  $cards           each [code, value (RM), expires (timestamp)]
 * @var string   $where_to_use
 * @var string   $single_use_rule
 * @var string   $additional_content
 * @var WC_Email $email
 */

if (!defined('ABSPATH')) {
    exit;
}

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p><?php /* translators: %s: recipient name */ printf(esc_html__('Hi %s,', 'galado-gift-cards'), esc_html($recipient_name)); ?></p>
<p><?php /* translators: %s: buyer name */ printf(esc_html__('%s sent you a GALADO gift card.', 'galado-gift-cards'), esc_html($buyer_name)); ?></p>

<?php if ('' !== $message) : ?>
<blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #111111;"><?php echo nl2br(esc_html($message)); ?></blockquote>
<?php endif; ?>

<?php foreach ($cards as $card) : ?>
<table cellspacing="0" cellpadding="8" style="width:100%;margin:16px 0;border:1px solid #e5e5e5;">
    <tr><th style="text-align:left;"><?php esc_html_e('Card value', 'galado-gift-cards'); ?></th><td><?php echo esc_html(Galado_GC_Cart::rm($card['value'])); ?></td></tr>
    <tr><th style="text-align:left;"><?php esc_html_e('Your code', 'galado-gift-cards'); ?></th><td style="font-family:monospace;font-size:18px;letter-spacing:1px;"><strong><?php echo esc_html($card['code']); ?></strong></td></tr>
    <tr><th style="text-align:left;"><?php esc_html_e('Valid until', 'galado-gift-cards'); ?></th><td><?php echo esc_html(Galado_GC_Time::human_date($card['expires'])); ?></td></tr>
</table>
<?php endforeach; ?>

<p><?php echo esc_html($where_to_use); ?></p>
<p><strong><?php echo esc_html($single_use_rule); ?></strong></p>

<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
