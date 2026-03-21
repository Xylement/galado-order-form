<?php
if (!defined('ABSPATH')) exit;

/**
 * Register plugin settings
 */
function gfaq_register_settings() {
    register_setting('gfaq_settings_group', 'gfaq_settings', [
        'sanitize_callback' => 'gfaq_sanitize_settings',
    ]);

    add_settings_section('gfaq_main', '', '__return_false', 'galado-faq-schema');

    add_settings_field('gfaq_auto_detect', __('Auto-Detection', 'galado-faq-schema'), function() {
        $settings = get_option('gfaq_settings', []);
        $checked = isset($settings['auto_detect']) ? $settings['auto_detect'] : 1;
        echo '<label>';
        echo '<input type="checkbox" name="gfaq_settings[auto_detect]" value="1" ' . checked($checked, 1, false) . '>';
        echo ' ' . esc_html__('Automatically detect FAQ patterns in page content', 'galado-faq-schema');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Detects accordions, toggles, details/summary elements, and heading + paragraph FAQ patterns.', 'galado-faq-schema') . '</p>';
    }, 'galado-faq-schema', 'gfaq_main');

    add_settings_field('gfaq_post_types', __('Enabled Post Types', 'galado-faq-schema'), function() {
        $settings = get_option('gfaq_settings', []);
        $enabled = isset($settings['post_types']) ? $settings['post_types'] : ['page', 'post', 'product'];
        $types = [
            'page'    => __('Pages', 'galado-faq-schema'),
            'post'    => __('Posts', 'galado-faq-schema'),
            'product' => __('Products (WooCommerce)', 'galado-faq-schema'),
        ];
        foreach ($types as $key => $label) {
            echo '<label style="display:block;margin-bottom:6px;">';
            echo '<input type="checkbox" name="gfaq_settings[post_types][]" value="' . esc_attr($key) . '" ' . checked(in_array($key, $enabled), true, false) . '>';
            echo ' ' . esc_html($label);
            echo '</label>';
        }
    }, 'galado-faq-schema', 'gfaq_main');
}

/**
 * Sanitize settings
 */
function gfaq_sanitize_settings($input) {
    $output = [];
    $output['auto_detect'] = isset($input['auto_detect']) ? 1 : 0;
    $output['post_types'] = isset($input['post_types']) && is_array($input['post_types'])
        ? array_map('sanitize_text_field', $input['post_types'])
        : [];
    return $output;
}

/**
 * Render settings page
 */
function gfaq_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap gfaq-wrap">
        <h1><?php esc_html_e('FAQ Schema Generator', 'galado-faq-schema'); ?></h1>
        <p class="gfaq-subtitle"><?php esc_html_e('Automatically generate FAQPage JSON-LD structured data for Google rich results.', 'galado-faq-schema'); ?></p>

        <div class="gfaq-info-box">
            <strong><?php esc_html_e('How it works:', 'galado-faq-schema'); ?></strong>
            <ol>
                <li><?php esc_html_e('The plugin scans your page content for FAQ patterns (accordions, toggles, Q&A blocks).', 'galado-faq-schema'); ?></li>
                <li><?php esc_html_e('You can also manually add FAQs via the meta box on each page/post editor.', 'galado-faq-schema'); ?></li>
                <li><?php esc_html_e('Valid FAQPage JSON-LD is automatically injected into the page head.', 'galado-faq-schema'); ?></li>
                <li><?php esc_html_e('Google picks up the schema and may show FAQ rich results in search.', 'galado-faq-schema'); ?></li>
            </ol>
        </div>

        <form method="post" action="options.php">
            <?php
            settings_fields('gfaq_settings_group');
            do_settings_sections('galado-faq-schema');
            submit_button(__('Save Settings', 'galado-faq-schema'));
            ?>
        </form>

        <div class="gfaq-info-box" style="margin-top:20px;">
            <strong><?php esc_html_e('Test your schema:', 'galado-faq-schema'); ?></strong>
            <p><?php echo wp_kses(
                __('After saving, visit any page with FAQs and use <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Google Rich Results Test</a> to validate your schema.', 'galado-faq-schema'),
                ['a' => ['href' => [], 'target' => [], 'rel' => []]]
            ); ?></p>
        </div>
    </div>
    <?php
}
