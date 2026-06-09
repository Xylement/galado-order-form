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
        // Preserve the existing service-account JSON when the field is blank
        // (we don't echo the secret back into the form, so an empty post means
        // "no change" rather than "clear it").
        $posted_sa_json = isset($_POST['service_account_json'])
            ? trim((string) wp_unslash($_POST['service_account_json']))
            : '';
        $sa_json = $posted_sa_json !== '' ? $posted_sa_json : ($settings['service_account_json'] ?? '');

        $had_sheet_creds = !empty($settings['sheet_id']) && !empty($settings['service_account_json']);

        $settings = [
            'klaviyo_api_key'      => sanitize_text_field($_POST['klaviyo_api_key'] ?? ''),
            'klaviyo_list_id'      => sanitize_text_field($_POST['klaviyo_list_id'] ?? ''),
            'klaviyo_event_name'   => sanitize_text_field($_POST['klaviyo_event_name'] ?? 'Warranty Approved'),
            'coupon_amount'        => max(0, min(100, (int) ($_POST['coupon_amount'] ?? 10))),
            'coupon_min_spend'     => max(0, (float) ($_POST['coupon_min_spend'] ?? 0)),
            'coupon_expiry_days'   => max(1, min(365, (int) ($_POST['coupon_expiry_days'] ?? 90))),
            'coupon_free_shipping' => isset($_POST['coupon_free_shipping']) ? 1 : 0,
            'warranty_months'      => max(1, min(36, (int) ($_POST['warranty_months'] ?? 6))),
            'from_name'            => sanitize_text_field($_POST['from_name'] ?? 'GALADO'),
            'from_email'           => sanitize_email($_POST['from_email'] ?? ''),
            'page_register_url'    => esc_url_raw($_POST['page_register_url'] ?? ''),
            'support_coverage_url' => esc_url_raw($_POST['support_coverage_url'] ?? ''),
            'sheet_id'             => sanitize_text_field($_POST['sheet_id'] ?? ''),
            'service_account_json' => $sa_json,
            'auto_approve'         => isset($_POST['auto_approve']) ? 1 : 0,
        ];
        update_option('gwarr_settings', $settings);
        delete_transient('gwarr_register_page_url'); // bust cache so override takes effect
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';

        // First time the sheet credentials are populated → kick off an initial
        // sync immediately so customers aren't stuck on "pending" while waiting
        // for the hourly cron to fire.
        $has_sheet_creds_now = !empty($settings['sheet_id']) && (!empty($settings['service_account_json']) || defined('GALADO_GSHEETS_SERVICE_ACCOUNT_JSON'));
        if (!$had_sheet_creds && $has_sheet_creds_now && class_exists('GWARR_Sheet_Sync')) {
            $result = GWARR_Sheet_Sync::run(true);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-warning"><p>Saved, but initial sync failed: ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $kept = isset($result['rows_kept']) ? (int) $result['rows_kept'] : 0;
                echo '<div class="notice notice-success"><p>Initial sheet sync complete — cached ' . $kept . ' order(s). Auto-approve is now live.</p></div>';
            }
        }
    }

    // ---- Manual sync ----
    if (isset($_POST['gwarr_sync_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_sync_nonce'])), 'gwarr_sync')) {
        if (class_exists('GWARR_Sheet_Sync')) {
            $result = GWARR_Sheet_Sync::run(true);
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>Sync failed: ' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $kept = isset($result['rows_kept']) ? (int) $result['rows_kept'] : 0;
                $tabs = isset($result['tabs_seen']) ? (int) $result['tabs_seen'] : 0;
                echo '<div class="notice notice-success"><p>Sync complete — read ' . $tabs . ' tab(s), cached ' . $kept . ' order(s).</p></div>';
                if (!empty($result['tab_errors'])) {
                    echo '<div class="notice notice-warning"><p>Some tabs reported errors:<br>' . esc_html(implode(' | ', $result['tab_errors'])) . '</p></div>';
                }
            }
        }
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
                <tr>
                    <th scope="row">Free shipping</th>
                    <td>
                        <label>
                            <input type="checkbox" name="coupon_free_shipping" value="1" <?php checked(!empty($settings['coupon_free_shipping'])); ?>>
                            Also waive shipping on the customer's next order
                        </label>
                        <p class="description">
                            For this flag to actually waive shipping at checkout you also need a
                            <strong>Free Shipping</strong> method on at least one zone in
                            <em>WooCommerce → Settings → Shipping</em> with <strong>"A valid free shipping coupon"</strong> set under "Free Shipping Requires". Otherwise the coupon discounts the order but shipping still charges normally.
                        </p>
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

            <h2 class="title">Auto-Approve (Google Sheet sync)</h2>
            <?php
            $sa_via_constant = defined('GALADO_GSHEETS_SERVICE_ACCOUNT_JSON');
            $sa_present      = $sa_via_constant || !empty($settings['service_account_json']);
            $last_sync       = get_option('gwarr_last_sheet_sync', '');
            $last_sync_stats = get_option('gwarr_last_sheet_sync_stats', []);
            ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Enable auto-approve</th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_approve" value="1" <?php checked(!empty($settings['auto_approve'])); ?>>
                            On submit, look up the order in the cached sheet and auto-approve if found
                        </label>
                        <p class="description">If the order isn't found in the cache, the registration falls back to manual review.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Sheet ID</th>
                    <td>
                        <input type="text" name="sheet_id" value="<?php echo esc_attr($settings['sheet_id'] ?? ''); ?>" class="regular-text" placeholder="1uZyQiQm7E7lLykzzSYhBCjLt3iVkKmoJm-61hjnyvQw">
                        <p class="description">The long ID from the sheet URL (between <code>/d/</code> and <code>/edit</code>). Reads columns A (marketplace), B (product), E (order #), J (purchase date) from every monthly tab.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Service account JSON</th>
                    <td>
                        <?php if ($sa_via_constant): ?>
                            <p>
                                <span class="dashicons dashicons-shield-alt" style="color:#00a32a;"></span>
                                Provided via <code>GALADO_GSHEETS_SERVICE_ACCOUNT_JSON</code> constant in <code>wp-config.php</code> — recommended.
                            </p>
                        <?php else: ?>
                            <textarea name="service_account_json" rows="4" class="large-text" autocomplete="off" placeholder="Paste the JSON key file contents here (or set GALADO_GSHEETS_SERVICE_ACCOUNT_JSON in wp-config.php instead)"><?php /* don't echo the secret back — empty field means "no change" */ ?></textarea>
                            <?php if (!empty($settings['service_account_json'])): ?>
                                <p class="description"><span class="dashicons dashicons-yes" style="color:#00a32a;"></span> Already stored. Leave this field blank to keep the existing value, or paste new contents to replace.</p>
                            <?php else: ?>
                                <p class="description">Paste the full JSON for service account <code>helix-sheets@galado-447205.iam.gserviceaccount.com</code>. For better security, define <code>GALADO_GSHEETS_SERVICE_ACCOUNT_JSON</code> in <code>wp-config.php</code> instead (either inline JSON or a file path).</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Sync status</th>
                    <td>
                        <?php
                        global $wpdb;
                        $cache_count = 0;
                        if (class_exists('GWARR_Sheet_Sync')) {
                            $cache_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . GWARR_Sheet_Sync::cache_table());
                        }
                        $next_cron = wp_next_scheduled('gwarr_sheet_sync');
                        ?>
                        <?php if (!$sa_present || empty($settings['sheet_id'])): ?>
                            <em>Configure Sheet ID + service account above, then save and use the Sync now button below.</em>
                        <?php elseif ($last_sync): ?>
                            <strong>Last sync:</strong> <?php echo esc_html(mysql2date(get_option('date_format') . ' g:i a', $last_sync)); ?>
                            <?php if (!empty($last_sync_stats['rows_kept'])): ?>
                                — kept <?php echo (int) $last_sync_stats['rows_kept']; ?> order(s) across <?php echo (int) ($last_sync_stats['tabs_seen'] ?? 0); ?> tab(s)
                            <?php endif; ?>
                            <?php if (!empty($last_sync_stats['tab_errors'])): ?>
                                <br><span style="color:#d63638;">Errors: <?php echo esc_html(implode(' | ', $last_sync_stats['tab_errors'])); ?></span>
                            <?php endif; ?>
                            <br><strong>Orders in cache:</strong> <?php echo number_format($cache_count); ?>
                            <?php if ($next_cron): ?>
                                <br><strong>Next auto-sync:</strong> <?php echo esc_html(mysql2date(get_option('date_format') . ' g:i a', date('Y-m-d H:i:s', $next_cron))); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <strong style="color:#dba617;">Never synced.</strong> Auto-approve only works once the local cache is populated — click <em>Sync sheet now</em> below to do the first run.
                        <?php endif; ?>
                        <?php if ($cache_count === 0 && $sa_present && !empty($settings['sheet_id']) && !empty($settings['auto_approve'])): ?>
                            <p style="color:#dba617;margin:8px 0 0;">
                                ⚠ Auto-approve is enabled but the cache is empty — every submission will fall to manual review until the sheet is synced.
                            </p>
                        <?php endif; ?>
                        <p class="description">Automatic hourly sync via WP-Cron. After every sync, any pending registrations that now have a matching cache entry are auto-approved.</p>
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
                <tr>
                    <th scope="row">Coverage page URL</th>
                    <td>
                        <input type="url" name="support_coverage_url" value="<?php echo esc_attr($settings['support_coverage_url'] ?? ''); ?>" class="regular-text" placeholder="https://galado.com.my/support/#tab_satisfaction-guarantee">
                        <p class="description">Linked from the registration form, My Warranties, and approval emails as "what's covered". Defaults to your satisfaction-guarantee tab.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('gwarr_sync', 'gwarr_sync_nonce'); ?>
            <button type="submit" class="button">🔄 Sync sheet now</button>
            <span class="description" style="margin-left:8px;">Manually pulls fresh data from the sheet and re-checks pending registrations.</span>
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
