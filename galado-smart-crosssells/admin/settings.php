<?php
/**
 * Admin settings for Smart Cross-Sells
 */

if (!defined('ABSPATH')) exit;

// Add settings page — under GALADO hub if available, otherwise under WooCommerce
add_action('admin_menu', function() {
    $parent = class_exists('Galado_Admin_Hub') ? 'galado-hub' : 'woocommerce';
    add_submenu_page(
        $parent,
        'Smart Cross-Sells',
        'Smart Cross-Sells',
        'manage_woocommerce',
        'galado-crosssells',
        'galado_cs_settings_page'
    );
});

// Register settings
add_action('admin_init', function() {
    $settings = [
        'galado_cs_enable_cart' => 'yes',
        'galado_cs_enable_checkout' => 'yes',
        'galado_cs_enable_thankyou' => 'yes',
        'galado_cs_cart_title' => '',
        'galado_cs_checkout_title' => '',
        'galado_cs_thankyou_title' => '',
        'galado_cs_max_products' => 4,
        'galado_cs_smart_matching' => 'yes',
        'galado_cs_ranking_mode' => 'hybrid',
    ];

    foreach ($settings as $key => $default) {
        register_setting('galado_cs_settings', $key, [
            'sanitize_callback' => $key === 'galado_cs_ranking_mode'
                ? 'galado_cs_sanitize_ranking_mode'
                : null,
        ]);
    }
});

function galado_cs_sanitize_ranking_mode($value) {
    $allowed = ['hybrid', 'trending', 'newest', 'lifetime'];
    $clean = in_array($value, $allowed, true) ? $value : 'hybrid';
    // Clear the trending cache so the new mode takes effect immediately.
    if (class_exists('Galado_Crosssell_Engine')) {
        Galado_Crosssell_Engine::clear_trending_cache();
    }
    return $clean;
}

function galado_cs_settings_page() {
    if (!current_user_can('manage_woocommerce')) return;
    ?>
    <div class="wrap">
        <h1>Smart Cross-Sells</h1>
        <p style="font-size:14px;color:#646970;">Boost your average order value by showing relevant product recommendations on cart, checkout, and thank you pages.</p>

        <form method="post" action="options.php">
            <?php settings_fields('galado_cs_settings'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Smart Matching</th>
                    <td>
                        <label>
                            <input type="checkbox" name="galado_cs_smart_matching" value="yes" <?php checked(get_option('galado_cs_smart_matching', 'yes'), 'yes'); ?>>
                            Auto-match complementary products by category
                        </label>
                        <p class="description">When enabled, the plugin automatically suggests accessories for cases, and cases for accessories. Disable to only show manually-set WooCommerce cross-sells.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Max Products</th>
                    <td>
                        <select name="galado_cs_max_products">
                            <?php for ($i = 2; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php selected(get_option('galado_cs_max_products', 4), $i); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <p class="description">Maximum number of cross-sell products to show per location.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Ranking Mode</th>
                    <td>
                        <?php $cs_mode = get_option('galado_cs_ranking_mode', 'hybrid'); ?>
                        <select name="galado_cs_ranking_mode">
                            <option value="hybrid" <?php selected($cs_mode, 'hybrid'); ?>>Hybrid — trending → newest → lifetime (recommended)</option>
                            <option value="trending" <?php selected($cs_mode, 'trending'); ?>>Trending only — last 30 days top sellers</option>
                            <option value="newest" <?php selected($cs_mode, 'newest'); ?>>Newest only — recently published products</option>
                            <option value="lifetime" <?php selected($cs_mode, 'lifetime'); ?>>Lifetime bestsellers — original behaviour</option>
                        </select>
                        <p class="description">Controls how cross-sell candidates are ranked. Hybrid mixes recent sales with new arrivals so the same products don't dominate forever. Trending data is cached for 6 hours.</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">Display Locations</h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Cart Page</th>
                    <td>
                        <label>
                            <input type="checkbox" name="galado_cs_enable_cart" value="yes" <?php checked(get_option('galado_cs_enable_cart', 'yes'), 'yes'); ?>>
                            Show cross-sells on cart page
                        </label>
                        <br><br>
                        <label>
                            Section title:
                            <input type="text" name="galado_cs_cart_title" value="<?php echo esc_attr(get_option('galado_cs_cart_title', 'Complete Your Setup')); ?>" class="regular-text" placeholder="Complete Your Setup">
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Checkout Page</th>
                    <td>
                        <label>
                            <input type="checkbox" name="galado_cs_enable_checkout" value="yes" <?php checked(get_option('galado_cs_enable_checkout', 'yes'), 'yes'); ?>>
                            Show cross-sells on checkout page
                        </label>
                        <br><br>
                        <label>
                            Section title:
                            <input type="text" name="galado_cs_checkout_title" value="<?php echo esc_attr(get_option('galado_cs_checkout_title', 'Last Chance to Add')); ?>" class="regular-text" placeholder="Last Chance to Add">
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Thank You Page</th>
                    <td>
                        <label>
                            <input type="checkbox" name="galado_cs_enable_thankyou" value="yes" <?php checked(get_option('galado_cs_enable_thankyou', 'yes'), 'yes'); ?>>
                            Show cross-sells on order confirmation page
                        </label>
                        <br><br>
                        <label>
                            Section title:
                            <input type="text" name="galado_cs_thankyou_title" value="<?php echo esc_attr(get_option('galado_cs_thankyou_title', 'Customers Also Love')); ?>" class="regular-text" placeholder="Customers Also Love">
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="title">Category Mapping</h2>
            <p>The plugin automatically maps these category relationships for smart matching. To customise, set manual cross-sells on individual products via the WooCommerce product editor (Product Data → Linked Products → Cross-sells).</p>

            <table class="widefat striped" style="max-width:700px;">
                <thead>
                    <tr>
                        <th>If cart contains...</th>
                        <th>Suggest from...</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><strong>iPhone Cases</strong> (all models)</td><td>Screen Protector, Lens Protector, Phone Charm, Phone Strap, MagSafe Grip</td></tr>
                    <tr><td><strong>Samsung Cases</strong> (all models)</td><td>Screen Protector, Lens Protector, Phone Charm, Phone Strap, MagSafe Grip</td></tr>
                    <tr><td><strong>AirPods Cases</strong></td><td>Phone Charm, Phone Strap, MagSafe Grip</td></tr>
                    <tr><td><strong>Phone Charm</strong></td><td>Phone Strap, MagSafe Grip, Screen Protector</td></tr>
                    <tr><td><strong>Phone Strap</strong></td><td>Phone Charm, MagSafe Grip, Screen Protector</td></tr>
                    <tr><td><strong>MagSafe Grip</strong></td><td>Phone Charm, Phone Strap, Screen Protector</td></tr>
                    <tr><td><strong>Screen Protector</strong></td><td>Lens Protector, Phone Charm, Phone Strap, MagSafe Grip</td></tr>
                    <tr><td><strong>Lens Protector</strong></td><td>Screen Protector, Phone Charm, Phone Strap, MagSafe Grip</td></tr>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px;"><strong>Key rule:</strong> Cases NEVER suggest other cases — only accessories. Customise via the <code>galado_cs_category_rules</code> filter.</p>

            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

// Handle unchecked checkboxes (they don't submit when unchecked)
add_action('pre_update_option_galado_cs_enable_cart', 'galado_cs_checkbox_handler', 10, 2);
add_action('pre_update_option_galado_cs_enable_checkout', 'galado_cs_checkbox_handler', 10, 2);
add_action('pre_update_option_galado_cs_enable_thankyou', 'galado_cs_checkbox_handler', 10, 2);
add_action('pre_update_option_galado_cs_smart_matching', 'galado_cs_checkbox_handler', 10, 2);

function galado_cs_checkbox_handler($new_value, $old_value) {
    return $new_value ?: 'no';
}

add_action('admin_init', function() {
    if (isset($_POST['option_page']) && $_POST['option_page'] === 'galado_cs_settings') {
        $checkboxes = ['galado_cs_enable_cart', 'galado_cs_enable_checkout', 'galado_cs_enable_thankyou', 'galado_cs_smart_matching'];
        foreach ($checkboxes as $cb) {
            if (!isset($_POST[$cb])) {
                update_option($cb, 'no');
            }
        }
    }
}, 5);
