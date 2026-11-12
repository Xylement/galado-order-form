<?php
/**
 * Fixed rules (constants) and the three limits Clement may change (options, edited under
 * WooCommerce > Settings > Products > Gift cards).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Config {

    // Decided 25 Sep 2026 (SPEC-2 GC2, GC3). Changing these is a product decision, not a setting.
    const PRESET_AMOUNTS = [50, 100, 150, 200, 300];
    const CUSTOM_MIN = 30;
    const VALID_YEARS = 3;
    const DELIVERY_HOUR = 9;              // Malaysian time
    const MESSAGE_MAX = 200;              // characters
    const NAME_MAX = 60;                  // characters
    const DELIVERY_MAX_DAYS_AHEAD = 365;
    const TIMEZONE = 'Asia/Kuala_Lumpur';
    const TEXT_DOMAIN = 'galado-gift-cards';

    // The three settings (GC8). Defaults are the numbers explained to Clement on 25 Sep 2026.
    const OPT_MAX_CARD = 'galado_gift_cards_max_card';
    const OPT_MAX_ORDER = 'galado_gift_cards_max_order';
    const OPT_RISK_HOLD = 'galado_gift_cards_risk_hold';
    const DEFAULT_MAX_CARD = 1000;
    const DEFAULT_MAX_ORDER = 2000;

    const SETTINGS_SECTION = 'galado_gift_cards';

    public static function init() {
        add_filter('woocommerce_get_sections_products', [__CLASS__, 'add_section']);
        add_filter('woocommerce_get_settings_products', [__CLASS__, 'settings'], 10, 2);
        add_filter('woocommerce_admin_settings_sanitize_option_' . self::OPT_MAX_CARD, [__CLASS__, 'sanitize_max_card']);
        add_filter('woocommerce_admin_settings_sanitize_option_' . self::OPT_MAX_ORDER, [__CLASS__, 'sanitize_max_order']);
    }

    /** RM value one card may hold. */
    public static function max_card() {
        $v = (int) get_option(self::OPT_MAX_CARD, self::DEFAULT_MAX_CARD);
        return $v >= self::CUSTOM_MIN ? $v : self::DEFAULT_MAX_CARD;
    }

    /** RM value of gift cards one order may hold. Never below one card's maximum. */
    public static function max_order() {
        $v = (int) get_option(self::OPT_MAX_ORDER, self::DEFAULT_MAX_ORDER);
        return max($v >= self::CUSTOM_MIN ? $v : self::DEFAULT_MAX_ORDER, self::max_card());
    }

    /** Hold delivery of cards paid with a card Stripe rated elevated or highest risk. */
    public static function risk_hold_enabled() {
        return 'no' !== get_option(self::OPT_RISK_HOLD, 'yes');
    }

    public static function add_section($sections) {
        $sections[self::SETTINGS_SECTION] = __('Gift cards', 'galado-gift-cards');
        return $sections;
    }

    public static function settings($settings, $section) {
        if (self::SETTINGS_SECTION !== $section) {
            return $settings;
        }
        return [
            [
                'title' => __('GALADO gift cards', 'galado-gift-cards'),
                'type'  => 'title',
                'desc'  => __('Limits against stolen payment cards. Changes apply to new carts at once.', 'galado-gift-cards'),
                'id'    => 'galado_gift_cards_limits',
            ],
            [
                'title'             => __('Most one card can hold (RM)', 'galado-gift-cards'),
                'id'                => self::OPT_MAX_CARD,
                'type'              => 'number',
                'default'           => (string) self::DEFAULT_MAX_CARD,
                'custom_attributes' => ['min' => (string) self::CUSTOM_MIN, 'step' => '1'],
                'desc_tip'          => __('Also the top of the custom amount.', 'galado-gift-cards'),
            ],
            [
                'title'             => __('Most gift cards in one order (RM)', 'galado-gift-cards'),
                'id'                => self::OPT_MAX_ORDER,
                'type'              => 'number',
                'default'           => (string) self::DEFAULT_MAX_ORDER,
                'custom_attributes' => ['min' => (string) self::CUSTOM_MIN, 'step' => '1'],
            ],
            [
                'title'   => __('Hold risky payments', 'galado-gift-cards'),
                'id'      => self::OPT_RISK_HOLD,
                'type'    => 'checkbox',
                'default' => 'yes',
                'desc'    => __('Hold delivery when Stripe rates the payment elevated or highest risk, until an admin releases it from the order screen.', 'galado-gift-cards'),
            ],
            ['type' => 'sectionend', 'id' => 'galado_gift_cards_limits'],
        ];
    }

    public static function sanitize_max_card($value) {
        $v = (int) $value;
        return (string) ($v >= self::CUSTOM_MIN ? $v : self::DEFAULT_MAX_CARD);
    }

    public static function sanitize_max_order($value) {
        $v = (int) $value;
        return (string) ($v >= self::CUSTOM_MIN ? $v : self::DEFAULT_MAX_ORDER);
    }
}
