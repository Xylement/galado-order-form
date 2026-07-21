<?php
/**
 * Server-side analytics (spec 11.1): stamp bundle identity onto order line items
 * and the order, so "which bundles sold, at what saving" is queryable from
 * orders and survives even after a bundle is trashed. Only loaded when the
 * storefront flag is on. HPOS-safe (CRUD meta only).
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Analytics {

    public static function init() {
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'line_item'], 10, 4);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'order'], 20, 2);
        // Store API checkout (app / blocks) path.
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'order_storeapi'], 20, 1);
    }

    public static function line_item($item, $cart_item_key, $values, $order) {
        if (empty($values['galado_bundle'])) return;
        $slug = (string) $values['galado_bundle'];
        $desc = GALADO_Bundles_Data::get($slug);
        $item->add_meta_data('_galado_bundle', $slug, true);
        $item->add_meta_data('_galado_bundle_name', $desc ? $desc['title'] : $slug, true);
        $item->add_meta_data('_galado_bundle_uid', (string) ($values['galado_bundle_uid'] ?? ''), true);
        $item->add_meta_data('_galado_bundle_slot', (string) ($values['galado_bundle_slot'] ?? ''), true);
    }

    public static function order($order, $data) {
        self::stamp_order($order);
    }

    public static function order_storeapi($order) {
        self::stamp_order($order);
    }

    private static function stamp_order($order) {
        if (!class_exists('GALADO_Bundles_Discount')) return;
        // The actually-applied savings (post-clamp), so the order never over-reports
        // the discount when the never-negative floor scaled a saving down.
        $applied = GALADO_Bundles_Discount::applied();
        if (!$applied) return;
        $total = 0.0; $meta = [];
        foreach ($applied as $slug => $m) {
            $total += $m['saving'];
            $meta[$slug] = ['name' => $m['name'], 'complete_instances' => $m['complete_instances'], 'saving' => $m['saving']];
        }
        $order->update_meta_data('_galado_bundle_saving', round($total, 2));
        $order->update_meta_data('_galado_bundle_meta', $meta);
    }
}
