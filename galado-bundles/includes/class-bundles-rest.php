<?php
/**
 * REST namespace galado-bundles/v1.
 *   GET /bundles?featured=1        public, cache-friendly, no cost/margin fields
 *   GET /product-search?q=...      admin-auth, powers the select2 item picker
 *   GET /variations?product_id=... admin-auth, powers the pinned-variation picker
 * The public route never returns cost/margin fields and never varies by session.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_REST {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route('galado-bundles/v1', '/bundles', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'get_bundles'],
            'args'                => [
                'featured' => ['sanitize_callback' => 'absint'],
                'all'      => ['sanitize_callback' => 'absint'],
            ],
        ]);
        register_rest_route('galado-bundles/v1', '/product-search', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'product_search'],
        ]);
        register_rest_route('galado-bundles/v1', '/variations', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'variations'],
        ]);
    }

    public static function can_manage() {
        return current_user_can('manage_woocommerce');
    }

    /** Public bundle read. Featured-only unless ?all=1 with manage_woocommerce.
     * Strips every cost/margin field; only public-safe display data. */
    public static function get_bundles(WP_REST_Request $req) {
        $all = (int) $req->get_param('all');
        if ($all && current_user_can('manage_woocommerce')) {
            $ids = get_posts([
                'post_type' => GALADO_BUNDLES_CPT, 'post_status' => 'publish',
                'posts_per_page' => 50, 'orderby' => ['menu_order' => 'ASC'], 'fields' => 'ids', 'no_found_rows' => true,
            ]);
            $list = array_filter(array_map([GALADO_Bundles_Data::class, 'get'], $ids));
        } else {
            $list = GALADO_Bundles_Data::get_featured();
        }
        $out = [];
        foreach ($list as $b) {
            $out[] = [
                'slug'    => $b['slug'],
                'title'   => $b['title'],
                'mode'    => $b['mode'],
                'save'    => $b['save'],
                'sum'     => $b['sum'],
                'total'   => $b['total'],
                'cta'     => $b['cta'],
                'image'   => $b['image'],
                'blurb'   => $b['blurb'],
                'buyable' => $b['buyable'],
                'items'   => array_map(function ($it) {
                    return [
                        'slot' => $it['slot'], 'product_id' => $it['product_id'],
                        'line_type' => $it['line_type'], 'qty' => $it['qty'], 'name' => $it['name_cache'],
                    ];
                }, $b['items']),
            ];
        }
        $resp = rest_ensure_response($out);
        $resp->header('Cache-Control', 'public, max-age=300');
        return $resp;
    }

    /** select2 product picker. Returns publish, purchasable products, excluding
     * the ids that must never be bundled (Studio backing product, etc.). */
    public static function product_search(WP_REST_Request $req) {
        $q = sanitize_text_field((string) $req->get_param('q'));
        if (mb_strlen($q) < 2) return rest_ensure_response([]);
        $excluded = galado_bundles_excluded_products();
        $ids = wc_get_products([
            'status' => 'publish', 'limit' => 20, 's' => $q, 'return' => 'ids', 'exclude' => $excluded,
        ]);
        $out = [];
        foreach ($ids as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;
            $type = $p->is_type('variable') ? 'variable' : ($p->is_type('simple') ? 'simple' : $p->get_type());
            if (!in_array($type, ['variable', 'simple'], true)) continue; // v1: simple + variable only
            $out[] = [
                'id'    => $pid,
                'text'  => $p->get_name(),
                'sku'   => $p->get_sku(),
                'type'  => $type,
                'price' => wc_get_price_to_display($p),
                'thumb' => wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                'stock' => $p->is_in_stock() ? 'in' : 'out',
            ];
        }
        return rest_ensure_response($out);
    }

    /** The single variation-read path for the pinned-variation picker. */
    public static function variations(WP_REST_Request $req) {
        $pid = (int) $req->get_param('product_id');
        $parent = $pid ? wc_get_product($pid) : null;
        if (!$parent || !$parent->is_type('variable')) return rest_ensure_response([]);
        $out = [];
        foreach ($parent->get_children() as $cid) {
            $v = wc_get_product($cid);
            if (!$v) continue;
            $attrs = $v->get_variation_attributes();
            $label = $attrs ? implode(' / ', array_map('wc_clean', array_values($attrs))) : ('#' . $cid);
            $out[] = [
                'id'    => $cid,
                'label' => $label,
                'price' => wc_get_price_to_display($v),
                'stock' => $v->is_in_stock() ? 'in' : 'out',
            ];
        }
        return rest_ensure_response($out);
    }
}
