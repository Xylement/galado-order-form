<?php
/**
 * [galado_gift_card_check]: "check your gift card".
 *
 * - A POST form with a nonce: the code never appears in a URL (GA4, Clarity and Cloudflare all log
 *   page addresses), and the answer never repeats the code (Clarity records page text).
 * - It answers only: valid (value and expiry), already used, expired, or not found. A disabled
 *   (draft) card, and any coupon that is not a gift card, reads as not found.
 * - Rate limit: 10 checks per IP per clock hour (a Cloudflare rule on the page is the outer guard).
 * - The page is sent uncacheable, so a cached copy never serves a stale nonce. If one still does,
 *   the POST answer carries a fresh form and asks for the code again.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Checker {

    const SHORTCODE = 'galado_gift_card_check';
    const NONCE = 'galado_gc_check';
    const LIMIT_PER_HOUR = 10;

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
        add_action('template_redirect', [__CLASS__, 'no_cache']);
    }

    public static function no_cache() {
        $post = get_queried_object();
        if (!headers_sent() && $post instanceof WP_Post && has_shortcode((string) $post->post_content, self::SHORTCODE)) {
            nocache_headers();
            header('cf-edge-cache: no-cache'); // Cloudflare APO: do not cache this page at the edge
        }
    }

    public static function shortcode() {
        $result = null;
        // phpcs:ignore WordPress.Security.NonceVerification -- verified just below
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['galado_gc_check_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['galado_gc_check_nonce']));
            if (!wp_verify_nonce($nonce, self::NONCE)) {
                $result = ['status' => 'retry'];
            } elseif (!self::allow(self::client_ip())) {
                $result = ['status' => 'limited'];
            } else {
                $code = Galado_GC_Codes::normalize(isset($_POST['galado_gc_code']) ? wp_unslash((string) $_POST['galado_gc_code']) : '');
                $result = self::lookup($code);
            }
        }
        ob_start();
        ?>
        <form method="post" class="galado-gc-check" autocomplete="off">
            <?php wp_nonce_field(self::NONCE, 'galado_gc_check_nonce'); ?>
            <p class="form-row">
                <label for="galado_gc_code"><?php esc_html_e('Gift card code', 'galado-gift-cards'); ?></label>
                <input type="text" id="galado_gc_code" name="galado_gc_code" required maxlength="32" data-clarity-mask="True"
                       placeholder="GIFT-XXXX-XXXX-XXXX" autocapitalize="characters" spellcheck="false">
            </p>
            <p><button type="submit" class="button"><?php esc_html_e('Check', 'galado-gift-cards'); ?></button></p>
        </form>
        <?php if ($result) : ?>
            <div class="galado-gc-check-result galado-gc-check-<?php echo esc_attr($result['status']); ?>" role="status">
                <p><?php echo esc_html(self::message($result)); ?></p>
            </div>
        <?php endif;
        return ob_get_clean();
    }

    /** @return array [status, value?, expires?] for a canonical code ('' = not a code) */
    public static function lookup($code) {
        if ('' === $code) {
            return ['status' => 'not_found'];
        }
        $id = (int) wc_get_coupon_id_by_code(strtolower($code)); // published coupons only
        if (!$id) {
            return ['status' => 'not_found'];
        }
        $coupon = new WC_Coupon($id);
        $expires = $coupon->get_date_expires();
        return self::status_for([
            'is_gift'     => Galado_GC_Codes::is_gift_coupon($coupon),
            'status'      => $coupon->get_status(),
            'usage_count' => (int) $coupon->get_usage_count(),
            'usage_limit' => (int) $coupon->get_usage_limit(),
            'expires'     => $expires ? $expires->getTimestamp() : 0,
            'value'       => (float) $coupon->get_amount('edit'),
        ]);
    }

    /** Pure: the one answer for a coupon's facts, in the order not found, used, expired, valid. */
    public static function status_for(array $c, $now = null) {
        $now = $now === null ? time() : (int) $now;
        if (empty($c['is_gift']) || 'publish' !== ($c['status'] ?? '')) {
            return ['status' => 'not_found'];
        }
        if ((int) $c['usage_limit'] > 0 && (int) $c['usage_count'] >= (int) $c['usage_limit']) {
            return ['status' => 'used'];
        }
        if ((int) $c['expires'] > 0 && $now > (int) $c['expires']) {
            return ['status' => 'expired', 'expires' => (int) $c['expires']];
        }
        return ['status' => 'valid', 'value' => (float) $c['value'], 'expires' => (int) $c['expires']];
    }

    public static function message(array $r) {
        switch ($r['status']) {
            case 'valid':
                $until = $r['expires'] ? Galado_GC_Time::human_date($r['expires']) : '';
                return sprintf(
                    /* translators: 1: value, 2: date */
                    __('This gift card is worth %1$s and can be used until %2$s. Single use: spend it in one order. Any unused value is lost.', 'galado-gift-cards'),
                    Galado_GC_Cart::rm($r['value']), $until
                );
            case 'used':
                return __('This gift card has already been used.', 'galado-gift-cards');
            case 'expired':
                /* translators: %s: date */
                return sprintf(__('This gift card expired on %s.', 'galado-gift-cards'), Galado_GC_Time::human_date($r['expires']));
            case 'limited':
                return __('You have checked a lot of codes. Please try again in an hour.', 'galado-gift-cards');
            case 'retry':
                return __('Please enter the code again.', 'galado-gift-cards');
            default:
                return __('We couldn’t find that gift card. Check the code and try again.', 'galado-gift-cards');
        }
    }

    /** Counts this check; false once the IP has used its checks for this clock hour. */
    public static function allow($ip, $now = null) {
        $now = $now === null ? time() : (int) $now;
        $key = 'galado_gc_chk_' . md5($ip . '|' . gmdate('YmdH', $now));
        $count = (int) get_transient($key);
        if ($count >= self::LIMIT_PER_HOUR) {
            return false;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }

    /** Cloudflare's visitor address when present and well formed, else the connection's. */
    public static function client_ip() {
        $cf = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']) : '';
        if ('' !== $cf && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}
