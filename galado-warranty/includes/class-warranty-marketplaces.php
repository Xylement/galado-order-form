<?php
/**
 * Marketplace registry — shared list used by the form dropdown,
 * the admin filters, and the auto-approve lookup logic.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Marketplaces {

    /**
     * Slug => display label. Order here is the display order.
     */
    public static function all() {
        return [
            'shopee'    => 'Shopee',
            'lazada'    => 'Lazada',
            'tiktok'    => 'TikTok Shop',
            'whatsapp'  => 'WhatsApp',
            'instagram' => 'Instagram',
            'facebook'  => 'Facebook',
        ];
    }

    public static function label($slug) {
        $all = self::all();
        return $all[$slug] ?? ucfirst((string) $slug);
    }

    public static function is_valid($slug) {
        return array_key_exists($slug, self::all());
    }
}
