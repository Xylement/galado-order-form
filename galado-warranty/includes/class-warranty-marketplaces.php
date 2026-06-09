<?php
/**
 * Marketplace registry — shared list used by the form dropdown,
 * the admin filters, and the auto-approve lookup logic.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Marketplaces {

    /**
     * Slug => display label. Order here is the display order.
     * Direct-website purchases get 6-month warranty automatically, so the
     * registration form only covers third-party marketplaces.
     */
    public static function all() {
        return [
            'shopee' => 'Shopee',
            'lazada' => 'Lazada',
            'tiktok' => 'TikTok Shop',
        ];
    }

    /**
     * Example order-number format per marketplace — shown as the input
     * placeholder so customers know what shape we expect.
     */
    public static function order_examples() {
        return [
            'shopee' => '260609KXBRPS2K',
            'lazada' => '504161478968273',
            'tiktok' => '584342495395677289',
        ];
    }

    public static function order_example($slug) {
        $examples = self::order_examples();
        return $examples[$slug] ?? '';
    }

    public static function label($slug) {
        $all = self::all();
        return $all[$slug] ?? ucfirst((string) $slug);
    }

    public static function is_valid($slug) {
        return array_key_exists($slug, self::all());
    }
}
