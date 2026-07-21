<?php
/**
 * The galado_bundle custom post type, its list table, the Featured quick-toggle
 * and the Duplicate row action. Status maps onto native post status:
 * publish = active, draft = inactive, trash = retired (reversible).
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_CPT {

    public static function init() {
        add_action('init', [__CLASS__, 'register']);
        add_filter('manage_' . GALADO_BUNDLES_CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . GALADO_BUNDLES_CPT . '_posts_custom_column', [__CLASS__, 'column'], 10, 2);
        add_filter('manage_edit-' . GALADO_BUNDLES_CPT . '_sortable_columns', [__CLASS__, 'sortable']);
        add_action('restrict_manage_posts', [__CLASS__, 'filters']);
        add_action('pre_get_posts', [__CLASS__, 'apply_filters_query']);
        add_filter('post_row_actions', [__CLASS__, 'row_actions'], 10, 2);
        add_action('admin_action_galado_bundle_duplicate', [__CLASS__, 'duplicate']);
        add_action('wp_ajax_galado_bundle_toggle_featured', [__CLASS__, 'ajax_toggle_featured']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'list_assets']);
    }

    public static function register() {
        register_post_type(GALADO_BUNDLES_CPT, [
            'labels' => [
                'name' => 'Bundles', 'singular_name' => 'Bundle',
                'add_new' => 'Add bundle', 'add_new_item' => 'Add bundle',
                'edit_item' => 'Edit bundle', 'new_item' => 'New bundle',
                'view_item' => 'View bundle', 'search_items' => 'Search bundles',
                'not_found' => 'No bundles yet', 'menu_name' => 'Bundles',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 56,
            'menu_icon' => 'dashicons-cart',
            'supports' => ['title', 'page-attributes'],
            'map_meta_cap' => true,
            'capability_type' => 'galado_bundle',
            'hierarchical' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    public static function columns($cols) {
        $out = ['cb' => $cols['cb'], 'title' => 'Name'];
        $out['bundle_items']   = 'Items';
        $out['bundle_saving']  = 'Saving';
        $out['bundle_feat']    = 'Featured';
        $out['bundle_status']  = 'Status';
        $out['menu_order']     = 'Order';
        $out['bundle_health']  = 'Health';
        return $out;
    }

    public static function sortable($cols) {
        $cols['menu_order'] = 'menu_order';
        return $cols;
    }

    public static function column($col, $post_id) {
        switch ($col) {
            case 'bundle_items':
                $b = GALADO_Bundles_Data::get($post_id);
                if (!$b) { echo '&mdash;'; break; }
                $pills = [];
                foreach ($b['items'] as $it) {
                    $name = $it['name_cache'] ?: ('#' . $it['product_id']);
                    $tag = ('variable' === $it['line_type'])
                        ? ('shopper_choice' === $it['variation_mode'] ? ' (pick)' : ' (set)') : '';
                    if (($it['qty'] ?? 1) > 1) $tag .= ' x' . (int) $it['qty'];
                    $pills[] = esc_html($name . $tag);
                }
                echo '<span style="color:#50575e">' . implode(' &middot; ', $pills) . '</span>';
                break;
            case 'bundle_saving':
                $save = (float) get_post_meta($post_id, GALADO_BUNDLES_META . 'save', true);
                echo $save > 0
                    ? 'RM' . rtrim(rtrim(number_format($save, 2, '.', ''), '0'), '.')
                    : '<span style="color:#a7aaad">Link only</span>';
                break;
            case 'bundle_feat':
                $on = '1' === get_post_meta($post_id, GALADO_BUNDLES_META . 'featured', true);
                printf(
                    '<button type="button" class="button-link galado-bundle-featured" data-id="%d" data-on="%d" title="Toggle featured" style="text-decoration:none;font-size:20px;line-height:1">%s</button>',
                    $post_id, $on ? 1 : 0,
                    $on ? '<span class="dashicons dashicons-star-filled" style="color:#e4002b"></span>'
                        : '<span class="dashicons dashicons-star-empty" style="color:#c3c4c7"></span>'
                );
                break;
            case 'bundle_status':
                $st = get_post_status($post_id);
                $map = ['publish' => ['Active', '#1a7f37'], 'draft' => ['Inactive', '#996800'], 'trash' => ['Retired', '#a7aaad']];
                $m = $map[$st] ?? [ucfirst($st), '#50575e'];
                printf('<span style="color:%s;font-weight:600">%s</span>', esc_attr($m[1]), esc_html($m[0]));
                break;
            case 'menu_order':
                echo (int) get_post_field('menu_order', $post_id);
                break;
            case 'bundle_health':
                $h = GALADO_Bundles_Data::health($post_id);
                $c = ['ok' => '#1a7f37', 'warn' => '#bd8600', 'error' => '#d63638'];
                $label = ['ok' => 'Ready', 'warn' => 'Check', 'error' => 'Broken'];
                printf(
                    '<span title="%s" style="color:%s;font-weight:600">&#9679; %s</span>',
                    esc_attr($h['reason']), esc_attr($c[$h['level']] ?? '#50575e'), esc_html($label[$h['level']] ?? '')
                );
                break;
        }
    }

    public static function filters($post_type) {
        if (GALADO_BUNDLES_CPT !== $post_type) return;
        $feat = isset($_GET['galado_featured']) ? (string) $_GET['galado_featured'] : '';
        echo '<select name="galado_featured"><option value="">All bundles</option>';
        echo '<option value="1"' . selected($feat, '1', false) . '>Featured only</option>';
        echo '</select>';
    }

    public static function apply_filters_query($q) {
        if (!is_admin() || !$q->is_main_query()) return;
        if (($q->get('post_type')) !== GALADO_BUNDLES_CPT) return;
        if (isset($_GET['galado_featured']) && '1' === $_GET['galado_featured']) {
            $mq = (array) $q->get('meta_query');
            $mq[] = ['key' => GALADO_BUNDLES_META . 'featured', 'value' => '1'];
            $q->set('meta_query', $mq);
        }
        if ('menu_order' === $q->get('orderby')) {
            $q->set('orderby', 'menu_order');
        }
    }

    public static function row_actions($actions, $post) {
        if (GALADO_BUNDLES_CPT !== $post->post_type) return $actions;
        $url = wp_nonce_url(
            admin_url('admin.php?action=galado_bundle_duplicate&post=' . $post->ID),
            'galado_bundle_duplicate_' . $post->ID
        );
        $actions['duplicate'] = '<a href="' . esc_url($url) . '">Duplicate</a>';
        return $actions;
    }

    /** Clone a bundle to a fresh draft. Heavily used to swap one item. */
    public static function duplicate() {
        $id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$id || !current_user_can('edit_galado_bundles')) wp_die('Not allowed.');
        check_admin_referer('galado_bundle_duplicate_' . $id);
        $src = get_post($id);
        if (!$src || GALADO_BUNDLES_CPT !== $src->post_type) wp_die('Not found.');

        $new_id = wp_insert_post([
            'post_type'   => GALADO_BUNDLES_CPT,
            'post_title'  => $src->post_title . ' (copy)',
            'post_status' => 'draft',
            'menu_order'  => $src->menu_order,
        ]);
        if (is_wp_error($new_id)) wp_die('Could not duplicate.');
        foreach (get_post_meta($id) as $key => $vals) {
            if (0 !== strpos($key, GALADO_BUNDLES_META)) continue;
            $v = maybe_unserialize($vals[0]);
            if ('featured' === substr($key, strlen(GALADO_BUNDLES_META))) $v = '0'; // copies are not featured
            update_post_meta($new_id, $key, $v);
        }
        wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }

    public static function ajax_toggle_featured() {
        check_ajax_referer('galado_bundle_featured', 'nonce');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if (!$id || !current_user_can('edit_galado_bundles')) wp_send_json_error(['msg' => 'Not allowed'], 403);
        $on = '1' === get_post_meta($id, GALADO_BUNDLES_META . 'featured', true);
        update_post_meta($id, GALADO_BUNDLES_META . 'featured', $on ? '0' : '1');
        do_action('galado_bundles_changed', [$id]);
        wp_send_json_success(['featured' => $on ? 0 : 1]);
    }

    public static function list_assets($hook) {
        if ('edit.php' !== $hook) return;
        if (get_current_screen() && GALADO_BUNDLES_CPT !== get_current_screen()->post_type) return;
        $nonce = wp_create_nonce('galado_bundle_featured');
        $js = 'jQuery(function($){$(".galado-bundle-featured").on("click",function(){var b=$(this);'
            . '$.post(ajaxurl,{action:"galado_bundle_toggle_featured",nonce:"' . $nonce . '",id:b.data("id")},function(r){'
            . 'if(r&&r.success){var on=r.data.featured;b.data("on",on).html(on?'
            . '\'<span class="dashicons dashicons-star-filled" style="color:#e4002b"></span>\':'
            . '\'<span class="dashicons dashicons-star-empty" style="color:#c3c4c7"></span>\');}});});});';
        wp_add_inline_script('jquery-core', $js);
    }
}
