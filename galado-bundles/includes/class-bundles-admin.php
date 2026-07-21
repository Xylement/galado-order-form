<?php
/**
 * The bundle add/edit screen: three meta boxes (items, pricing/honesty,
 * display/rotation) and save-time validation (spec 4.5, 4.6). Writes no product
 * data; only the bundle post's own meta.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Bundles_Admin {

    private static $saving = false; // recursion guard for status corrections

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'meta_boxes']);
        add_action('save_post_' . GALADO_BUNDLES_CPT, [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_notices', [__CLASS__, 'notices']);
    }

    public static function meta_boxes() {
        add_meta_box('galado_bundle_items', 'Bundle items', [__CLASS__, 'box_items'], GALADO_BUNDLES_CPT, 'normal', 'high');
        add_meta_box('galado_bundle_pricing', 'Pricing and honesty', [__CLASS__, 'box_pricing'], GALADO_BUNDLES_CPT, 'normal', 'default');
        add_meta_box('galado_bundle_display', 'Display and rotation', [__CLASS__, 'box_display'], GALADO_BUNDLES_CPT, 'side', 'default');
    }

    public static function box_items($post) {
        wp_nonce_field('galado_bundle_save', 'galado_bundle_nonce');
        $items = GALADO_Bundles_Data::items($post->ID);
        echo '<div id="galado-bundle-items" data-items="' . esc_attr(wp_json_encode($items)) . '"></div>';
        echo '<input type="hidden" name="galado_bundle_items_json" id="galado-bundle-items-json" value="' . esc_attr(wp_json_encode($items)) . '">';
        echo '<p class="description">Add a product, drag to reorder. A variable product (Stylink, cases) lets you pin one variation or let the shopper pick. The Studio case is excluded on purpose.</p>';
    }

    public static function box_pricing($post) {
        $save = get_post_meta($post->ID, GALADO_BUNDLES_META . 'save', true);
        echo '<p><label><strong>Flat saving (RM)</strong><br>';
        echo '<input type="number" step="0.01" min="0" name="galado_bundle_save" id="galado-bundle-save" value="' . esc_attr($save) . '" class="regular-text" style="max-width:140px"></label>';
        echo ' <span class="description">Margin-funded. Leave 0 for a link-only set (no saving).</span></p>';
        echo '<p id="galado-bundle-honesty" style="font-size:13px;color:#50575e"></p>';
        echo '<p><label><input type="checkbox" name="galado_bundle_stack_qty" value="1" ' . checked('1', get_post_meta($post->ID, GALADO_BUNDLES_META . 'stack_qty', true), false) . '> Multiply the saving when the same set is bought more than once</label> <span class="description">(off by default)</span></p>';
    }

    public static function box_display($post) {
        $img  = (int) get_post_meta($post->ID, GALADO_BUNDLES_META . 'image', true);
        $blurb = get_post_meta($post->ID, GALADO_BUNDLES_META . 'blurb', true);
        $cta  = get_post_meta($post->ID, GALADO_BUNDLES_META . 'cta', true);
        $feat = get_post_meta($post->ID, GALADO_BUNDLES_META . 'featured', true);

        echo '<p><label><input type="checkbox" name="galado_bundle_featured" value="1" ' . checked('1', $feat, false) . '> <strong>Featured</strong> (shown in the home band)</label></p>';
        echo '<p><label>Subtitle<br><input type="text" maxlength="140" name="galado_bundle_blurb" value="' . esc_attr($blurb) . '" class="widefat"></label></p>';
        echo '<p><label>CTA label<br><input type="text" name="galado_bundle_cta" value="' . esc_attr($cta) . '" class="widefat" placeholder="Add the set"></label></p>';

        echo '<p><strong>Set image</strong></p>';
        echo '<div id="galado-bundle-image-wrap">';
        $src = $img ? wp_get_attachment_image_url($img, 'medium') : '';
        echo '<img id="galado-bundle-image-preview" src="' . esc_url($src) . '" style="max-width:100%;height:auto;display:' . ($src ? 'block' : 'none') . ';border:1px solid #dcdcde;border-radius:6px;margin-bottom:6px">';
        echo '<input type="hidden" name="galado_bundle_image" id="galado-bundle-image" value="' . esc_attr($img) . '">';
        echo '<button type="button" class="button" id="galado-bundle-image-pick">Choose image</button> ';
        echo '<button type="button" class="button-link" id="galado-bundle-image-clear" style="' . ($src ? '' : 'display:none') . '">Remove</button>';
        echo '</div>';
        echo '<p class="description" style="margin-top:10px">Set Active/Inactive with the Publish box: Published = active, Draft = inactive.</p>';
    }

    /** Validation and caching (spec 4.6). */
    public static function save($post_id, $post) {
        if (self::$saving) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST['galado_bundle_nonce']) || !wp_verify_nonce($_POST['galado_bundle_nonce'], 'galado_bundle_save')) return;
        if (!current_user_can('edit_galado_bundle', $post_id)) return;

        $notices = ['error' => [], 'warn' => []];

        // Scalars.
        update_post_meta($post_id, GALADO_BUNDLES_META . 'save', max(0, (float) ($_POST['galado_bundle_save'] ?? 0)));
        update_post_meta($post_id, GALADO_BUNDLES_META . 'featured', isset($_POST['galado_bundle_featured']) ? '1' : '0');
        update_post_meta($post_id, GALADO_BUNDLES_META . 'stack_qty', isset($_POST['galado_bundle_stack_qty']) ? '1' : '0');
        update_post_meta($post_id, GALADO_BUNDLES_META . 'image', (int) ($_POST['galado_bundle_image'] ?? 0));
        update_post_meta($post_id, GALADO_BUNDLES_META . 'blurb', sanitize_text_field(wp_unslash($_POST['galado_bundle_blurb'] ?? '')));
        update_post_meta($post_id, GALADO_BUNDLES_META . 'cta', sanitize_text_field(wp_unslash($_POST['galado_bundle_cta'] ?? '')));

        // Items.
        $raw = isset($_POST['galado_bundle_items_json']) ? wp_unslash($_POST['galado_bundle_items_json']) : '[]';
        $in  = json_decode($raw, true);
        $items = self::sanitise_items(is_array($in) ? $in : [], $notices);

        if (!$items) {
            $notices['error'][] = 'A bundle needs at least one valid item. Kept as a draft.';
        }

        $save = (float) ($_POST['galado_bundle_save'] ?? 0);
        $sum  = 0.0;
        foreach ($items as $it) $sum += $it['price_cache'] * $it['qty'];

        if ($save > 0 && $sum > 0) {
            if ($save >= $sum) {
                $notices['error'][] = sprintf('The saving (RM%s) is not less than the buy-separately total (RM%s). Kept as a draft.', number_format($save, 2), number_format($sum, 2));
            } elseif ($save > 0.4 * $sum) {
                $notices['warn'][] = sprintf('The saving (RM%s) is over 40%% of the buy-separately total (RM%s). Double-check it is really margin-funded.', number_format($save, 2), number_format($sum, 2));
            }
        }

        self::double_bundle_note($items, $notices);
        self::wcpa_note($items, $notices);
        if ($items && !GALADO_Bundles_Data::is_buyable($items)) {
            $notices['warn'][] = 'An item is out of stock. The bundle is saved but hidden on the storefront until it is back.';
        }

        update_post_meta($post_id, GALADO_BUNDLES_META . 'items', wp_json_encode($items));
        update_post_meta($post_id, GALADO_BUNDLES_META . 'mode', GALADO_Bundles_Data::derive_mode($items, $save));

        // A hard error must never leave a broken bundle Active/Featured.
        if ($notices['error'] && 'publish' === $post->post_status) {
            self::$saving = true;
            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            update_post_meta($post_id, GALADO_BUNDLES_META . 'featured', '0');
            self::$saving = false;
        }

        set_transient('galado_bundles_notice_' . $post_id . '_' . get_current_user_id(), $notices, 60);
        do_action('galado_bundles_changed', [$post_id]);
    }

    /** Validate + cache every line. Self-heals stale variation ids. */
    private static function sanitise_items($in, &$notices) {
        $out = [];
        $used_slots = [];
        foreach ($in as $r) {
            $pid = (int) ($r['product_id'] ?? 0);
            if (!$pid) continue;
            if (in_array($pid, galado_bundles_excluded_products(), true)) {
                $notices['warn'][] = 'Product #' . $pid . ' cannot be bundled and was dropped.';
                continue;
            }
            $p = wc_get_product($pid);
            if (!$p || 'publish' !== $p->get_status() || !$p->is_purchasable()) {
                $notices['warn'][] = 'Product #' . $pid . ' is not a published, purchasable product and was dropped.';
                continue;
            }
            $is_variable = $p->is_type('variable');
            $line_type = $is_variable ? 'variable' : 'simple';
            $qty = max(1, (int) ($r['qty'] ?? 1));

            $mode = 'fixed';
            $default_variation = 0;
            if ($is_variable) {
                $mode = ('shopper_choice' === ($r['variation_mode'] ?? '')) ? 'shopper_choice' : 'pinned';
                $default_variation = (int) ($r['default_variation_id'] ?? 0);
                if ($default_variation) {
                    $v = wc_get_product($default_variation);
                    if (!$v || $v->get_parent_id() !== $pid) {
                        $notices['warn'][] = 'A saved variation for ' . $p->get_name() . ' no longer exists and was cleared.';
                        $default_variation = 0;
                    }
                }
                if ('pinned' === $mode && !$default_variation) {
                    // pin needs a variation; fall back to the first purchasable one and warn.
                    $default_variation = self::first_purchasable_variation($p);
                    if ($default_variation) {
                        $notices['warn'][] = 'No variation was pinned for ' . $p->get_name() . '; the first available one was used.';
                    } else {
                        $notices['warn'][] = $p->get_name() . ' has no available variation and was dropped.';
                        continue;
                    }
                }
            }

            $slot = sanitize_key($r['slot'] ?? '');
            if ('' === $slot || isset($used_slots[$slot])) $slot = 'slot' . count($out);
            $used_slots[$slot] = true;

            $price = $is_variable
                ? (float) wc_get_price_to_display($default_variation ? wc_get_product($default_variation) : $p)
                : (float) wc_get_price_to_display($p);

            $out[] = [
                'slot'                 => $slot,
                'product_id'           => $pid,
                'line_type'            => $line_type,
                'qty'                  => $qty,
                'variation_mode'       => $mode,
                'default_variation_id' => $default_variation,
                'label'                => sanitize_text_field($r['label'] ?? ''),
                'name_cache'           => $p->get_name(),
                'price_cache'          => round($price, 2),
            ];
        }
        return $out;
    }

    private static function first_purchasable_variation($parent) {
        foreach ($parent->get_children() as $cid) {
            $v = wc_get_product($cid);
            if ($v && $v->is_purchasable() && $v->is_in_stock()) return $cid;
        }
        return 0;
    }

    /** Stylink is already a chain + charm kit; adding a separate loose charm
     * double-charges the charm (spec 7.5). Warn, do not block. */
    private static function double_bundle_note($items, &$notices) {
        $has_chain_charm = false;
        $has_loose_charm = false;
        foreach ($items as $it) {
            $name = strtolower($it['name_cache']);
            if ('variable' === $it['line_type']) {
                $p = wc_get_product($it['product_id']);
                if ($p) {
                    foreach ($p->get_children() as $cid) {
                        $v = wc_get_product($cid);
                        if ($v) {
                            $attrs = $v->get_variation_attributes();
                            $lbl = $attrs ? strtolower((string) reset($attrs)) : '';
                            if (0 === strpos($lbl, 'chain + ')) { $has_chain_charm = true; break; }
                        }
                    }
                }
            }
            if (false !== strpos($name, 'charm') && 'simple' === $it['line_type']) $has_loose_charm = true;
        }
        if ($has_chain_charm && $has_loose_charm) {
            $notices['warn'][] = 'This kit has a chain + charm product AND a loose charm. That can double-charge the charm; check the value story.';
        }
    }

    private static function wcpa_note($items, &$notices) {
        $flagged = [];
        foreach ($items as $it) {
            if ('' !== GALADO_Bundles_Data::wcpa_addon_note($it['product_id'])) $flagged[] = $it['name_cache'];
        }
        if ($flagged) {
            $notices['warn'][] = 'These items have add-on fields (' . esc_html(implode(', ', $flagged)) . '). Bundles add items without add-ons, the same way the one-click sets work today. Confirm none of the add-ons are mandatory before featuring.';
        }
    }

    public static function notices() {
        $screen = get_current_screen();
        if (!$screen || GALADO_BUNDLES_CPT !== $screen->post_type || 'post' !== $screen->base) return;
        global $post;
        if (!$post) return;
        $key = 'galado_bundles_notice_' . $post->ID . '_' . get_current_user_id();
        $n = get_transient($key);
        if (!$n) return;
        delete_transient($key);
        foreach (['error', 'warn'] as $lvl) {
            foreach ($n[$lvl] as $msg) {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $lvl ? 'error' : 'warning', esc_html($msg));
            }
        }
    }

    public static function assets($hook) {
        $screen = get_current_screen();
        if (!$screen || GALADO_BUNDLES_CPT !== $screen->post_type) return;
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('galado-bundles-admin', GALADO_BUNDLES_URL . 'admin/admin.css', [], GALADO_BUNDLES_VERSION);
        wp_enqueue_script('galado-bundles-admin', GALADO_BUNDLES_URL . 'admin/admin.js', ['jquery', 'jquery-ui-sortable'], GALADO_BUNDLES_VERSION, true);
        wp_localize_script('galado-bundles-admin', 'GALADO_BUNDLES_ADMIN', [
            'search'     => rest_url('galado-bundles/v1/product-search'),
            'variations' => rest_url('galado-bundles/v1/variations'),
            'nonce'      => wp_create_nonce('wp_rest'),
        ]);
    }
}
