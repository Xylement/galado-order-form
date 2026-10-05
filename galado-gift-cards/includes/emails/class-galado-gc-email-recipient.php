<?php
/**
 * The gift card itself, emailed to the recipient on the chosen date.
 * Templates: emails/galado-gift-card-recipient.php and emails/plain/galado-gift-card-recipient.php,
 * overridable from the theme as yourtheme/woocommerce/emails/<same name>. G-Send supplies the
 * final HTML; these are plain placeholders with every required fact.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Email_Recipient extends WC_Email {

    /** @var WC_Order_Item_Product|null */
    public $item;
    /** @var array[] [code, value, expires] */
    public $cards = [];

    public function __construct() {
        $this->id = 'galado_gift_card_recipient';
        $this->customer_email = true;
        $this->title = __('Gift card (to the recipient)', 'galado-gift-cards');
        $this->description = __('The gift card code, sent to the recipient on the delivery date the buyer chose. Keep this enabled: disabling it means cards are never delivered.', 'galado-gift-cards');
        $this->template_base = GALADO_GC_DIR . 'templates/';
        $this->template_html = 'emails/galado-gift-card-recipient.php';
        $this->template_plain = 'emails/plain/galado-gift-card-recipient.php';
        $this->placeholders = ['{buyer_name}' => '', '{recipient_name}' => '', '{card_value}' => ''];
        parent::__construct();
    }

    public function get_default_subject() {
        return __('{buyer_name} sent you a GALADO gift card', 'galado-gift-cards');
    }

    public function get_default_heading() {
        return __('A GALADO gift card for you', 'galado-gift-cards');
    }

    /** @return bool true when the email was handed to wp_mail successfully. */
    public function trigger($order, $item, array $cards) {
        $this->setup_locale();
        $this->object = $order;
        $this->item = $item;
        $this->cards = $cards;
        $this->recipient = (string) $item->get_meta(Galado_GC_Cart::META_EMAIL);
        $this->placeholders['{buyer_name}'] = self::buyer_name($order);
        $this->placeholders['{recipient_name}'] = (string) $item->get_meta(Galado_GC_Cart::META_NAME);
        $this->placeholders['{card_value}'] = $cards ? Galado_GC_Cart::rm(array_sum(array_column($cards, 'value'))) : '';
        $sent = false;
        if ($this->is_enabled() && $this->get_recipient() && $cards) {
            $sent = (bool) $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }
        $this->restore_locale();
        return $sent;
    }

    public function get_content_html() {
        return wc_get_template_html($this->template_html, $this->template_args(false), '', $this->template_base);
    }

    public function get_content_plain() {
        return wc_get_template_html($this->template_plain, $this->template_args(true), '', $this->template_base);
    }

    private function template_args($plain) {
        return [
            'order'              => $this->object,
            'email_heading'      => $this->get_heading(),
            'recipient_name'     => $this->placeholders['{recipient_name}'],
            'buyer_name'         => $this->placeholders['{buyer_name}'],
            'message'            => $this->item ? (string) $this->item->get_meta(Galado_GC_Cart::META_MESSAGE) : '',
            'design_image'       => Galado_GC_Designs::image_url($this->item ? $this->item->get_meta(Galado_GC_Designs::META) : ''),
            'design_headline'    => Galado_GC_Designs::headline($this->item ? $this->item->get_meta(Galado_GC_Designs::META) : ''),
            'cards'              => $this->cards,
            'where_to_use'       => self::where_to_use(),
            'single_use_rule'    => self::single_use_rule(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $plain,
            'email'              => $this,
        ];
    }

    public static function buyer_name($order) {
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        return '' !== $name ? $name : __('Someone', 'galado-gift-cards');
    }

    public static function where_to_use() {
        return __('Use it at checkout on galado.com.my, in the promo code box in the GALADO app, or at our counter.', 'galado-gift-cards');
    }

    public static function single_use_rule() {
        return __('Single use: spend it in one order. Any unused value is lost.', 'galado-gift-cards');
    }
}
