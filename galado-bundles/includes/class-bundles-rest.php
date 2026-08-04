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

        // Ops routes: what the Bundle settings screen can do, minus the one
        // thing it must never do remotely. The storefront master switch (the
        // real cutover: cart fee everywhere + #95 stand-down) is deliberately
        // NOT reachable here; it stays a deliberate wp-admin act.
        register_rest_route('galado-bundles/v1', '/ops/seed', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'ops_seed'],
        ]);
        register_rest_route('galado-bundles/v1', '/ops/publish', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'ops_publish'],
        ]);
        register_rest_route('galado-bundles/v1', '/ops/settings', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'ops_settings'],
        ]);
        register_rest_route('galado-bundles/v1', '/ops/probe', [
            'methods'             => 'GET',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'ops_probe'],
        ]);
    }

    /** Run the launch seeders (combos + accessories shelf); report state. */
    public static function ops_seed() {
        $made  = GALADO_Bundles_Combos::seed_launch_combos();
        $made += GALADO_Bundles_Addons::seed_accessories_group();
        return rest_ensure_response(['created' => $made, 'combos' => self::combo_report()]);
    }

    /** Publish draft combos by slug (default: the four launch slugs). */
    public static function ops_publish(WP_REST_Request $req) {
        $slugs = (array) ($req->get_param('slugs') ?: [
            'combo-protect-complete', 'combo-protect-screen', 'combo-protect-camera', 'combo-protect-screen-lens',
            'addons-accessories',
        ]);
        $done = [];
        foreach ($slugs as $slug) {
            $post = get_page_by_path(sanitize_title($slug), OBJECT, GALADO_BUNDLES_CPT);
            if (!$post) { $done[$slug] = 'not found'; continue; }
            if ('publish' === $post->post_status) { $done[$slug] = 'already published'; continue; }
            wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);
            $done[$slug] = 'published';
        }
        do_action('galado_bundles_changed', []);
        return rest_ensure_response(['result' => $done, 'combos' => self::combo_report()]);
    }

    /**
     * Flip the auxiliary toggles. ONLY combos_enabled and sticky_cart are
     * accepted; any attempt to set the storefront master is rejected loudly
     * rather than ignored, so a miswired call can never soft-launch the cart
     * engine.
     */
    public static function ops_settings(WP_REST_Request $req) {
        foreach (['storefront_enabled', 'galado_bundles_storefront_enabled', 'storefront'] as $forbidden) {
            if (null !== $req->get_param($forbidden)) {
                return new WP_Error('storefront_locked',
                    'The storefront master switch cannot be set over REST. Use wp-admin: GALADO > Bundle settings.',
                    ['status' => 403]);
            }
        }
        $out = [];
        foreach (['combos_enabled' => 'galado_bundles_combos_enabled', 'addons_enabled' => 'galado_bundles_addons_enabled', 'sticky_cart' => 'galado_bundles_sticky_cart'] as $key => $option) {
            $val = $req->get_param($key);
            if (null !== $val) update_option($option, '1' === (string) $val || 1 === $val || true === $val ? '1' : '0');
            $out[$key] = get_option($option, '0');
        }
        $out['storefront_enabled'] = galado_bundles_storefront_enabled() ? '1' : '0'; // read-only echo
        return rest_ensure_response($out);
    }

    /** The combo module AND the add-on shelves exactly as they would render on
     * one product, visibility toggles bypassed, for dark verification. */
    public static function ops_probe(WP_REST_Request $req) {
        $pid = (int) $req->get_param('product_id');
        $product = $pid ? wc_get_product($pid) : null;
        if (!$product) return new WP_Error('bad_product', 'Unknown product.', ['status' => 404]);
        $out = GALADO_Bundles_Combos::probe($product);
        $out['addons'] = GALADO_Bundles_Addons::page_groups($product);
        return rest_ensure_response($out);
    }

    private static function combo_report() {
        $out = [];
        foreach (get_posts([
            'post_type' => GALADO_BUNDLES_CPT, 'post_status' => ['publish', 'draft'],
            'posts_per_page' => 20, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => GALADO_BUNDLES_META . 'combo', 'value' => '1']],
        ]) as $id) {
            $b = GALADO_Bundles_Data::get($id);
            if (!$b) continue;
            $h = GALADO_Bundles_Data::health($id);
            $out[] = [
                'slug' => $b['slug'], 'title' => $b['title'], 'status' => $b['status'],
                'combo_price' => $b['combo_price'], 'sum' => $b['sum'], 'save' => $b['save'],
                'fallback' => $b['combo_fallback'], 'buyable' => $b['buyable'], 'health' => $h,
            ];
        }
        return $out;
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
