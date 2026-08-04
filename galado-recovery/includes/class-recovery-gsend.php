<?php
/**
 * GALADO Send transport (channel spec v2, 2026-08-04).
 *
 * When send_channel = galado_send, the queued push POSTs the capture to the
 * G-Send events endpoint instead of Klaviyo, signed with HMAC-SHA256 over the
 * exact raw body. G-Send owns delivery, suppression, voucher minting (at send
 * time, via galado-coupons/v1/mint) and the flow engine; this plugin stays the
 * source of identity, cart snapshots and recovery tokens.
 *
 * Events on this channel:
 *   cart_abandoned   the capture push. Same row checks, throttles and token
 *                    minting as the Klaviyo path; only the last hop differs.
 *   cart_recovered   fired when a recovery token is used, so G-Send cancels
 *                    any still-pending recovery email for that address.
 *
 * order_completed is deliberately NOT sent from here: a G-Send-side order hook
 * produces it (channel spec v2, section 3). No coupon is minted on this
 * channel either; G-Send mints its own at send time.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_GSend {

    const ENDPOINT = 'https://club.galado.com.my/send/events';

    public static function init() {
        add_action('galado_recovery_gsend_recovered', [__CLASS__, 'push_recovered'], 10, 1);
    }

    /** Secret shared with G-Send; lives in wp_options, never in code or logs. */
    private static function secret() {
        return (string) get_option('galado_gsend_events_secret', '');
    }

    /**
     * POST one event, signed. The signature is computed over the exact string
     * sent: encode once, sign that variable, post that variable, so body and
     * signature input are byte-identical (channel spec v2).
     *
     * Returns true on 2xx (duplicate replays included), the status int for a
     * response that will not heal on retry (400 malformed, 401 bad signature
     * or missing secret), or false for network errors and retryable statuses.
     */
    public static function post_event(array $event) {
        $secret = self::secret();
        if ('' === $secret) return 401; // config missing: retrying cannot help

        $body = wp_json_encode($event);

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 8,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-gsend-signature' => hash_hmac('sha256', $body, $secret),
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) return false;
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) return true;
        if (400 === $status || 401 === $status) return $status;
        return false; // 429 / 5xx / anything else: retry with backoff
    }

    /**
     * cart_abandoned payload (channel spec v2). event_id arrives from the
     * capture (<cart_hash>_<unix_ts>, minted once, reused across retries) and
     * ts is the capture time, so replays are byte-stable where it matters and
     * the endpoint's dedupe does the rest.
     */
    public static function abandoned_event($row, $event_id, $token) {
        $snapshot = json_decode((string) $row->cart_contents, true);
        if (!is_array($snapshot) || empty($snapshot['items'])) return null;

        $items = [];
        foreach ($snapshot['items'] as $it) {
            $items[] = [
                'name'  => (string) ($it['name'] ?? ''),
                'qty'   => max(1, (int) ($it['quantity'] ?? 1)),
                'price' => number_format((float) ($it['price'] ?? 0), 2, '.', ''),
                'image' => (string) ($it['image'] ?? ''),
                'url'   => (string) ($it['url'] ?? ''),
            ];
        }

        $captured = strtotime((string) $row->created_at . ' UTC');

        return [
            'event_id' => (string) $event_id,
            'type'     => 'cart_abandoned',
            'email'    => (string) $row->email,
            'ts'       => gmdate('c', $captured ?: time()),
            'meta'     => [
                'consent'      => (bool) $row->consent,
                'first_name'   => self::first_name($row->email),
                'checkout_url' => home_url('/?galado_recover=' . $token),
                'items'        => $items,
            ],
        ];
    }

    /**
     * Best-effort first name for the greeting: registered account first, then
     * the latest order under this address. Empty string when unknown; the
     * email templates drop the greeting name cleanly.
     */
    private static function first_name($email) {
        $user = get_user_by('email', $email);
        if ($user) {
            $name = trim((string) get_user_meta($user->ID, 'first_name', true));
            if ('' !== $name) return $name;
        }
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'billing_email' => $email,
                'limit'         => 1,
                'orderby'       => 'date',
                'order'         => 'DESC',
                'return'        => 'objects',
            ]);
            if ($orders) {
                $name = trim((string) $orders[0]->get_billing_first_name());
                if ('' !== $name) return $name;
            }
        }
        return '';
    }

    /** Queue cart_recovered without blocking the restore redirect. */
    public static function queue_recovered($row_id) {
        $args = [(int) $row_id];
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('galado_recovery_gsend_recovered', $args, 'galado-recovery');
            return;
        }
        wp_schedule_single_event(time() + 5, 'galado_recovery_gsend_recovered', $args);
    }

    /**
     * The queued cart_recovered send. Single attempt by design: this event
     * exists to CANCEL an email, so losing one costs at most a redundant
     * reminder to someone who already came back, never a wrong send. The
     * deterministic event_id (<cart_hash>_recovered) keeps replays harmless.
     */
    public static function push_recovered($row_id) {
        if ('galado_send' !== galado_recovery_send_channel()) return;

        $row = GALADO_Recovery_DB::get((int) $row_id);
        if (!$row) return;

        self::post_event([
            'event_id' => (string) $row->cart_hash . '_recovered',
            'type'     => 'cart_recovered',
            'email'    => (string) $row->email,
            'ts'       => gmdate('c'),
            'meta'     => (object) [],
        ]);
    }
}
