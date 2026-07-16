<?php
/**
 * HMAC token helpers, byte-compatible with studio-api's lib/hmac.ts:
 * base64url(json_payload) . base64url(hmac_sha256(json_payload, secret)),
 * optional unix "exp" honoured, constant-time comparison.
 */

if (!defined('ABSPATH')) exit;

class GSTUDIO_Token {

    private static function b64url($bin) {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64url_decode($str) {
        $pad = strlen($str) % 4;
        if ($pad) {
            $str .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($str, '-_', '+/'), true);
    }

    /**
     * Sign a payload array. json_encode flags mirror JSON.stringify closely
     * enough because we only ever sign our own freshly-encoded payload; the
     * verify side never re-encodes.
     */
    public static function sign(array $payload, $secret) {
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sig  = hash_hmac('sha256', $body, $secret, true);
        return self::b64url($body) . '.' . self::b64url($sig);
    }

    /**
     * Verify a token; returns the payload array or null. Empty secret never
     * verifies (unarmed gate, same rule as studio-api).
     */
    public static function verify($token, $secret) {
        if (!is_string($token) || $secret === '' || $secret === null) {
            return null;
        }
        $dot = strpos($token, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }
        $body = self::b64url_decode(substr($token, 0, $dot));
        if ($body === false) {
            return null;
        }
        $expected = self::b64url(hash_hmac('sha256', $body, $secret, true));
        $given    = substr($token, $dot + 1);
        if (!hash_equals($expected, $given)) {
            return null;
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }
}
