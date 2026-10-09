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
     * registration form only covers channels where the default warranty
     * is shorter (marketplace orders + in-store/POS sales).
     */
    public static function all() {
        return [
            'shopee' => 'Shopee',
            'lazada' => 'Lazada',
            'tiktok' => 'TikTok Shop',
            'retail' => 'Retail Walk In',
        ];
    }

    /**
     * Example order-number format per marketplace — shown as the input
     * placeholder so customers know what shape we expect. Retail walk-in
     * receipts use the GT2-XXX numbering printed on the POS slip.
     */
    public static function order_examples() {
        return [
            'shopee' => '260609KXBRPS2K',
            'lazada' => '504161478968273',
            'tiktok' => '584342495395677289',
            'retail' => 'GT2-422',
        ];
    }

    public static function order_example($slug) {
        $examples = self::order_examples();
        return $examples[$slug] ?? '';
    }

    /**
     * What a marketplace's order number must look like, for marketplaces that
     * have a rule (only Shopee so far; Lazada, TikTok Shop and Retail Walk In
     * take any number). The form hands the same rule to the browser as data
     * attributes, so the on-the-spot check and this server check never drift.
     *
     * Shopee (v1.12.1): customers kept registering their SPX tracking number
     * (22 registrations, 21 rejected at review: 21 of the 28 rejections ever).
     * A Shopee Order ID is 14 characters and starts with the order date as
     * YYMMDD; 21,839 of the 21,844 Shopee orders in the sheet fit.
     *   pattern          the normalised number must match it (PCRE and JS alike)
     *   dated            its first 6 digits are a real YYMMDD date, not after tomorrow in KL
     *   tracking_prefix  numbers starting with this are courier tracking numbers
     */
    public static function order_rules() {
        return [
            'shopee' => [
                'pattern'         => '^\d{6}[A-Z0-9]{8}$',
                'dated'           => true,
                'tracking_prefix' => 'SPX',
                'messages'        => [
                    'tracking' => 'That looks like your SPX tracking number, not your Shopee Order ID. Your Order ID has 14 characters and starts with your order date, like 260609KXBRPS2K. In the Shopee app, go to Me > My Purchases, open the order and copy the Order ID.',
                    'format'   => "That doesn't look like a Shopee Order ID. It has 14 characters and starts with your order date, like 260609KXBRPS2K. In the Shopee app, go to Me > My Purchases, open the order and copy the Order ID.",
                ],
            ],
        ];
    }

    /**
     * The form of an order number that gets checked and saved. Every marketplace
     * is trimmed; one with a rule also loses spaces and a leading # (paste noise
     * from the app, and one live registration typed "#2609...") and is upper-cased,
     * which is how the order sheet holds it. The order_number column is
     * case-insensitive (utf8mb4_unicode_520_ci), so this cannot clash with rows
     * already saved; those stay exactly as they are.
     */
    public static function normalise_order_number($slug, $order) {
        $order = trim((string) $order);
        if (!isset(self::order_rules()[$slug])) {
            return $order;
        }
        // Exactly the characters JavaScript's \s matches (incl. the non-breaking and
        // ideographic spaces and U+FEFF that apps paste), so script.js and this check
        // agree on every number. PHP's own \s would leave those in.
        $clean = preg_replace('/[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', '', $order);
        return strtoupper(ltrim($clean === null ? $order : $clean, '#'));
    }

    /**
     * Why an order number cannot be this marketplace's order ID: null when it is
     * fine (or the marketplace has no rule), 'tracking' for a courier tracking
     * number, 'format' for anything else. $now is for tests only.
     */
    public static function order_number_problem($slug, $order, $now = null) {
        $rule = self::order_rules()[$slug] ?? null;
        if (!$rule) {
            return null;
        }
        $order = self::normalise_order_number($slug, $order);
        if ($rule['tracking_prefix'] !== '' && strpos($order, $rule['tracking_prefix']) === 0) {
            return 'tracking';
        }
        if (!preg_match('/' . $rule['pattern'] . '/', $order)) {
            return 'format';
        }
        if (!empty($rule['dated'])) {
            $y = 2000 + (int) substr($order, 0, 2);
            $m = (int) substr($order, 2, 2);
            $d = (int) substr($order, 4, 2);
            if (!checkdate($m, $d, $y) || sprintf('%04d-%02d-%02d', $y, $m, $d) > self::order_max_date($now)) {
                return 'format';
            }
        }
        return null;
    }

    /** The customer-facing message for a problem from order_number_problem(). */
    public static function order_number_message($slug, $problem) {
        return self::order_rules()[$slug]['messages'][$problem] ?? 'Please check your order number.';
    }

    /**
     * The latest order date a dated order ID may carry: tomorrow in Kuala Lumpur
     * (one day of slack for the timezone edge), as Y-m-d.
     */
    public static function order_max_date($now = null) {
        $kl  = new DateTimeZone('Asia/Kuala_Lumpur');
        $now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable('now');
        return $now->setTimezone($kl)->modify('+1 day')->format('Y-m-d');
    }

    public static function label($slug) {
        // 'website' is not a registerable marketplace (not in all()), but
        // WooCommerce-captured rows use it — label it nicely everywhere.
        if ($slug === 'website') {
            return 'Website Order';
        }
        $all = self::all();
        return $all[$slug] ?? ucfirst((string) $slug);
    }

    public static function is_valid($slug) {
        return array_key_exists($slug, self::all());
    }
}
