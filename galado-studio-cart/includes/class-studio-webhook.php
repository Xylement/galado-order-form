<?php
/**
 * Order webhook: when a Studio order is paid (processing/completed), tell
 * studio-api so the artwork enters the QC queue and gains permanent
 * retention (spec flow: W -> A "order webhook (QC queue + retention hold)").
 * Idempotent per order via a flag meta; studio-api's side is idempotent too
 * (order_id only set where NULL).
 */

if (!defined('ABSPATH')) exit;

class GSTUDIO_Webhook {

    public static function init() {
        add_action('woocommerce_order_status_processing', [__CLASS__, 'notify'], 20);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'notify'], 20);
    }

    public static function notify($order_id) {
        if (!function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_gstudio_hooked')) return;

        $artwork_ids = [];
        foreach ($order->get_items() as $item) {
            $aid = $item->get_meta('_studio_artwork_id');
            if ($aid) $artwork_ids[] = (string) $aid;
        }
        if (!$artwork_ids) return; // not a Studio order

        $secret = gstudio_secret();
        if ('' === $secret) {
            error_log('[galado-studio] webhook skipped: shared secret not configured');
            return;
        }

        $payload = [
            'token'       => GSTUDIO_Token::sign(['t' => 'hook', 'exp' => time() + 300], $secret),
            'order_id'    => (int) $order_id,
            'artwork_ids' => $artwork_ids,
        ];
        $resp = wp_remote_post(gstudio_api_base() . '/v1/hooks/woo-order', [
            'timeout' => 6,
            'headers' => ['content-type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp) || 200 !== wp_remote_retrieve_response_code($resp)) {
            // Leave un-flagged so the next status transition retries; QC can
            // also be backfilled from the admin side if it keeps failing.
            error_log('[galado-studio] webhook for order ' . $order_id . ' failed: '
                . (is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp)));
            return;
        }

        $order->update_meta_data('_gstudio_hooked', '1');
        $order->save();
    }
}
