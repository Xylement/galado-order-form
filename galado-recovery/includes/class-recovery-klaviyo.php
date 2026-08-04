<?php
/**
 * Server-side Klaviyo push (spec FR-8).
 *
 * Sends "Started Checkout" (metric UHiTBn) via the Events API so flow W2GqDu
 * triggers, with the recovery link as CheckoutURL. Klaviyo onsite JS is
 * suppressed on cart/checkout (snippet #20), so server-side is the only path.
 *
 * Guarantees, in order:
 *   1. Never send for a row that is no longer open (purchase closes first).
 *   2. Never send to a suppressed profile; a failed suppression check retries,
 *      it does not send blind.
 *   3. At most one event per (email, cart_hash) per 4 hours.
 *   4. Deterministic event id per capture plus Klaviyo's own unique_id, so
 *      retries can never double-fire the flow.
 *   5. Failures retry with backoff (Action Scheduler when present, WP-Cron
 *      otherwise), they are not dropped.
 *
 * The raw recovery token exists only in transit: it is minted at send time,
 * hashed into the row, embedded in the payload, and never stored or queued raw.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_Klaviyo {

    const API_BASE     = 'https://a.klaviyo.com/api';
    const API_REVISION = '2024-10-15';
    const METRIC_NAME  = 'Started Checkout';
    const MAX_ATTEMPTS = 4;
    /** Hard ceiling per recipient per rolling 24h, on top of the 4h dedupe. */
    const MAX_PER_EMAIL_PER_DAY = 3;

    /** Backoff schedule in seconds per retry attempt. */
    private static $delays = [60, 300, 1800, 7200];

    public static function init() {
        add_action('galado_recovery_push', [__CLASS__, 'push'], 10, 3);
    }

    /**
     * Per-RECIPIENT throttle, deliberately stricter than spec FR-8.
     *
     * FR-8 dedupes on (email, cart_hash), but cart_hash is attacker-controlled:
     * anyone can mutate their own cart between capture calls to mint a fresh
     * hash and post the same victim address again, turning the endpoint into an
     * email bomb. Keying the dedupe on the email alone removes that lever
     * entirely: one send per address per 4 hours, whatever the cart, plus a
     * hard daily ceiling. Genuine repeat captures still update the stored cart,
     * they just do not re-trigger the flow.
     */
    public static function recently_pushed($email) {
        if (get_transient(self::dedupe_key($email))) return true;
        return self::daily_count($email) >= self::MAX_PER_EMAIL_PER_DAY;
    }

    private static function daily_count($email) {
        return galado_recovery_peek(self::daily_key($email));
    }

    /**
     * Counted through galado_recovery_bump(), which is a real atomic increment
     * where a persistent object cache exists. A plain get-then-set pair let two
     * pushes for the same address, picked up by Action Scheduler at nearly the
     * same instant, both read the pre-increment count and both send.
     */
    private static function record_push($email) {
        set_transient(self::dedupe_key($email), 1, GALADO_RECOVERY_DEDUPE_TTL);
        galado_recovery_bump(self::daily_key($email), DAY_IN_SECONDS);
    }

    /** Queue the initial push, async so capture never waits on Klaviyo. */
    public static function queue($row_id, $event_id, $attempt) {
        $args = [(int) $row_id, (string) $event_id, (int) $attempt];
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('galado_recovery_push', $args, 'galado-recovery');
            return;
        }
        wp_schedule_single_event(time() + 5, 'galado_recovery_push', $args);
    }

    private static function schedule_retry($row_id, $event_id, $attempt) {
        $delay = self::$delays[min($attempt, count(self::$delays) - 1)];
        $args  = [(int) $row_id, (string) $event_id, (int) $attempt];
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, 'galado_recovery_push', $args, 'galado-recovery');
            return;
        }
        wp_schedule_single_event(time() + $delay, 'galado_recovery_push', $args);
    }

    /**
     * The queued job. Channel-agnostic up to the final hand-off: row validity,
     * the never-email-a-buyer check, the per-recipient throttle and token
     * minting are the same whoever sends. Only the last step differs, so
     * swapping Klaviyo for GALADO Send changes one branch, not the pipeline.
     */
    public static function push($row_id, $event_id, $attempt = 0) {
        $channel = galado_recovery_send_channel();
        if ('none' === $channel) {
            return; // dark: rows are captured, nothing is sent
        }

        $row = GALADO_Recovery_DB::get((int) $row_id);
        if (!$row || GALADO_Recovery_DB::STATUS_OPEN !== $row->status) {
            return; // purchased, recovered or purged since capture: never email
        }

        // Send-time throttle: one event per recipient per 4 hours, max 3/day.
        if (self::recently_pushed($row->email)) {
            return;
        }

        if ('galado_send' === $channel) {
            self::send_via_galado_send($row, $event_id, $attempt);
            return;
        }

        self::send_via_klaviyo($row, $event_id, $attempt);
    }

    /**
     * POST the capture to G-Send, signed (channel spec v2). G-Send owns
     * suppression, delivery and send-time voucher minting; this plugin owns
     * identity, the cart snapshot and the token. Replaces the earlier
     * in-process galado_recovery_send filter seam, which never gained a
     * listener and is now retired.
     */
    private static function send_via_galado_send($row, $event_id, $attempt) {
        $token   = self::mint_token($row);
        $payload = GALADO_Recovery_GSend::abandoned_event($row, $event_id, $token);
        if (!$payload) {
            self::note_error('Row ' . $row->id . ' has no usable cart snapshot; push skipped.');
            return;
        }

        $result = GALADO_Recovery_GSend::post_event($payload);

        if (true === $result) {
            self::record_push($row->email);
            update_option('galado_recovery_last_push', gmdate('Y-m-d H:i:s') . ' (galado_send)', false);
            return;
        }
        if (401 === $result) {
            self::note_error('G-Send rejected row ' . $row->id . ': bad signature or missing galado_gsend_events_secret.');
            return; // configuration problem: retrying cannot help
        }
        if (400 === $result) {
            self::note_error('G-Send called row ' . $row->id . ' malformed (HTTP 400); fix the payload, do not retry.');
            return;
        }
        self::retry_or_give_up($row->id, $event_id, $attempt, 'G-Send unreachable or 5xx');
    }

    /** Mint a recovery token: raw goes out, only the SHA-256 hash is stored. */
    private static function mint_token($row) {
        $token = bin2hex(random_bytes(32));
        GALADO_Recovery_DB::set_token(
            $row->id,
            hash('sha256', $token),
            gmdate('Y-m-d H:i:s', time() + GALADO_RECOVERY_TOKEN_TTL)
        );
        return $token;
    }

    private static function send_via_klaviyo($row, $event_id, $attempt) {
        $row_id = $row->id;

        $api_key = galado_recovery_klaviyo_key();
        if ('' === $api_key) {
            self::note_error('Klaviyo API key not configured; push skipped.');
            return;
        }

        // Suppression check. On API failure retry later rather than send blind.
        $suppressed = self::is_suppressed($api_key, $row->email);
        if (null === $suppressed) {
            self::retry_or_give_up($row_id, $event_id, $attempt, 'suppression check failed');
            return;
        }
        if ($suppressed) {
            return;
        }

        $token   = self::mint_token($row);
        $payload = self::payload($row, $event_id, $token);
        if (!$payload) {
            self::note_error('Row ' . $row->id . ' has no usable cart snapshot; push skipped.');
            return;
        }

        $response = wp_remote_post(self::API_BASE . '/events/', [
            'timeout' => 12,
            'headers' => [
                'Authorization' => 'Klaviyo-API-Key ' . $api_key,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'revision'      => self::API_REVISION,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            self::retry_or_give_up($row_id, $event_id, $attempt, $response->get_error_message());
            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            self::record_push($row->email);
            update_option('galado_recovery_last_push', gmdate('Y-m-d H:i:s') . ' (klaviyo)', false);
            return;
        }

        if (429 === $status || $status >= 500) {
            self::retry_or_give_up($row_id, $event_id, $attempt, 'HTTP ' . $status);
            return;
        }

        // 4xx other than 429 will not heal on retry: log the body, stop.
        self::note_error('Events API rejected row ' . $row->id . ' with HTTP ' . $status . ': '
            . substr((string) wp_remote_retrieve_body($response), 0, 300));
    }

    /**
     * Payload mirroring the LIVE WooCommerce-integration event shape (verified
     * against a real UHiTBn event on 2026-08-03), not the simplified shape in
     * the spec. Two things depend on this fidelity:
     *   - "$service": "woocommerce" attributes the event to the WooCommerce
     *     integration metric (UHiTBn). Without it the event lands on a separate
     *     API metric and flow W2GqDu never triggers.
     *   - The flow's email templates were built against this property shape
     *     (Items[].Name/URL/Images, $extra.GrandTotal, CartRebuildKey), so our
     *     events must carry the same keys to render correctly.
     * CheckoutURL (the tokenised recovery link, spec FR-7/FR-8) rides on top
     * so templates can upgrade to it later.
     */
    private static function payload($row, $event_id, $token) {
        $snapshot = json_decode((string) $row->cart_contents, true);
        if (!is_array($snapshot) || empty($snapshot['items'])) return null;

        $names = [];
        $items = [];
        $categories = [];
        $rebuild = ['composite' => [], 'normal_products' => []];
        $subtotal = 0.0;

        foreach ($snapshot['items'] as $it) {
            $product_id   = (int) ($it['product_id'] ?? 0);
            $variation_id = (int) ($it['variation_id'] ?? 0);
            $quantity     = (int) ($it['quantity'] ?? 1);
            $variation    = is_array($it['variation'] ?? null) ? $it['variation'] : [];
            $line_total   = (float) ($it['price'] ?? 0) * max(1, $quantity);
            $item_cats    = is_array($it['categories'] ?? null) ? $it['categories'] : [];
            $subtotal    += $line_total;

            $names[] = (string) ($it['name'] ?? '');
            $categories = array_merge($categories, $item_cats);

            $items[] = [
                'VariantID'  => $variation_id ?: $product_id,
                'ProductID'  => $product_id,
                'Name'       => (string) ($it['name'] ?? ''),
                'SKU'        => (string) ($it['sku'] ?? ''),
                'Quantity'   => $quantity,
                'ItemPrice'  => (float) ($it['price'] ?? 0),
                'LineTotal'  => $line_total,
                'SubTotal'   => $line_total,
                'URL'        => (string) ($it['url'] ?? ''),
                'Images'     => [['URL' => (string) ($it['image'] ?? '')]],
                'Variation'  => $variation ?: [],
                'Categories' => array_values($item_cats),
            ];

            // Same structure the Klaviyo WooCommerce plugin encodes, so any
            // template link built on CartRebuildKey keeps working for our events.
            $rebuild['normal_products'][md5($product_id . '_' . $variation_id)] = [
                'product_id'   => $product_id,
                'quantity'     => $quantity,
                'variation_id' => $variation_id,
                'variation'    => $variation ?: [],
            ];
        }

        $currency = (string) ($row->currency ?: 'MYR');
        $symbol   = (string) ($snapshot['symbol'] ?? $currency);
        $total    = (float) $row->cart_total;

        return [
            'data' => [
                'type'       => 'event',
                'attributes' => [
                    'unique_id'  => $event_id,
                    'properties' => [
                        '$service'       => 'woocommerce',
                        '$event_id'      => $event_id,
                        '$value'         => $total,
                        'ItemNames'      => $names,
                        'Items'          => $items,
                        'Categories'     => array_values(array_unique($categories)),
                        'Currency'       => $currency,
                        'CurrencySymbol' => $symbol,
                        'CheckoutURL'    => home_url('/?galado_recover=' . $token),
                        '$extra'         => [
                            'GrandTotal'     => number_format($total, 2, '.', ''),
                            'SubTotal'       => (float) ($snapshot['subtotal'] ?? $subtotal),
                            'TaxTotal'       => 0,
                            'ShippingTotal'  => '0',
                            'Items'          => $items,
                            'CartRebuildKey' => base64_encode((string) wp_json_encode($rebuild)),
                        ],
                    ],
                    // 'service' => 'woocommerce' on the METRIC attributes is what
                    // routes this onto the WooCommerce-integration metric UHiTBn
                    // (the one flow W2GqDu triggers on). Verified against
                    // Klaviyo's own wck-started-checkout.js, which sets exactly
                    // this. Without it the event creates a separate
                    // "Started Checkout" metric under the API integration that
                    // triggers nothing, which is what happened on 2026-08-03
                    // (stray metric XN5DLS). A '$service' property is NOT enough.
                    'metric'  => ['data' => ['type' => 'metric', 'attributes' => [
                        'name'    => self::METRIC_NAME,
                        'service' => 'woocommerce',
                    ]]],
                    'profile' => ['data' => ['type' => 'profile', 'attributes' => ['email' => $row->email]]],
                ],
            ],
        ];
    }

    /**
     * True/false when Klaviyo answered, null when the check itself failed.
     * A profile that does not exist yet is simply not suppressed.
     */
    private static function is_suppressed($api_key, $email) {
        $url = self::API_BASE . '/profiles/?' . http_build_query([
            'filter' => 'equals(email,"' . $email . '")',
            'additional-fields[profile]' => 'subscriptions',
        ]);

        $response = wp_remote_get($url, [
            'timeout' => 12,
            'headers' => [
                'Authorization' => 'Klaviyo-API-Key ' . $api_key,
                'Accept'        => 'application/json',
                'revision'      => self::API_REVISION,
            ],
        ]);

        if (is_wp_error($response)) return null;
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) return null;

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['data'])) return null;
        if (empty($body['data'])) return false; // no profile yet

        $marketing = $body['data'][0]['attributes']['subscriptions']['email']['marketing'] ?? [];
        if (!empty($marketing['suppression']) && is_array($marketing['suppression'])) {
            return true;
        }
        if (isset($marketing['consent']) && 'UNSUBSCRIBED' === $marketing['consent']) {
            return true;
        }
        return false;
    }

    private static function retry_or_give_up($row_id, $event_id, $attempt, $reason) {
        if ($attempt + 1 >= self::MAX_ATTEMPTS) {
            self::note_error('Giving up on row ' . (int) $row_id . ' after ' . self::MAX_ATTEMPTS . ' attempts: ' . $reason);
            return;
        }
        self::schedule_retry($row_id, $event_id, $attempt + 1);
    }

    private static function dedupe_key($email) {
        return 'grv_pushed_' . md5(strtolower(trim((string) $email)));
    }

    private static function daily_key($email) {
        return 'grv_day_' . md5(strtolower(trim((string) $email)));
    }

    /** Last error surfaces on the settings screen; details go to the log. */
    private static function note_error($message) {
        update_option('galado_recovery_last_error', gmdate('Y-m-d H:i:s') . ' ' . $message, false);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[galado-recovery] ' . $message);
        }
    }
}
