<?php
/**
 * "Your gift card was sent": to the buyer, when the recipient's email goes out.
 * Shows only the end of each code: the card belongs to the recipient now, and a buyer's inbox
 * should not hold a second spendable copy.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Email_Buyer_Sent extends WC_Email {

    /** @var WC_Order_Item_Product|null */
    public $item;
    /** @var array[] */
    public $cards = [];

    public function __construct() {
        $this->id = 'galado_gift_card_buyer_sent';
        $this->customer_email = true;
        $this->title = __('Gift card sent (to the buyer)', 'galado-gift-cards');
        $this->description = __('Tells the buyer their gift card has been emailed to the recipient.', 'galado-gift-cards');
        $this->template_base = GALADO_GC_DIR . 'templates/';
        $this->template_html = 'emails/galado-gift-card-sent.php';
        $this->template_plain = 'emails/plain/galado-gift-card-sent.php';
        $this->placeholders = ['{recipient_name}' => '', '{order_number}' => ''];
        parent::__construct();
    }

    public function get_default_subject() {
        return __('Your GALADO gift card was sent to {recipient_name}', 'galado-gift-cards');
    }

    public function get_default_heading() {
        return __('Your gift card is on its way', 'galado-gift-cards');
    }

    public function trigger($order, $item, array $cards) {
        $this->setup_locale();
        $this->object = $order;
        $this->item = $item;
        $this->cards = $cards;
        $this->recipient = (string) $order->get_billing_email();
        $this->placeholders['{recipient_name}'] = (string) $item->get_meta(Galado_GC_Cart::META_NAME);
        $this->placeholders['{order_number}'] = $order->get_order_number();
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
            'recipient_email'    => $this->item ? (string) $this->item->get_meta(Galado_GC_Cart::META_EMAIL) : '',
            'cards'              => $this->cards,
            'single_use_rule'    => Galado_GC_Email_Recipient::single_use_rule(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $plain,
            'email'              => $this,
        ];
    }
}
