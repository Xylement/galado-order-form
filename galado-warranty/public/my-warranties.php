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
// Endpoint registration runs once per request on `init`. Because git-sync
// deploys never fire the activation hook, we also flush the rewrite rules ONCE
// per plugin version here — right after the endpoint is registered so it's
// included. This self-heals the /my-account/warranties/ 404 that appears when
// the rewrite rules get rebuilt without our endpoint (a permalink re-save, a WC
// update, or another plugin flushing). It is NOT a per-request flush: the
// version gate makes it run a single time after each deploy.

add_action('init', function () {
    if (function_exists('add_rewrite_endpoint')) {
        add_rewrite_endpoint('warranties', EP_ROOT | EP_PAGES);
    }
    if (function_exists('flush_rewrite_rules') && defined('GWARR_VERSION')
        && get_option('gwarr_rewrite_version') !== GWARR_VERSION) {
        flush_rewrite_rules(false); // soft flush (rebuilds the option, not .htaccess)
        update_option('gwarr_rewrite_version', GWARR_VERSION, false);
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

    $rows       = GWARR_DB::for_user(get_current_user_id());
    $claim_map  = class_exists('GWARR_Claims') ? GWARR_Claims::map_for_user(get_current_user_id()) : [];

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
    <div class="gwarr-my-wrap">
        <p class="gwarr-coverage-note">
            🛡 What's covered? See our <a href="<?php echo esc_url(gwarr_coverage_url()); ?>" target="_blank" rel="noopener">satisfaction guarantee details</a>.
        </p>
        <div class="gwarr-my-list">
            <?php foreach ($rows as $row): ?>
                <?php gwarr_render_my_warranty_card($row, $claim_map[(int) $row->id] ?? null); ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function gwarr_render_my_warranty_card($row, $claim = null) {
    $marketplace_label = GWARR_Marketplaces::label($row->marketplace);
    $status            = $row->status;
    $is_approved       = $status === 'approved';
    $is_rejected       = $status === 'rejected';
    $is_pending        = $status === 'pending';
    $is_claimed        = $status === 'claimed';

    $warranty_ends = $row->warranty_ends ? mysql2date(get_option('date_format'), $row->warranty_ends) : null;
    $is_expired    = ($is_approved || $is_claimed) && $row->warranty_ends
        && strtotime($row->warranty_ends) < strtotime(current_time('Y-m-d'));

    // Both expired and claimed warranties are "spent" — render them greyed.
    $is_inactive = $is_expired || $is_claimed;
    $has_coverage = $is_approved || $is_claimed; // rows that carry purchase/coverage dates

    $badge_class = 'gwarr-badge ';
    $badge_text  = ucfirst($status);
    if ($is_claimed)                  { $badge_class .= 'gwarr-badge-claimed'; $badge_text = 'Claimed'; }
    elseif ($is_approved && $is_expired) { $badge_class .= 'gwarr-badge-expired'; $badge_text = 'Expired'; }
    elseif ($is_approved)             { $badge_class .= 'gwarr-badge-ok'; $badge_text = 'Active'; }
    elseif ($is_pending)              { $badge_class .= 'gwarr-badge-run'; $badge_text = 'Pending review'; }
    elseif ($is_rejected)             { $badge_class .= 'gwarr-badge-err'; }

    // Website rows store order_number as "{orderId}#{itemId}" for dedup; show
    // the clean order number from wc_order_id instead.
    $is_website     = isset($row->source) && $row->source === 'website';
    $display_order  = ($is_website && !empty($row->wc_order_id)) ? '#' . $row->wc_order_id : $row->order_number;

    $card_class = 'gwarr-warranty-card' . ($is_inactive ? ' gwarr-warranty-card--inactive' : '');
    ?>
    <article class="<?php echo esc_attr($card_class); ?>">
        <header class="gwarr-warranty-head">
            <span class="<?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
            <span class="gwarr-marketplace"><?php echo esc_html($marketplace_label); ?></span>
            <span class="gwarr-order">Order <?php echo esc_html($display_order); ?></span>
        </header>

        <div class="gwarr-warranty-body">
            <?php if (!empty($row->product_text)): ?>
                <div class="gwarr-product"><?php echo gwarr_format_product_html($row->product_text); ?></div>
            <?php endif; ?>

            <?php if ($has_coverage): ?>
                <dl class="gwarr-meta">
                    <?php if (!empty($row->purchase_date)): ?>
                        <dt>Purchased</dt>
                        <dd><?php echo esc_html(mysql2date(get_option('date_format'), $row->purchase_date)); ?></dd>
                    <?php endif; ?>
                    <dt>Warranty <?php echo $is_expired ? 'expired on' : 'covered until'; ?></dt>
                    <dd><?php echo esc_html($warranty_ends); ?></dd>
                    <?php if ($is_claimed && !empty($row->claimed_at)): ?>
                        <dt>Claimed on</dt>
                        <dd><?php echo esc_html(mysql2date(get_option('date_format'), $row->claimed_at)); ?></dd>
                    <?php endif; ?>
                </dl>

                <?php if ($is_claimed): ?>
                    <p class="gwarr-claimed-msg">This item's warranty has been claimed and is now closed.</p>
                <?php endif; ?>

                <?php if (!empty($row->coupon_code)): ?>
                    <div class="gwarr-coupon">
                        <span class="gwarr-coupon-label">Your welcome coupon</span>
                        <code class="gwarr-coupon-code"><?php echo esc_html($row->coupon_code); ?></code>
                        <p class="gwarr-coupon-perks"><?php echo esc_html(gwarr_perk_description()); ?></p>
                        <p class="gwarr-coupon-help">Apply this code at checkout on galado.com.my. Single use, customer-specific.</p>
                    </div>
                <?php endif; ?>

                <?php
                // Claim controls only on an active (approved, non-expired) warranty.
                if ($is_approved && !$is_expired && function_exists('gwarr_render_claim_form')) {
                    $claim_status = $claim ? $claim->status : '';
                    if ($claim_status === 'submitted') {
                        echo '<p class="gwarr-claim-status gwarr-claim-status--review">⏳ Your claim is under review. We\'ll email you with the next steps.</p>';
                    } else {
                        if ($claim_status === 'rejected' && !empty($claim->admin_note)) {
                            echo '<p class="gwarr-claim-status gwarr-claim-status--declined">Your previous claim was declined: <em>'
                                . esc_html($claim->admin_note) . '</em> You can submit a new claim below.</p>';
                        }
                        gwarr_render_claim_form($row);
                    }
                }
                ?>

            <?php elseif ($is_pending): ?>
                <p class="gwarr-pending-msg">
                    We're reviewing your order against our records. You'll receive an email once your warranty is approved.
                </p>

            <?php elseif ($is_rejected): ?>
                <p class="gwarr-rejected-msg">
                    Sorry, we couldn't verify this order.
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
