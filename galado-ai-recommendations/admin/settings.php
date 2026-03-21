<?php
if (!defined('ABSPATH')) exit;

function gair_settings_page() {
    if (!current_user_can('manage_woocommerce')) return;

    // Handle save
    if (isset($_POST['gair_save_nonce']) && wp_verify_nonce($_POST['gair_save_nonce'], 'gair_save_settings')) {
        $settings = [
            'enabled'        => isset($_POST['gair_enabled']) ? 1 : 0,
            'provider'       => sanitize_text_field($_POST['gair_provider'] ?? 'anthropic'),
            'anthropic_key'  => sanitize_text_field($_POST['gair_anthropic_key'] ?? ''),
            'anthropic_model'=> sanitize_text_field($_POST['gair_anthropic_model'] ?? 'claude-sonnet-4-20250514'),
            'openai_key'     => sanitize_text_field($_POST['gair_openai_key'] ?? ''),
            'openai_model'   => sanitize_text_field($_POST['gair_openai_model'] ?? 'gpt-4o-mini'),
            'max_products'   => absint($_POST['gair_max_products'] ?? 4),
            'cache_hours'    => absint($_POST['gair_cache_hours'] ?? 6),
            'show_homepage'  => isset($_POST['gair_show_homepage']) ? 1 : 0,
            'show_product'   => isset($_POST['gair_show_product']) ? 1 : 0,
            'show_cart'      => isset($_POST['gair_show_cart']) ? 1 : 0,
            'widget_title'   => sanitize_text_field($_POST['gair_widget_title'] ?? 'Recommended for You'),
        ];
        update_option('gair_settings', $settings);
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // Handle clear cache
    if (isset($_POST['gair_clear_cache']) && wp_verify_nonce($_POST['gair_clear_nonce'] ?? '', 'gair_clear_cache')) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'gair_profiles',
            ['recommendations_cache' => '', 'cache_expires' => null],
            ['1' => '1'],
            ['%s', '%s']
        );
        echo '<div class="notice notice-success"><p>Recommendation cache cleared.</p></div>';
    }

    // Handle test
    $test_result = '';
    if (isset($_POST['gair_test_api']) && wp_verify_nonce($_POST['gair_test_nonce'] ?? '', 'gair_test_api')) {
        $settings = get_option('gair_settings', []);
        $provider = $settings['provider'] ?? 'anthropic';

        if ($provider === 'anthropic') {
            $key = $settings['anthropic_key'] ?? '';
            $model = $settings['anthropic_model'] ?? 'claude-sonnet-4-20250514';
            if (empty($key)) {
                $test_result = '<div class="notice notice-error"><p>Anthropic API key is empty.</p></div>';
            } else {
                $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
                    'timeout' => 15,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-api-key' => $key,
                        'anthropic-version' => '2023-06-01',
                    ],
                    'body' => wp_json_encode([
                        'model' => $model,
                        'max_tokens' => 50,
                        'messages' => [['role' => 'user', 'content' => 'Reply with: API connection successful']],
                    ]),
                ]);
                if (is_wp_error($response)) {
                    $test_result = '<div class="notice notice-error"><p>Connection failed: ' . esc_html($response->get_error_message()) . '</p></div>';
                } else {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['content'])) {
                        $test_result = '<div class="notice notice-success"><p>✅ Claude API connected successfully! Model: ' . esc_html($model) . '</p></div>';
                    } else {
                        $test_result = '<div class="notice notice-error"><p>API error: ' . esc_html($body['error']['message'] ?? 'Unknown error') . '</p></div>';
                    }
                }
            }
        } else {
            $key = $settings['openai_key'] ?? '';
            $model = $settings['openai_model'] ?? 'gpt-4o-mini';
            if (empty($key)) {
                $test_result = '<div class="notice notice-error"><p>OpenAI API key is empty.</p></div>';
            } else {
                $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                    'timeout' => 15,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $key,
                    ],
                    'body' => wp_json_encode([
                        'model' => $model,
                        'max_tokens' => 50,
                        'messages' => [['role' => 'user', 'content' => 'Reply with: API connection successful']],
                    ]),
                ]);
                if (is_wp_error($response)) {
                    $test_result = '<div class="notice notice-error"><p>Connection failed: ' . esc_html($response->get_error_message()) . '</p></div>';
                } else {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['choices'])) {
                        $test_result = '<div class="notice notice-success"><p>✅ OpenAI API connected successfully! Model: ' . esc_html($model) . '</p></div>';
                    } else {
                        $test_result = '<div class="notice notice-error"><p>API error: ' . esc_html($body['error']['message'] ?? 'Unknown error') . '</p></div>';
                    }
                }
            }
        }
    }

    $s = get_option('gair_settings', []);

    // Stats
    global $wpdb;
    $event_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gair_events");
    $profile_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gair_profiles");
    ?>
    <div class="wrap">
        <h1>AI Recommendations</h1>
        <p style="font-size:14px;color:#646970;">AI-powered personalised product recommendations using Claude or GPT.</p>

        <?php echo $test_result; ?>

        <!-- Stats -->
        <div style="display:flex;gap:12px;margin-bottom:24px;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 24px;text-align:center;flex:1;">
                <div style="font-size:28px;font-weight:700;"><?php echo number_format($event_count ?? 0); ?></div>
                <div style="font-size:13px;color:#666;">Events Tracked</div>
            </div>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 24px;text-align:center;flex:1;">
                <div style="font-size:28px;font-weight:700;"><?php echo number_format($profile_count ?? 0); ?></div>
                <div style="font-size:13px;color:#666;">Customer Profiles</div>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field('gair_save_settings', 'gair_save_nonce'); ?>

            <h2 class="title">General</h2>
            <table class="form-table">
                <tr>
                    <th>Enable AI Recommendations</th>
                    <td><label><input type="checkbox" name="gair_enabled" value="1" <?php checked($s['enabled'] ?? 0, 1); ?>> Active</label></td>
                </tr>
                <tr>
                    <th>Widget Title</th>
                    <td><input type="text" name="gair_widget_title" value="<?php echo esc_attr($s['widget_title'] ?? 'Recommended for You'); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Max Products</th>
                    <td>
                        <select name="gair_max_products">
                            <?php for ($i = 2; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php selected($s['max_products'] ?? 4, $i); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Cache Duration</th>
                    <td>
                        <select name="gair_cache_hours">
                            <option value="1" <?php selected($s['cache_hours'] ?? 6, 1); ?>>1 hour</option>
                            <option value="3" <?php selected($s['cache_hours'] ?? 6, 3); ?>>3 hours</option>
                            <option value="6" <?php selected($s['cache_hours'] ?? 6, 6); ?>>6 hours</option>
                            <option value="12" <?php selected($s['cache_hours'] ?? 6, 12); ?>>12 hours</option>
                            <option value="24" <?php selected($s['cache_hours'] ?? 6, 24); ?>>24 hours</option>
                        </select>
                        <p class="description">How long to cache recommendations per visitor. Longer = fewer API calls.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Display Locations</h2>
            <table class="form-table">
                <tr>
                    <th>Show On</th>
                    <td>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="gair_show_homepage" value="1" <?php checked($s['show_homepage'] ?? 0, 1); ?>> Homepage / Shop page</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="gair_show_product" value="1" <?php checked($s['show_product'] ?? 0, 1); ?>> Product pages</label>
                        <label style="display:block;"><input type="checkbox" name="gair_show_cart" value="1" <?php checked($s['show_cart'] ?? 0, 1); ?>> Cart page</label>
                        <p class="description" style="margin-top:8px;">You can also use the shortcode <code>[galado_recommendations]</code> anywhere.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">AI Provider</h2>
            <table class="form-table">
                <tr>
                    <th>Provider</th>
                    <td>
                        <select name="gair_provider" id="gair-provider-select">
                            <option value="anthropic" <?php selected($s['provider'] ?? 'anthropic', 'anthropic'); ?>>Anthropic (Claude)</option>
                            <option value="openai" <?php selected($s['provider'] ?? 'anthropic', 'openai'); ?>>OpenAI (GPT)</option>
                        </select>
                    </td>
                </tr>
            </table>

            <div id="gair-anthropic-settings" style="<?php echo ($s['provider'] ?? 'anthropic') !== 'anthropic' ? 'display:none' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th>Anthropic API Key</th>
                        <td>
                            <input type="password" name="gair_anthropic_key" value="<?php echo esc_attr($s['anthropic_key'] ?? ''); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Get your key from <a href="https://console.anthropic.com/settings/keys" target="_blank">console.anthropic.com</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Model</th>
                        <td>
                            <select name="gair_anthropic_model">
                                <option value="claude-sonnet-4-20250514" <?php selected($s['anthropic_model'] ?? '', 'claude-sonnet-4-20250514'); ?>>Claude Sonnet 4 (recommended)</option>
                                <option value="claude-haiku-4-20250514" <?php selected($s['anthropic_model'] ?? '', 'claude-haiku-4-20250514'); ?>>Claude Haiku 4 (faster, cheaper)</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <div id="gair-openai-settings" style="<?php echo ($s['provider'] ?? 'anthropic') !== 'openai' ? 'display:none' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th>OpenAI API Key</th>
                        <td>
                            <input type="password" name="gair_openai_key" value="<?php echo esc_attr($s['openai_key'] ?? ''); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Get your key from <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Model</th>
                        <td>
                            <select name="gair_openai_model">
                                <option value="gpt-4o-mini" <?php selected($s['openai_model'] ?? '', 'gpt-4o-mini'); ?>>GPT-4o Mini (recommended, cheapest)</option>
                                <option value="gpt-4o" <?php selected($s['openai_model'] ?? '', 'gpt-4o'); ?>>GPT-4o (best quality)</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button('Save Settings'); ?>
        </form>

        <!-- Test & Clear -->
        <div style="display:flex;gap:12px;margin-top:20px;">
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('gair_test_api', 'gair_test_nonce'); ?>
                <button type="submit" name="gair_test_api" value="1" class="button">🧪 Test API Connection</button>
            </form>
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('gair_clear_cache', 'gair_clear_nonce'); ?>
                <button type="submit" name="gair_clear_cache" value="1" class="button" onclick="return confirm('Clear all cached recommendations?')">🗑️ Clear Cache</button>
            </form>
        </div>
    </div>

    <script>
    document.getElementById('gair-provider-select').addEventListener('change', function() {
        document.getElementById('gair-anthropic-settings').style.display = this.value === 'anthropic' ? '' : 'none';
        document.getElementById('gair-openai-settings').style.display = this.value === 'openai' ? '' : 'none';
    });
    </script>
    <?php
}
