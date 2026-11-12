<?php
/**
 * Gift card codes: GIFT-XXXX-XXXX-XXXX from random_int over 31 unambiguous characters
 * (no 0, O, 1, I or L). 12 random characters = 31^12, about 7.9 x 10^17 codes (59 bits).
 *
 * WooCommerce stores and compares coupon codes in lower case (wc_format_coupon_code), so a code
 * is lower case inside WooCommerce and upper case wherever a customer reads it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Codes {

    const PREFIX = 'GIFT-';
    const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    const PATTERN = '/^GIFT-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{4}$/';
    /** Finds a code anywhere in a message, any case, to mask it. */
    const FIND_PATTERN = '/gift-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}/i';
    const META_FLAG = '_galado_gift_card';

    /** One random code. Not checked against the database: use unique(). */
    public static function generate() {
        $max = strlen(self::ALPHABET) - 1;
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= self::ALPHABET[random_int(0, $max)];
            }
            $groups[] = $chunk;
        }
        return self::PREFIX . implode('-', $groups);
    }

    /**
     * A code no coupon of any status uses yet. WooCommerce's own lookup ignores drafts, so a
     * disabled (draft) card's code would otherwise look free and could be issued twice.
     *
     * @param callable|null $exists For tests: fn(string $code): bool.
     */
    public static function unique($exists = null) {
        $exists = $exists ?: [__CLASS__, 'exists_in_db'];
        for ($try = 0; $try < 10; $try++) {
            $code = self::generate();
            if (!call_user_func($exists, $code)) {
                return $code;
            }
        }
        // 10 collisions in a 7.9 x 10^17 space means the random source is broken: refuse to guess.
        throw new RuntimeException('galado-gift-cards: could not generate a unique code');
    }

    public static function exists_in_db($code) {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title = %s LIMIT 1",
            strtolower($code)
        ));
        return !empty($id);
    }

    /** "gift 7kq4 m2xd 9pwa", "GIFT7KQ4M2XD9PWA" or "GIFT-7KQ4-M2XD-9PWA" become the canonical code, else ''. */
    public static function normalize($input) {
        $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $input));
        if (strlen($raw) !== 16 || 0 !== strpos($raw, 'GIFT')) {
            return '';
        }
        $code = 'GIFT-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4);
        return preg_match(self::PATTERN, $code) ? $code : '';
    }

    public static function looks_like_code($code) {
        return (bool) preg_match(self::PATTERN, strtoupper((string) $code));
    }

    /** A code issued by this plugin, whatever its status. */
    public static function is_gift_card_code($code) {
        static $memo = [];
        $code = strtolower((string) $code);
        if (!self::looks_like_code($code)) {
            return false;
        }
        if (!isset($memo[$code])) {
            global $wpdb;
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title = %s LIMIT 1",
                $code
            ));
            $memo[$code] = $id && 'yes' === get_post_meta($id, self::META_FLAG, true);
        }
        return $memo[$code];
    }

    /** A WC_Coupon issued by this plugin. */
    public static function is_gift_coupon($coupon) {
        return $coupon instanceof WC_Coupon && 'yes' === $coupon->get_meta(self::META_FLAG);
    }

    /** For customers and staff: upper case. */
    public static function display($code) {
        return strtoupper((string) $code);
    }

    /** Safe in order notes and logs: "ending 9PWA". Never the whole code. */
    public static function tail($code) {
        return strtoupper(substr((string) $code, -4));
    }

    /** Replace any gift card code in a message with a harmless phrase. */
    public static function mask_in_text($text, $replacement = 'your gift card') {
        return preg_replace(self::FIND_PATTERN, $replacement, (string) $text);
    }
}
