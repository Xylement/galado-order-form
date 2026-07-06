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
            'claim_notify_email'   => sanitize_email($_POST['claim_notify_email'] ?? ''),
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

    // ---- Website-order backfill ----
    if (isset($_POST['gwarr_backfill_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_backfill_nonce'])), 'gwarr_backfill')) {
        if (class_exists('GWARR_Orders')) {
            GWARR_Orders::start_backfill();
            echo '<div class="notice notice-success"><p>Backfill started — it runs in the background. Refresh this page to watch progress.</p></div>';
        }
    }

    // ---- Create missing warranty coupons ----
    if (isset($_POST['gwarr_couponfix_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_couponfix_nonce'])), 'gwarr_couponfix')) {
        if (class_exists('GWARR_Coupon')) {
            GWARR_Coupon::start_repair();
            echo '<div class="notice notice-success"><p>Coupon repair started, it runs in the background. Keep this page open to watch progress.</p></div>';
        }
    }

    // ---- Send sample claim emails ----
    if (isset($_POST['gwarr_sample_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_sample_nonce'])), 'gwarr_sample')) {
        $to = sanitize_email($_POST['gwarr_sample_to'] ?? '');
        if (!is_email($to)) {
            echo '<div class="notice notice-error"><p>Please enter a valid email address for the sample.</p></div>';
        } elseif (class_exists('GWARR_Email')) {
            $n = GWARR_Email::send_sample_claim_emails($to);
            echo '<div class="notice notice-' . ($n ? 'success' : 'error') . '"><p>'
                . ($n ? 'Sent ' . (int) $n . ' sample email(s) to ' . esc_html($to) . ' (received, approved, declined + the admin alert). Check the inbox — and spam, just in case.'
                      : 'Could not send — wp_mail returned false. Check your site\'s email/SMTP setup.')
                . '</p></div>';
        }
    }

    // ---- Diagnostics ----
    $diag = null;
    if (isset($_POST['gwarr_diag_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_diag_nonce'])), 'gwarr_diag')) {
        if (class_exists('GWARR_Diagnostics')) {
            $diag = GWARR_Diagnostics::run();
        }
    }

    ?>
    <div class="wrap gwarr-admin">
        <h1>Warranty Settings</h1>

        <?php if ($diag !== null) { gwarr_render_diagnostics_results($diag); } ?>

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
                            Surface "free shipping" as part of the coupon perks
                        </label>
                        <p class="description">
                            <strong>On by default.</strong> Even though GALADO already ships free within Malaysia,
                            marketplace buyers don't read shipping policies — they react to "10% off + free shipping"
                            on the coupon as a stronger conversion signal. The text appears in the registration form,
                            My Warranties view, and approval email.
                            <br><br>
                            At checkout the flag is a no-op (shipping is already free). If your shipping config ever requires
                            <em>"A valid free shipping coupon"</em>, this same coupon would then also unlock shipping —
                            no extra work needed.
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
                <tr>
                    <th scope="row">Claim notification email</th>
                    <td>
                        <input type="email" name="claim_notify_email" value="<?php echo esc_attr($settings['claim_notify_email'] ?? ''); ?>" class="regular-text" placeholder="warranty@galado.com.my">
                        <p class="description">Where new warranty-<strong>claim</strong> submissions are sent for review. Defaults to warranty@galado.com.my.</p>
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

        <h2 class="title">Website Orders</h2>
        <p class="description" style="max-width:760px;">
            New WooCommerce orders (Processing or Completed) are auto-added as per-item warranties automatically.
            Use this one-time backfill to also capture orders from the last <?php echo (int) (class_exists('GWARR_Orders') ? GWARR_Orders::BACKFILL_MONTHS : 12); ?> months.
            It runs in the background in small batches and is safe to re-run (each item is added only once).
        </p>
        <?php
        $bf_running = false;
        if (class_exists('GWARR_Orders')) {
            $bf = GWARR_Orders::get_backfill_state();
            $bf_running = $bf['status'] === 'running';

            if ($bf_running) {
                // Auto-advance: the page reloads every few seconds, and each load
                // processes one batch AFTER the response is flushed (no WP-Cron,
                // no blocking the render). Deferring past the response means a
                // slow per-order Club lookup can never stall or break this page.
                echo '<meta http-equiv="refresh" content="5">';
                if (class_exists('GWARR_Deferred')) {
                    GWARR_Deferred::add(function () { GWARR_Orders::run_backfill_batch(); });
                } else {
                    GWARR_Orders::run_backfill_batch();
                }
            }

            echo '<p style="margin:6px 0;">';
            if ($bf['status'] === 'idle') {
                echo '<em>Not run yet.</em>';
            } elseif ($bf_running) {
                echo '<strong style="color:#dba617;">Running…</strong> processed ' . (int) $bf['processed'] . ' order(s), created ' . (int) $bf['created'] . ' warranty row(s) so far. <em>Keep this page open — it advances automatically every few seconds.</em>';
            } elseif ($bf['status'] === 'done') {
                echo '<strong style="color:#00a32a;">Done</strong> — processed ' . (int) $bf['processed'] . ' order(s), created ' . (int) $bf['created'] . ' warranty row(s). Finished ' . esc_html($bf['finished']) . '.';
            }
            echo '</p>';
        }
        ?>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('gwarr_backfill', 'gwarr_backfill_nonce'); ?>
            <button type="submit" class="button"><?php echo $bf_running ? '🔄 Restart backfill' : '📦 Backfill recent website orders'; ?></button>
            <span class="description" style="margin-left:8px;">Advances automatically while this page is open. Safe to re-run (idempotent).</span>
        </form>

        <hr style="margin:40px 0;">

        <h2 class="title">Warranty Coupons</h2>
        <p class="description" style="max-width:760px;">
            Every approved registration is issued a welcome coupon (e.g. <code>W-XXXXXX</code>). This scans all of them and
            <strong>creates any WooCommerce coupon that is missing</strong>, so codes shown to customers actually work at
            checkout. Existing coupons are left untouched. Runs in batches; keep this page open until it says Done.
        </p>
        <?php
        $cr_running = false;
        if (class_exists('GWARR_Coupon')) {
            $cr = GWARR_Coupon::get_repair_state();
            $cr_running = $cr['status'] === 'running';
            if ($cr_running) {
                echo '<meta http-equiv="refresh" content="5">';
                if (class_exists('GWARR_Deferred')) {
                    GWARR_Deferred::add(function () { GWARR_Coupon::run_repair_batch(); });
                } else {
                    GWARR_Coupon::run_repair_batch();
                }
            }
            echo '<p style="margin:6px 0;">';
            if ($cr['status'] === 'idle') {
                echo '<em>Not run yet.</em>';
            } elseif ($cr_running) {
                echo '<strong style="color:#dba617;">Running…</strong> checked ' . (int) $cr['checked'] . ' of ' . (int) $cr['total']
                    . ', created ' . (int) $cr['created'] . ', already existed ' . (int) $cr['existing']
                    . ($cr['failed'] ? ', failed ' . (int) $cr['failed'] : '') . '. <em>Keep this page open, it advances automatically.</em>';
            } elseif ($cr['status'] === 'done') {
                echo '<strong style="color:#00a32a;">Done</strong> — checked ' . (int) $cr['checked'] . ', created ' . (int) $cr['created']
                    . ' missing coupon(s), ' . (int) $cr['existing'] . ' already existed'
                    . ($cr['failed'] ? ', <strong style="color:#d63638;">' . (int) $cr['failed'] . ' failed (check error log)</strong>' : '')
                    . '. Finished ' . esc_html($cr['finished']) . '.';
            }
            echo '</p>';
        }
        ?>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('gwarr_couponfix', 'gwarr_couponfix_nonce'); ?>
            <button type="submit" class="button"><?php echo $cr_running ? '🔄 Restart coupon repair' : '🎟️ Create missing warranty coupons'; ?></button>
            <span class="description" style="margin-left:8px;">Only creates coupons that don't exist yet. Safe to re-run.</span>
        </form>

        <hr style="margin:40px 0;">

        <h2 class="title">Sample Emails</h2>
        <p class="description" style="max-width:760px;">
            Sends the three customer claim emails (received, approved, declined) plus the internal admin alert — all in the
            GALADO Club style — to an address so you can preview them in a real inbox. Subjects are prefixed <code>[SAMPLE]</code>.
        </p>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('gwarr_sample', 'gwarr_sample_nonce'); ?>
            <input type="email" name="gwarr_sample_to" value="clement@galado.com.my" class="regular-text" style="max-width:280px;">
            <button type="submit" class="button">✉️ Send sample emails</button>
            <span class="description" style="margin-left:8px;">Sends 4 emails via the site's normal mailer.</span>
        </form>

        <hr style="margin:40px 0;">

        <h2 class="title">Diagnostics</h2>
        <p class="description" style="max-width:700px;">
            Times each external call a registration makes (Club lookup, Club webhook, email, Klaviyo) and reports the environment,
            so we can see exactly what's slow. Sends one test email to the site admin; otherwise read-only.
        </p>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('gwarr_diag', 'gwarr_diag_nonce'); ?>
            <button type="submit" class="button">🩺 Run diagnostics</button>
            <span class="description" style="margin-left:8px;">Takes a few seconds — it runs the real calls.</span>
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

/**
 * Render the results of a diagnostics run at the top of the settings page.
 */
function gwarr_render_diagnostics_results($diag) {
    ?>
    <div class="notice notice-info" style="padding:14px 16px;">
        <h2 style="margin-top:0;">Diagnostics results</h2>

        <h3 style="margin-bottom:4px;">Environment</h3>
        <table class="widefat striped" style="max-width:760px;margin-bottom:16px;">
            <tbody>
                <?php foreach ($diag['env'] as $label => $value): ?>
                    <tr>
                        <td style="width:240px;"><strong><?php echo esc_html($label); ?></strong></td>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin-bottom:4px;">Step timings</h3>
        <table class="widefat striped" style="max-width:760px;margin-bottom:16px;">
            <thead><tr><th style="width:280px;">Step</th><th style="width:110px;">Time</th><th>Result</th></tr></thead>
            <tbody>
                <?php foreach ($diag['timings'] as $t): ?>
                    <?php $slow = $t['ms'] >= 3000; ?>
                    <tr>
                        <td><?php echo esc_html($t['label']); ?></td>
                        <td style="<?php echo $slow ? 'color:#d63638;font-weight:700;' : ''; ?>">
                            <?php echo esc_html(GWARR_Diagnostics::fmt($t['ms'])); ?>
                        </td>
                        <td><?php echo esc_html($t['result']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Waterfall from the most recent REAL registration (instrumented path).
        $marks    = get_option('gwarr_last_submit_marks', null);
        $deferred = get_option('gwarr_last_deferred_timing', null);
        if (is_array($marks) && !empty($marks['rows'])):
        ?>
            <h3 style="margin-bottom:4px;">Last real registration <span style="font-weight:400;color:#666;">(submitted <?php echo esc_html($marks['at'] ?? '?'); ?><?php echo isset($marks['ok']) ? ', ' . ($marks['ok'] ? 'success' : 'error/duplicate') : ''; ?>)</span></h3>
            <table class="widefat striped" style="max-width:760px;margin-bottom:8px;">
                <thead><tr><th>Step</th><th style="width:120px;">This step</th><th style="width:140px;">Cumulative</th></tr></thead>
                <tbody>
                    <?php foreach ($marks['rows'] as $r): ?>
                        <?php $slow = ($r['since_prev_ms'] ?? 0) >= 3000; ?>
                        <tr>
                            <td><?php echo esc_html($r['label']); ?></td>
                            <td style="<?php echo $slow ? 'color:#d63638;font-weight:700;' : ''; ?>"><?php echo esc_html(GWARR_Diagnostics::fmt((float) $r['since_prev_ms'])); ?></td>
                            <td><?php echo esc_html(GWARR_Diagnostics::fmt((float) $r['since_start_ms'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (isset($marks['total_php_ms'])): ?>
                        <tr>
                            <td><strong>Total PHP request time</strong></td>
                            <td colspan="2" style="<?php echo $marks['total_php_ms'] >= 5000 ? 'color:#d63638;font-weight:700;' : 'font-weight:700;'; ?>"><?php echo esc_html(GWARR_Diagnostics::fmt((float) $marks['total_php_ms'])); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if (is_array($deferred)): ?>
                <p class="description" style="max-width:760px;">
                    <strong>Deferred work (after response flushed):</strong>
                    fired <?php echo esc_html($deferred['at'] ?? '?'); ?> ·
                    <?php echo (int) ($deferred['task_count'] ?? 0); ?> task(s) ·
                    flush happened at <?php echo esc_html(GWARR_Diagnostics::fmt((float) ($deferred['before_flush_ms'] ?? 0))); ?> into the request ·
                    deferred work took <?php echo esc_html(GWARR_Diagnostics::fmt((float) ($deferred['deferred_ms'] ?? 0))); ?> ·
                    fastcgi_finish_request: <?php echo esc_html($deferred['fcr'] ?? '?'); ?>.
                </p>
            <?php endif; ?>
            <p class="description" style="max-width:760px;color:#666;">
                If "Total PHP request time" is small (a second or two) but the customer still waited a minute or more, the slowness is in the browser/network layer or the page they were redirected to — not this plugin's processing.
            </p>
        <?php else: ?>
            <p class="description">No real registration recorded yet. Submit a test warranty, then re-run diagnostics to see the per-step waterfall of the actual submission.</p>
        <?php endif; ?>

        <?php if (!empty($diag['notes'])): ?>
            <h3 style="margin-bottom:4px;">Notes</h3>
            <ul style="margin:0 0 4px 18px;list-style:disc;">
                <?php foreach ($diag['notes'] as $note): ?>
                    <li style="margin-bottom:6px;"><?php echo esc_html($note); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}
