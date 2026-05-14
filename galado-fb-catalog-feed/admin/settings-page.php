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

    // Save settings
    if (isset($_POST['gfbf_save_nonce']) && wp_verify_nonce($_POST['gfbf_save_nonce'], 'gfbf_save')) {
        $exclude = isset($_POST['gfbf_exclude_cats']) && is_array($_POST['gfbf_exclude_cats'])
            ? array_map('intval', $_POST['gfbf_exclude_cats'])
            : [];

        $format = in_array($_POST['gfbf_format'] ?? 'xml', ['xml', 'csv'], true)
            ? $_POST['gfbf_format']
            : 'xml';

        $settings = [
            'token'              => sanitize_text_field($_POST['gfbf_token'] ?? ($settings['token'] ?? '')),
            'format'             => $format,
            'include_variations' => isset($_POST['gfbf_include_variations']) ? 1 : 0,
            'exclude_cats'       => $exclude,
            'cache_hours'        => max(1, min(48, intval($_POST['gfbf_cache_hours'] ?? 6))),
            'brand'              => sanitize_text_field($_POST['gfbf_brand'] ?? 'GALADO'),
        ];
        update_option('gfbf_settings', $settings);
        gfbf_clear_cache();
        echo '<div class="notice notice-success"><p>Settings saved — feed cache cleared.</p></div>';
    }

    // Regenerate cache
    if (isset($_POST['gfbf_regen_nonce']) && wp_verify_nonce($_POST['gfbf_regen_nonce'], 'gfbf_regen')) {
        gfbf_clear_cache();
        echo '<div class="notice notice-success"><p>Feed cache cleared — it rebuilds on the next request.</p></div>';
    }

    $token       = $settings['token'] ?? '';
    $format      = $settings['format'] ?? 'xml';
    $cache_hours = intval($settings['cache_hours'] ?? 6);
    $brand       = $settings['brand'] ?? 'GALADO';
    $exclude     = isset($settings['exclude_cats']) && is_array($settings['exclude_cats'])
        ? $settings['exclude_cats']
        : [];

    $xml_url = add_query_arg(
        array_filter(['galado_fb_feed' => 1, 'format' => 'xml', 'token' => $token]),
        home_url('/')
    );
    $csv_url = add_query_arg(
        array_filter(['galado_fb_feed' => 1, 'format' => 'csv', 'token' => $token]),
        home_url('/')
    );

    $last_generated = get_option('gfbf_last_generated', '');
    $primary_url    = $format === 'csv' ? $csv_url : $xml_url;

    $product_cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
    ]);
    ?>
    <div class="wrap gfbf-wrap">
        <h1>Facebook Catalog Feed</h1>
        <p style="font-size:14px;color:#646970;max-width:760px;">
            Generates a Meta-spec product feed straight from your live WooCommerce catalog —
            no Graph API, no access tokens, nothing to break on a plugin update.
        </p>

        <!-- Feed URLs -->
        <div class="gfbf-card">
            <h2>Your Feed URLs</h2>
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
                <?php if ($last_generated): ?>
                <tr>
                    <th scope="row">Last built</th>
                    <td><?php echo esc_html($last_generated); ?> (site time)</td>
                </tr>
                <?php endif; ?>
            </table>
            <form method="post" style="margin-top:8px;">
                <?php wp_nonce_field('gfbf_regen', 'gfbf_regen_nonce'); ?>
                <button type="submit" class="button">Rebuild feed now</button>
                <span class="description" style="margin-left:8px;">Or add <code>&amp;refresh=1</code> to a feed URL while logged in.</span>
            </form>
        </div>

        <!-- How to connect -->
        <div class="gfbf-card">
            <h2>Connect it to Facebook</h2>
            <p><strong>Option A — Scheduled feed (set &amp; forget):</strong></p>
            <ol>
                <li>Open <a href="https://business.facebook.com/commerce" target="_blank">Commerce Manager</a> → your <strong>Catalog</strong> → <strong>Data Sources</strong>.</li>
                <li>Choose <strong>Add Items → Data Feed → Scheduled feed</strong>.</li>
                <li>Paste the <strong>XML feed URL</strong> above. Set frequency to <strong>Daily</strong>.</li>
                <li>Currency: <strong><?php echo esc_html(get_woocommerce_currency()); ?></strong>. Finish — Facebook pulls automatically from now on.</li>
            </ol>
            <p><strong>Option B — One-off manual upload:</strong></p>
            <ol>
                <li>Click <strong>Open</strong> on the CSV feed above and save the file.</li>
                <li>In Commerce Manager → Data Sources → <strong>Add Items → Upload → Manual upload</strong>.</li>
                <li>Upload the saved file. Re-upload whenever you want to refresh.</li>
            </ol>
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
                        <th scope="row">Default format</th>
                        <td>
                            <select name="gfbf_format">
                                <option value="xml" <?php selected($format, 'xml'); ?>>XML (RSS 2.0) — recommended</option>
                                <option value="csv" <?php selected($format, 'csv'); ?>>CSV</option>
                            </select>
                            <p class="description">Used when a request omits <code>&amp;format=</code>. Both URLs always work regardless.</p>
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
                        <th scope="row">Cache duration</th>
                        <td>
                            <input type="number" name="gfbf_cache_hours" value="<?php echo esc_attr($cache_hours); ?>" min="1" max="48" style="width:80px;"> hours
                            <p class="description">How long a built feed is cached before rebuilding. Facebook pulls daily, so 6–12h is plenty.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Feed token</th>
                        <td>
                            <input type="text" name="gfbf_token" value="<?php echo esc_attr($token); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Required in the feed URL as <code>&amp;token=</code>. Leave blank to make the feed public (not recommended).</p>
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
                                <p class="description">Tick categories to keep out of the feed — e.g. custom/personalised lines that don't have a fixed price or image.</p>
                            <?php else: ?>
                                <p class="description">No product categories found.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </div>
        </form>
    </div>
    <?php
}
