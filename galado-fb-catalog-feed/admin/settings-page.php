<?php
/**
 * Admin settings page for the Facebook Catalog Feed.
 */

if (!defined('ABSPATH')) exit;

function gfbf_settings_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    $settings = get_option('gfbf_settings', []);

    // --- Save settings ---
    if (isset($_POST['gfbf_save_nonce']) && wp_verify_nonce($_POST['gfbf_save_nonce'], 'gfbf_save')) {
        $exclude = isset($_POST['gfbf_exclude_cats']) && is_array($_POST['gfbf_exclude_cats'])
            ? array_map('intval', $_POST['gfbf_exclude_cats'])
            : [];

        $frequency = in_array($_POST['gfbf_frequency'] ?? 'daily', ['daily', 'twicedaily', 'manual'], true)
            ? $_POST['gfbf_frequency']
            : 'daily';

        $settings = [
            'token'              => sanitize_text_field($_POST['gfbf_token'] ?? ($settings['token'] ?? '')),
            'include_variations' => isset($_POST['gfbf_include_variations']) ? 1 : 0,
            'exclude_cats'       => $exclude,
            'brand'              => sanitize_text_field($_POST['gfbf_brand'] ?? 'GALADO'),
            'frequency'          => $frequency,
        ];
        update_option('gfbf_settings', $settings);
        GFBF_Feed_Builder::ensure_schedule();
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // --- Build now ---
    if (isset($_POST['gfbf_build_nonce']) && wp_verify_nonce($_POST['gfbf_build_nonce'], 'gfbf_build')) {
        $state = GFBF_Feed_Builder::get_state();
        if ($state['status'] === 'running') {
            echo '<div class="notice notice-warning"><p>A build is already in progress.</p></div>';
        } else {
            GFBF_Feed_Builder::start();
            echo '<div class="notice notice-success"><p>Feed build started — it runs in the background. Refresh this page to watch progress.</p></div>';
        }
    }

    $settings    = get_option('gfbf_settings', []);
    $token       = $settings['token'] ?? '';
    $brand       = $settings['brand'] ?? 'GALADO';
    $frequency   = $settings['frequency'] ?? 'daily';
    $exclude     = isset($settings['exclude_cats']) && is_array($settings['exclude_cats'])
        ? $settings['exclude_cats']
        : [];

    $state = GFBF_Feed_Builder::get_state();

    $xml_url = add_query_arg(
        array_filter(['galado_fb_feed' => 1, 'format' => 'xml', 'token' => $token]),
        home_url('/')
    );
    $csv_url = add_query_arg(
        array_filter(['galado_fb_feed' => 1, 'format' => 'csv', 'token' => $token]),
        home_url('/')
    );

    $product_cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
    ]);

    // Auto-refresh the page while a build is running so progress updates.
    if ($state['status'] === 'running') {
        echo '<meta http-equiv="refresh" content="6">';
    }
    ?>
    <div class="wrap gfbf-wrap">
        <h1>Facebook Catalog Feed</h1>
        <p style="font-size:14px;color:#646970;max-width:780px;">
            The feed is built in the background in small batches and saved as a static file.
            Serving it is just a file read — it can't slow down or overload your site, even under heavy bot traffic.
        </p>

        <!-- Build status -->
        <div class="gfbf-card">
            <h2>Build Status</h2>
            <?php
            $badge_class = 'gfbf-badge';
            $badge_text  = ucfirst($state['status']);
            if ($state['status'] === 'done')    { $badge_class .= ' gfbf-badge-ok'; }
            if ($state['status'] === 'running') { $badge_class .= ' gfbf-badge-run'; }
            if ($state['status'] === 'error')   { $badge_class .= ' gfbf-badge-err'; }
            if ($state['status'] === 'idle')    { $badge_class .= ' gfbf-badge-idle'; }
            ?>
            <p>
                <span class="<?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
                <?php if ($state['status'] === 'running'): ?>
                    Processed <?php echo intval($state['offset']); ?> products,
                    <?php echo intval($state['rows']); ?> feed rows so far…
                    <em>(page auto-refreshes)</em>
                <?php elseif ($state['status'] === 'done'): ?>
                    <?php echo intval($state['rows']); ?> items —
                    finished <?php echo esc_html($state['finished_at']); ?> (site time)
                <?php elseif ($state['status'] === 'error'): ?>
                    <strong>Error:</strong> <?php echo esc_html($state['message']); ?>
                <?php else: ?>
                    No feed built yet.
                <?php endif; ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('gfbf_build', 'gfbf_build_nonce'); ?>
                <button type="submit" class="button button-primary" <?php disabled($state['status'], 'running'); ?>>
                    <?php echo $state['status'] === 'done' ? 'Rebuild Feed Now' : 'Build Feed Now'; ?>
                </button>
                <span class="description" style="margin-left:8px;">
                    Runs in the background via WP-Cron. A large catalog may take a few minutes.
                </span>
            </form>
        </div>

        <!-- Feed URLs -->
        <div class="gfbf-card">
            <h2>Your Feed URLs</h2>
            <?php if ($state['status'] !== 'done'): ?>
                <p class="description">These become live once the first build finishes.</p>
            <?php endif; ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">XML feed <span class="gfbf-pill">recommended</span></th>
                    <td>
                        <code class="gfbf-url"><?php echo esc_html($xml_url); ?></code>
                        <a href="<?php echo esc_url($xml_url); ?>" target="_blank" class="button button-small">Open</a>
                    </td>
                </tr>
                <tr>
                    <th scope="row">CSV feed</th>
                    <td>
                        <code class="gfbf-url"><?php echo esc_html($csv_url); ?></code>
                        <a href="<?php echo esc_url($csv_url); ?>" target="_blank" class="button button-small">Open</a>
                    </td>
                </tr>
            </table>
        </div>

        <!-- How to connect -->
        <div class="gfbf-card">
            <h2>Connect it to Facebook</h2>
            <p><strong>Option A — Scheduled feed (set &amp; forget):</strong></p>
            <ol>
                <li>Open <a href="https://business.facebook.com/commerce" target="_blank">Commerce Manager</a> → your <strong>Catalog</strong> → <strong>Data Sources</strong>.</li>
                <li>Choose <strong>Add Items → Data Feed → Scheduled feed</strong>.</li>
                <li>Paste the <strong>XML feed URL</strong> above. Set frequency to <strong>Daily</strong>.</li>
                <li>Currency: <strong><?php echo esc_html(get_woocommerce_currency()); ?></strong>. Finish.</li>
            </ol>
            <p><strong>Option B — One-off manual upload:</strong> open the CSV feed, save the file, then in Commerce Manager → Data Sources → <strong>Add Items → Upload</strong>.</p>
        </div>

        <!-- Settings -->
        <form method="post">
            <?php wp_nonce_field('gfbf_save', 'gfbf_save_nonce'); ?>
            <div class="gfbf-card">
                <h2>Feed Settings</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Brand</th>
                        <td>
                            <input type="text" name="gfbf_brand" value="<?php echo esc_attr($brand); ?>" class="regular-text">
                            <p class="description">Sent as the <code>brand</code> field on every item.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Rebuild frequency</th>
                        <td>
                            <select name="gfbf_frequency">
                                <option value="daily" <?php selected($frequency, 'daily'); ?>>Daily (recommended)</option>
                                <option value="twicedaily" <?php selected($frequency, 'twicedaily'); ?>>Twice daily</option>
                                <option value="manual" <?php selected($frequency, 'manual'); ?>>Manual only</option>
                            </select>
                            <p class="description">How often the background rebuild runs. Facebook pulls daily, so Daily is plenty.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Variable products</th>
                        <td>
                            <label>
                                <input type="checkbox" name="gfbf_include_variations" value="1" <?php checked(!empty($settings['include_variations'])); ?>>
                                Export each variation as its own item (shared <code>item_group_id</code>)
                            </label>
                            <p class="description">Recommended — lets Facebook show the right colour/model. Uncheck to send one row per parent product.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Feed token</th>
                        <td>
                            <input type="text" name="gfbf_token" value="<?php echo esc_attr($token); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Required in the feed URL as <code>&amp;token=</code>, and used in the feed filename so it isn't easily discoverable. Changing it requires a rebuild.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Exclude categories</th>
                        <td>
                            <?php if (!is_wp_error($product_cats) && !empty($product_cats)): ?>
                                <div class="gfbf-cat-grid">
                                    <?php foreach ($product_cats as $cat): ?>
                                        <label>
                                            <input type="checkbox" name="gfbf_exclude_cats[]" value="<?php echo esc_attr($cat->term_id); ?>" <?php checked(in_array($cat->term_id, $exclude, true)); ?>>
                                            <?php echo esc_html($cat->name); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description">Tick categories to keep out of the feed — e.g. custom/personalised lines without a fixed price or image.</p>
                            <?php else: ?>
                                <p class="description">No product categories found.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
                <p class="description">Saving settings does not rebuild the feed — click <strong>Rebuild Feed Now</strong> above to apply changes.</p>
            </div>
        </form>
    </div>
    <?php
}
