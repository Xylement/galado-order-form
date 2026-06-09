<?php
/**
 * Minimal Google Sheets API client.
 *
 * Why hand-rolled instead of google/apiclient-services via Composer?
 *   - Two endpoints used (list tabs, read range)
 *   - Read-only scope, no SDK dependency surface to maintain on a WP site
 *   - Composer install on a WP site is awkward; one file is portable
 *
 * The flow: build a JWT signed with the service account's private key,
 * POST it to the Google token endpoint in exchange for an access token,
 * then call the Sheets API with that bearer token.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Sheet_API {

    const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    const SHEETS_URL = 'https://sheets.googleapis.com/v4/spreadsheets';
    const SCOPE      = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    /** @var array */
    private $sa;

    /**
     * @param string|array $service_account Either the JSON string from
     *                                       Google Cloud Console or the already-decoded array.
     */
    public function __construct($service_account) {
        if (is_string($service_account)) {
            $decoded = json_decode($service_account, true);
            $service_account = is_array($decoded) ? $decoded : [];
        }
        $this->sa = is_array($service_account) ? $service_account : [];
    }

    /**
     * Cheap shape check so callers can bail before any network calls.
     */
    public function is_configured() {
        return !empty($this->sa['client_email']) && !empty($this->sa['private_key']);
    }

    /**
     * Exchange the signed JWT for a short-lived OAuth access token.
     * Token is cached in a 50-minute transient to avoid re-signing on every call.
     */
    public function get_access_token() {
        if (!$this->is_configured()) {
            return new WP_Error('gwarr_sa_missing', 'Service account credentials are not configured.');
        }

        $cache_key = 'gwarr_gsheets_token_' . md5($this->sa['client_email']);
        $cached    = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $this->sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signing_input = $this->b64url_encode(wp_json_encode($header))
            . '.' . $this->b64url_encode(wp_json_encode($claims));

        $signature = '';
        if (!openssl_sign($signing_input, $signature, $this->sa['private_key'], 'SHA256')) {
            return new WP_Error('gwarr_jwt_sign_failed', 'Could not sign JWT — check the private_key format.');
        }

        $jwt = $signing_input . '.' . $this->b64url_encode($signature);

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            $err = $body['error_description'] ?? ($body['error'] ?? 'unknown');
            return new WP_Error('gwarr_token_failed', 'Token exchange failed: ' . $err);
        }

        // Refresh a little before expiry to absorb clock skew.
        $expires = isset($body['expires_in']) ? max(60, (int) $body['expires_in'] - 60) : 3000;
        set_transient($cache_key, $body['access_token'], $expires);

        return $body['access_token'];
    }

    /**
     * Return the list of tab titles in the given spreadsheet.
     */
    public function list_tabs($sheet_id) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $url = self::SHEETS_URL . '/' . rawurlencode($sheet_id) . '?fields=sheets.properties.title';
        $resp = $this->get($url, $token);
        if (is_wp_error($resp)) return $resp;

        $titles = [];
        foreach (($resp['sheets'] ?? []) as $s) {
            if (!empty($s['properties']['title'])) {
                $titles[] = $s['properties']['title'];
            }
        }
        return $titles;
    }

    /**
     * Read a range from the spreadsheet. Returns a 2D array (rows × cells).
     * Range example: "May 2026!A:J"
     */
    public function read_range($sheet_id, $range) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $url = self::SHEETS_URL . '/' . rawurlencode($sheet_id) . '/values/' . rawurlencode($range)
            . '?valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING';

        $resp = $this->get($url, $token);
        if (is_wp_error($resp)) return $resp;

        return $resp['values'] ?? [];
    }

    /**
     * GET helper with bearer auth + JSON decoding.
     */
    private function get($url, $token) {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) return $response;

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code >= 400) {
            $decoded = json_decode($body, true);
            $msg     = $decoded['error']['message'] ?? $body;
            return new WP_Error('gwarr_sheets_http_' . $code, 'Sheets API error: ' . $msg);
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * URL-safe base64 (no padding) — required for JWT.
     */
    private function b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
