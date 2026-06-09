<?php
/**
 * Minimal Klaviyo API client for warranty events.
 *
 * Three things fire on approval:
 *   1. Upsert the customer's profile (with consent state)
 *   2. Subscribe them to the Marketplace Buyers list (only if consent given)
 *   3. Send a "Warranty Approved" event with marketplace + product context
 *
 * Failures are logged and swallowed — they never block the user-facing flow.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Klaviyo {

    const API_BASE    = 'https://a.klaviyo.com/api';
    const API_REVISION = '2024-10-15';

    /** Fire profile + list + event for an approved registration. Best-effort. */
    public static function on_approval($row) {
        $settings = get_option('gwarr_settings', []);
        $api_key  = $settings['klaviyo_api_key'] ?? '';

        if ($api_key === '') {
            self::log('skipped: Klaviyo API key not configured');
            return;
        }

        $user = get_userdata((int) $row->user_id);
        if (!$user || !$user->user_email) {
            self::log('skipped: customer has no email');
            return;
        }

        $profile_id = self::upsert_profile($api_key, $user, $row);
        if ($profile_id === null) {
            self::log('aborted after profile upsert failed');
            return;
        }

        // Marketing list — only with explicit consent.
        $list_id = $settings['klaviyo_list_id'] ?? '';
        if ($list_id !== '' && !empty($row->marketing_consent)) {
            self::subscribe_to_list($api_key, $list_id, $user->user_email);
        }

        $event_name = $settings['klaviyo_event_name'] ?: 'Warranty Approved';
        self::send_event($api_key, $event_name, $user, $row);
    }

    /**
     * Create or update the Klaviyo profile. Returns the profile ID or null.
     */
    private static function upsert_profile($api_key, $user, $row) {
        $name_parts = self::split_name($user->display_name ?: $user->user_login);

        $body = [
            'data' => [
                'type' => 'profile',
                'attributes' => [
                    'email'      => $user->user_email,
                    'first_name' => $name_parts[0],
                    'last_name'  => $name_parts[1],
                    'properties' => [
                        'Last Warranty Marketplace'  => GWARR_Marketplaces::label($row->marketplace),
                        'Last Warranty Order Number' => $row->order_number,
                        'Last Warranty Product'      => $row->product_text,
                        'Last Warranty Approved At'  => current_time('mysql'),
                    ],
                ],
            ],
        ];

        $response = self::request($api_key, 'POST', '/profiles/', $body);

        // 409 = profile exists — extract the existing ID and update via PATCH.
        if (isset($response['status']) && (int) $response['status'] === 409) {
            $existing_id = self::extract_conflict_id($response);
            if ($existing_id) {
                $body['data']['id'] = $existing_id;
                self::request($api_key, 'PATCH', '/profiles/' . $existing_id . '/', $body);
                return $existing_id;
            }
        }

        if (isset($response['body']['data']['id'])) {
            return $response['body']['data']['id'];
        }

        self::log('profile upsert returned unexpected response: ' . wp_json_encode($response['body'] ?? $response));
        return null;
    }

    /**
     * Subscribe the email to the configured Marketplace Buyers list.
     */
    private static function subscribe_to_list($api_key, $list_id, $email) {
        $body = [
            'data' => [
                'type' => 'profile-subscription-bulk-create-job',
                'attributes' => [
                    'profiles' => [
                        'data' => [[
                            'type' => 'profile',
                            'attributes' => [
                                'email'         => $email,
                                'subscriptions' => [
                                    'email' => ['marketing' => ['consent' => 'SUBSCRIBED']],
                                ],
                            ],
                        ]],
                    ],
                    'custom_source' => 'GALADO Warranty Registration',
                ],
                'relationships' => [
                    'list' => ['data' => ['type' => 'list', 'id' => $list_id]],
                ],
            ],
        ];

        self::request($api_key, 'POST', '/profile-subscription-bulk-create-jobs/', $body);
    }

    /**
     * Fire the "Warranty Approved" event so downstream flows can react.
     */
    private static function send_event($api_key, $event_name, $user, $row) {
        $body = [
            'data' => [
                'type' => 'event',
                'attributes' => [
                    'properties' => [
                        'marketplace'        => GWARR_Marketplaces::label($row->marketplace),
                        'marketplace_slug'   => $row->marketplace,
                        'order_number'       => $row->order_number,
                        'product'            => $row->product_text,
                        'purchase_date'      => $row->purchase_date,
                        'warranty_ends'      => $row->warranty_ends,
                        'coupon_code'        => $row->coupon_code,
                        'registration_id'    => (int) $row->id,
                    ],
                    'metric'  => ['data' => ['type' => 'metric', 'attributes' => ['name' => $event_name]]],
                    'profile' => ['data' => ['type' => 'profile', 'attributes' => ['email' => $user->user_email]]],
                ],
            ],
        ];

        self::request($api_key, 'POST', '/events/', $body);
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    private static function request($api_key, $method, $path, $body) {
        $args = [
            'method'  => $method,
            'timeout' => 12,
            'headers' => [
                'Authorization' => 'Klaviyo-API-Key ' . $api_key,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'revision'      => self::API_REVISION,
            ],
            'body'    => wp_json_encode($body),
        ];

        $response = wp_remote_request(self::API_BASE . $path, $args);
        if (is_wp_error($response)) {
            self::log($method . ' ' . $path . ' failed: ' . $response->get_error_message());
            return ['status' => 0, 'body' => null];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = wp_remote_retrieve_body($response);
        $body   = $raw !== '' ? json_decode($raw, true) : null;

        if ($status >= 400 && $status !== 409) {
            self::log($method . ' ' . $path . ' status ' . $status . ': ' . $raw);
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * Klaviyo 409 responses include the existing profile's id in errors[].meta.duplicate_profile_id.
     */
    private static function extract_conflict_id($response) {
        $errors = $response['body']['errors'] ?? [];
        foreach ((array) $errors as $err) {
            if (isset($err['meta']['duplicate_profile_id'])) {
                return $err['meta']['duplicate_profile_id'];
            }
        }
        return null;
    }

    private static function split_name($full_name) {
        $full_name = trim((string) $full_name);
        if ($full_name === '') return ['', ''];

        $parts = preg_split('/\s+/', $full_name, 2);
        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
        ];
    }

    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[galado-warranty/klaviyo] ' . $message);
        }
    }
}
