<?php
/**
 * Settings page for the Warranty plugin.
 */

if (!defined('ABSPATH')) exit;

function gwarr_render_settings_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Forbidden');
    }

    $settings = wp_parse_args(get_option('gwarr_settings', []), gwarr_default_settings());

    // ---- Save ----
    if (isset($_POST['gwarr_save_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_save_nonce'])), 'gwarr_save')) {
        $settings = [
            'klaviyo_api_key'    => sanitize_text_field($_POST['klaviyo_api_key'] ?? ''),
            'klaviyo_list_id'    => sanitize_text_field($_POST['klaviyo_list_id'] ?? ''),
            'klaviyo_event_name' => sanitize_text_field($_POST['klaviyo_event_name'] ?? 'Warranty Approved'),
            'coupon_amount'      => max(0, min(100, (int) ($_POST['coupon_amount'] ?? 10))),
            'coupon_min_spend'   => max(0, (float) ($_POST['coupon_min_spend'] ?? 0)),
            'coupon_expiry_days' => max(1, min(365, (int) ($_POST['coupon_expiry_days'] ?? 90))),
            'warranty_months'    => max(1, min(36, (int) ($_POST['warranty_months'] ?? 6))),
            'from_name'          => sanitize_text_field($_POST['from_name'] ?? 'GALADO'),
            'from_email'         => sanitize_email($_POST['from_email'] ?? ''),
            'page_register_url'  => esc_url_raw($_POST['page_register_url'] ?? ''),
        ];
        update_option('gwarr_settings', $settings);
        delete_transient('gwarr_register_page_url'); // bust cache so override takes effect
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    ?>
    <div class="wrap gwarr-admin">
        <h1>Warranty Settings</h1>

        <form method="post">
            <?php wp_nonce_field('gwarr_save', 'gwarr_save_nonce'); ?>

            <h2 class="title">Warranty Policy</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Warranty period</th>
                    <td>
                        <input type="number" name="warranty_months" value="<?php echo esc_attr($settings['warranty_months']); ?>" min="1" max="36" style="width:80px;"> months from purchase date
                    </td>
                </tr>
            </table>

            <h2 class="title">Welcome Coupon</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Discount</th>
                    <td>
                        <input type="number" name="coupon_amount" value="<?php echo esc_attr($settings['coupon_amount']); ?>" min="0" max="100" style="width:80px;">%
                        <p class="description">Percentage off the customer's next direct-website order.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Minimum spend</th>
                    <td>
                        <input type="number" name="coupon_min_spend" value="<?php echo esc_attr($settings['coupon_min_spend']); ?>" min="0" step="0.01" style="width:120px;"> <?php echo esc_html(get_woocommerce_currency()); ?>
                        <p class="description">Leave at 0 for no minimum.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Expiry</th>
                    <td>
                        <input type="number" name="coupon_expiry_days" value="<?php echo esc_attr($settings['coupon_expiry_days']); ?>" min="1" max="365" style="width:80px;"> days from issue
                    </td>
                </tr>
            </table>

            <h2 class="title">Klaviyo</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Private API key</th>
                    <td>
                        <input type="password" name="klaviyo_api_key" value="<?php echo esc_attr($settings['klaviyo_api_key']); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Klaviyo → Account → Settings → API keys. Starts with <code>pk_</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Marketplace Buyers list ID</th>
                    <td>
                        <input type="text" name="klaviyo_list_id" value="<?php echo esc_attr($settings['klaviyo_list_id']); ?>" class="regular-text">
                        <p class="description">Create a list named "Marketplace Buyers" in Klaviyo and paste its ID here. Customers are only added to the list if they ticked the marketing consent box.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Event name</th>
                    <td>
                        <input type="text" name="klaviyo_event_name" value="<?php echo esc_attr($settings['klaviyo_event_name']); ?>" class="regular-text">
                        <p class="description">The metric/event fired on approval — use this to trigger Klaviyo flows.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Customer Emails</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">From name</th>
                    <td>
                        <input type="text" name="from_name" value="<?php echo esc_attr($settings['from_name']); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">From email</th>
                    <td>
                        <input type="email" name="from_email" value="<?php echo esc_attr($settings['from_email']); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                        <p class="description">Leave blank to use the site admin email.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Registration Page</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Registration page URL</th>
                    <td>
                        <input type="url" name="page_register_url" value="<?php echo esc_attr($settings['page_register_url']); ?>" class="regular-text" placeholder="https://galado.com.my/register-warranty/">
                        <p class="description">Optional override — the "Register a warranty" CTA in My Warranties points here. Leave blank to auto-detect (any page that contains <code>[galado_warranty_register]</code>).</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr style="margin:40px 0;">

        <h2>Shortcodes</h2>
        <table class="widefat striped" style="max-width:700px;">
            <thead><tr><th>Shortcode</th><th>Where it goes</th></tr></thead>
            <tbody>
                <tr><td><code>[galado_warranty_register]</code></td><td>The registration form. Drop on a page like <code>/register-warranty/</code>.</td></tr>
                <tr><td><code>[galado_warranty_list]</code></td><td>The customer's "My Warranties" view. The same view also appears as a tab in WooCommerce My Account at <code>/my-account/warranties/</code>.</td></tr>
            </tbody>
        </table>
    </div>
    <?php
}
