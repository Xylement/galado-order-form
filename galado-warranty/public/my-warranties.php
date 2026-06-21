<?php
/**
 * Customer-facing list of their own warranty registrations.
 *
 *  - WC My Account tab "Warranties" (endpoint: /my-account/warranties/)
 *  - Shortcode [galado_warranty_list] for use anywhere on the site
 *
 * Both surfaces share gwarr_render_my_warranties() so they stay in lockstep.
 */

if (!defined('ABSPATH')) exit;

// --- Shortcode ---------------------------------------------------------------

add_shortcode('galado_warranty_list', function () {
    ob_start();
    gwarr_render_my_warranties();
    return ob_get_clean();
});

// --- WC My Account endpoint --------------------------------------------------
//
// Endpoint registration runs once per request on `init` (the conventional
// hook). Rewrite-rule flushing is handled by the activation hook in the
// main plugin file — we never flush during normal request lifecycles, since
// flushing fires every other plugin's rewrite_rules_array callbacks and
// makes our plugin a blame magnet for unrelated bugs.

add_action('init', function () {
    if (function_exists('add_rewrite_endpoint')) {
        add_rewrite_endpoint('warranties', EP_ROOT | EP_PAGES);
    }
});

add_filter('woocommerce_account_menu_items', function ($items) {
    if (!is_array($items)) {
        return $items;
    }
    // Insert before "Logout" so it doesn't bury the link.
    $new = [];
    foreach ($items as $key => $label) {
        if ($key === 'customer-logout') {
            $new['warranties'] = 'Warranties';
        }
        $new[$key] = $label;
    }
    if (!isset($new['warranties'])) {
        $new['warranties'] = 'Warranties';
    }
    return $new;
});

add_action('woocommerce_account_warranties_endpoint', function () {
    if (function_exists('gwarr_render_my_warranties')) {
        gwarr_render_my_warranties();
    }
});

// --- Renderer ----------------------------------------------------------------

function gwarr_render_my_warranties() {
    if (!is_user_logged_in()) {
        echo '<p>Please <a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">log in</a> to view your warranties.</p>';
        return;
    }

    // Pop any submission notice stashed by the registration handler. After a
    // successful registration the customer is redirected here, so show the
    // "thanks/registered" confirmation above their warranty list.
    $notice_key = 'gwarr_form_notice_' . get_current_user_id();
    $stashed    = get_transient($notice_key);
    if (is_string($stashed) && $stashed !== '') {
        echo $stashed; // already escaped HTML built by gwarr_notice()
        delete_transient($notice_key);
    }

    $rows = GWARR_DB::for_user(get_current_user_id());

    if (empty($rows)) {
        $register_url = gwarr_register_page_url();
        ?>
        <div class="gwarr-empty">
            <p>You haven't registered any warranties yet.</p>
            <?php if ($register_url): ?>
                <p><a class="button gwarr-btn" href="<?php echo esc_url($register_url); ?>">Register a warranty</a></p>
            <?php endif; ?>
        </div>
        <?php
        return;
    }

    ?>
    <p class="gwarr-coverage-note">
        🛡 What's covered? See our <a href="<?php echo esc_url(gwarr_coverage_url()); ?>" target="_blank" rel="noopener">satisfaction guarantee details</a>.
    </p>
    <div class="gwarr-my-list">
        <?php foreach ($rows as $row): ?>
            <?php gwarr_render_my_warranty_card($row); ?>
        <?php endforeach; ?>
    </div>
    <?php
}

function gwarr_render_my_warranty_card($row) {
    $marketplace_label = GWARR_Marketplaces::label($row->marketplace);
    $status            = $row->status;
    $is_approved       = $status === 'approved';
    $is_rejected       = $status === 'rejected';
    $is_pending        = $status === 'pending';

    $warranty_ends = $row->warranty_ends ? mysql2date(get_option('date_format'), $row->warranty_ends) : null;
    $is_expired    = $is_approved && $row->warranty_ends && strtotime($row->warranty_ends) < strtotime(current_time('Y-m-d'));

    $badge_class = 'gwarr-badge ';
    $badge_text  = ucfirst($status);
    if ($is_approved && !$is_expired) { $badge_class .= 'gwarr-badge-ok'; }
    if ($is_approved && $is_expired)  { $badge_class .= 'gwarr-badge-expired'; $badge_text = 'Expired'; }
    if ($is_pending)                  { $badge_class .= 'gwarr-badge-run'; $badge_text = 'Pending review'; }
    if ($is_rejected)                 { $badge_class .= 'gwarr-badge-err'; }

    ?>
    <article class="gwarr-warranty-card">
        <header class="gwarr-warranty-head">
            <span class="<?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
            <span class="gwarr-marketplace"><?php echo esc_html($marketplace_label); ?></span>
            <span class="gwarr-order">Order <?php echo esc_html($row->order_number); ?></span>
        </header>

        <div class="gwarr-warranty-body">
            <?php if (!empty($row->product_text)): ?>
                <div class="gwarr-product"><?php echo gwarr_format_product_html($row->product_text); ?></div>
            <?php endif; ?>

            <?php if ($is_approved): ?>
                <dl class="gwarr-meta">
                    <dt>Purchased</dt>
                    <dd><?php echo esc_html(mysql2date(get_option('date_format'), $row->purchase_date)); ?></dd>
                    <dt>Warranty <?php echo $is_expired ? 'expired on' : 'covered until'; ?></dt>
                    <dd><?php echo esc_html($warranty_ends); ?></dd>
                </dl>

                <?php if (!empty($row->coupon_code)): ?>
                    <div class="gwarr-coupon">
                        <span class="gwarr-coupon-label">Your welcome coupon</span>
                        <code class="gwarr-coupon-code"><?php echo esc_html($row->coupon_code); ?></code>
                        <p class="gwarr-coupon-perks"><?php echo esc_html(gwarr_perk_description()); ?></p>
                        <p class="gwarr-coupon-help">Apply this code at checkout on galado.com.my. Single use, customer-specific.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($is_pending): ?>
                <p class="gwarr-pending-msg">
                    We're reviewing your order against our records. You'll receive an email once your warranty is approved.
                </p>

            <?php elseif ($is_rejected): ?>
                <p class="gwarr-rejected-msg">
                    Sorry — we couldn't verify this order.
                    <?php if (!empty($row->admin_note)): ?>
                        <br><em><?php echo esc_html($row->admin_note); ?></em>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

/**
 * URL to the registration page — uses the override in settings if provided,
 * otherwise tries to discover any page containing the shortcode, otherwise empty.
 */
function gwarr_register_page_url() {
    $settings = get_option('gwarr_settings', []);
    if (!empty($settings['page_register_url'])) {
        return $settings['page_register_url'];
    }

    // Cache the discovery so we don't run a meta_query on every page load.
    $cached = get_transient('gwarr_register_page_url');
    if ($cached !== false) {
        return $cached === '__none__' ? '' : $cached;
    }

    $page = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        's'              => '[galado_warranty_register',
        'fields'         => 'ids',
    ]);
    $url = !empty($page) ? get_permalink($page[0]) : '__none__';
    set_transient('gwarr_register_page_url', $url, DAY_IN_SECONDS);
    return $url === '__none__' ? '' : $url;
}
