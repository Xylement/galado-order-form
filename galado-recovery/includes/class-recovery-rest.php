<?php
/**
 * Capture endpoint (spec FR-3, FR-4).
 *
 * POST /wp-json/galado-recovery/v1/capture
 * Nonce-gated, honeypotted, rate-limited. Reads the cart from the visitor's
 * WooCommerce session only; the client payload never supplies cart contents.
 * Responses carry no cart or profile data and are never cacheable (POST plus
 * explicit no-store headers, so Cloudflare APO cannot serve them from edge).
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_REST {

    const RL_PER_MINUTE = 5;
    const RL_PER_HOUR   = 20;

    public static function init() {
        // FR-4 cache bypass. Snippet #21 only covers /cart/, /checkout/ and
        // /my-account/, so this route inherits the site default
        // (cf-edge-cache: cache,platform=wordpress). POSTs are not edge-cached
        // in practice, but rather than widen a live snippet the plugin asserts
        // no-store on EVERY response of its own namespace, errors included.
        add_filter('rest_post_dispatch', [__CLASS__, 'no_store'], 10, 3);

        add_action('rest_api_init', function () {
            register_rest_route('galado-recovery/v1', '/capture', [
                'methods'             => 'POST',
                'permission_callback' => [__CLASS__, 'check_nonce'],
                'callback'            => [__CLASS__, 'capture'],
                'args'                => [
                    'email'   => ['type' => 'string', 'required' => true],
                    'consent' => ['type' => 'boolean', 'default' => false],
                    'source'  => ['type' => 'string', 'default' => 'checkout'],
                    'hp'      => ['type' => 'string', 'default' => ''],
                ],
            ]);
        });
    }

    /** Force no-store on anything served from galado-recovery/v1. */
    public static function no_store($response, $server, $request) {
        if ($request && 0 === strpos(ltrim((string) $request->get_route(), '/'), 'galado-recovery/')) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('CF-Edge-Cache', 'no-cache');
        }
        return $response;
    }

    /**
     * Same-origin proof for guests: the wp_rest nonce printed on the page.
     * Guests have no user context, so this is CSRF protection, not auth.
     */
    public static function check_nonce($request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('rest_forbidden', 'Invalid or missing nonce.', ['status' => 403]);
        }
        return true;
    }

    public static function capture($request) {
        // Honeypot (FR-3): pretend success, write nothing.
        if ('' !== (string) $request->get_param('hp')) {
            return self::ok();
        }

        // Dark or Phase 1 off: accept silently so the client never errors.
        if (!galado_recovery_enabled()) {
            return self::ok();
        }

        // Abuse surface: this accepts arbitrary emails, so throttle per IP.
        $limited = self::rate_limit();
        if (is_wp_error($limited)) {
            return $limited;
        }

        $email = sanitize_email((string) $request->get_param('email'));
        if (!$email || !is_email($email)) {
            return new WP_Error('invalid_email', 'That email address does not look valid.', ['status' => 400]);
        }

        // Optional throwaway blocklist: swallow silently, do not signal.
        if (self::blocklisted($email)) {
            return self::ok();
        }

        // Only client-originated sources are accepted here; "inbound" is
        // resolved server-side by the restore handler (FR-10), never claimed
        // by the client.
        $source = (string) $request->get_param('source');
        if (!in_array($source, ['checkout', 'cart'], true)) {
            $source = 'checkout';
        }

        // Load the visitor's session cart in REST context.
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        $cart = (function_exists('WC') && WC()->cart) ? WC()->cart : null;
        if (!$cart || $cart->is_empty()) {
            return self::ok(); // nothing to persist, nothing to recover
        }

        $snapshot = self::snapshot($cart);
        if (empty($snapshot['items'])) {
            return self::ok();
        }

        $session_key = (WC()->session && method_exists(WC()->session, 'get_customer_id'))
            ? (string) WC()->session->get_customer_id() : '';

        $row_id = GALADO_Recovery_DB::upsert(
            $email,
            $session_key,
            wp_json_encode($snapshot),
            $snapshot['hash'],
            $snapshot['total'],
            $snapshot['currency'],
            (bool) $request->get_param('consent'),
            $source
        );

        // Queue the Klaviyo push (FR-8). Dedupe: at most one event per
        // (email, cart_hash) per 4 hours, enforced again at send time.
        if ($row_id && !GALADO_Recovery_Klaviyo::recently_pushed($email)) {
            $event_id = $snapshot['hash'] . '_' . time();
            GALADO_Recovery_Klaviyo::queue($row_id, $event_id, 0);
        }

        return self::ok();
    }

    /**
     * Cart snapshot from the server session (FR-3/FR-5): restore data plus the
     * display fields the Klaviyo payload needs, captured at current prices.
     */
    private static function snapshot($cart) {
        $items = [];
        foreach ($cart->get_cart() as $item) {
            $product = isset($item['data']) && is_object($item['data']) ? $item['data'] : null;
            if (!$product || !$product->exists()) continue;

            $custom = array_diff_key($item, array_flip([
                'data', 'key', 'product_id', 'variation_id', 'variation', 'quantity',
                'line_total', 'line_tax', 'line_subtotal', 'line_subtotal_tax', 'line_tax_data', 'data_hash',
            ]));

            $categories = wp_get_post_terms((int) $item['product_id'], 'product_cat', ['fields' => 'names']);

            $items[] = [
                'product_id'   => (int) $item['product_id'],
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'quantity'     => (int) $item['quantity'],
                'variation'    => is_array($item['variation'] ?? null) ? $item['variation'] : [],
                'custom'       => self::json_safe($custom),
                'name'         => $product->get_name(),
                'sku'          => (string) $product->get_sku(),
                'price'        => (float) wc_get_price_to_display($product),
                'url'          => (string) get_permalink((int) $item['product_id']),
                'image'        => (string) (wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: ''),
                'categories'   => is_wp_error($categories) ? [] : array_values((array) $categories),
            ];
        }

        return [
            'items'    => $items,
            'hash'     => GALADO_Recovery_DB::cart_hash($cart),
            'total'    => (float) $cart->get_total('edit'),
            'subtotal' => (float) $cart->get_subtotal(),
            'currency' => get_woocommerce_currency(),
            'symbol'   => html_entity_decode((string) get_woocommerce_currency_symbol(), ENT_QUOTES),
        ];
    }

    /** Strip anything that will not survive a JSON round trip (objects, closures). */
    private static function json_safe($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $safe = self::json_safe($v);
                if (null !== $safe || null === $v) $out[$k] = $safe;
            }
            return $out;
        }
        return is_scalar($value) || null === $value ? $value : null;
    }

    /** FR-3: 5/min and 20/hour per IP, 429 beyond. */
    private static function rate_limit() {
        $ip = self::client_ip();
        if ('' === $ip) return true;
        $bucket = md5($ip);

        $minute = galado_recovery_bump('grv_rl_m_' . $bucket, MINUTE_IN_SECONDS);
        $hour   = galado_recovery_bump('grv_rl_h_' . $bucket, HOUR_IN_SECONDS);
        if ($minute > self::RL_PER_MINUTE || $hour > self::RL_PER_HOUR) {
            return new WP_Error('rate_limited', 'Too many requests. Please try again shortly.', ['status' => 429]);
        }
        return true;
    }

    /**
     * The site fronts through Cloudflare, so CF-Connecting-IP carries the real
     * visitor. It is only trusted when the request actually arrived FROM a
     * Cloudflare edge address: the origin IP is known to be reachable directly,
     * and an unvalidated header would let an attacker mint a fresh "IP" per
     * request and walk straight past the rate limiter.
     */
    private static function client_ip() {
        $peer = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && self::is_cloudflare_peer($peer)) {
            $forwarded = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) return $forwarded;
        }
        return $peer;
    }

    /** Cloudflare's published ranges (cloudflare.com/ips), filterable to update. */
    private static function is_cloudflare_peer($ip) {
        if ('' === $ip) return false;
        $ranges = apply_filters('galado_recovery_cloudflare_ranges', [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ]);
        foreach ($ranges as $range) {
            if (self::ip_in_range($ip, $range)) return true;
        }
        return false;
    }

    /** CIDR match for both IPv4 and IPv6, done on packed binary form. */
    private static function ip_in_range($ip, $cidr) {
        if (false === strpos($cidr, '/')) return false;
        list($subnet, $bits) = explode('/', $cidr, 2);

        $ip_bin     = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        $bits       = (int) $bits;
        if (false === $ip_bin || false === $subnet_bin) return false;
        if (strlen($ip_bin) !== strlen($subnet_bin)) return false; // v4 vs v6

        $whole = intdiv($bits, 8);
        $rest  = $bits % 8;
        if ($whole > 0 && strncmp($ip_bin, $subnet_bin, $whole) !== 0) return false;
        if (0 === $rest) return true;

        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return (($ip_bin[$whole] & $mask) === ($subnet_bin[$whole] & $mask));
    }

    private static function blocklisted($email) {
        $settings = galado_recovery_settings();
        $list = array_filter(array_map('trim', explode("\n", strtolower((string) $settings['blocklist']))));
        if (!$list) return false;
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        return in_array($domain, $list, true);
    }

    /** FR-4: uniform success response, never cacheable, no data echoed back. */
    private static function ok() {
        $response = rest_ensure_response(['ok' => true]);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('CF-Edge-Cache', 'no-cache');
        return $response;
    }
}
