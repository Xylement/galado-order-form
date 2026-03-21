<?php
/**
 * Plugin Name: GALADO FAQ Schema Generator
 * Plugin URI: https://galado.com.my
 * Description: Automatically generates FAQPage JSON-LD structured data from page content. Detects FAQ patterns in accordions, toggles, and heading/paragraph pairs.
 * Version: 1.0.0
 * Author: GALADO
 * Author URI: https://galado.com.my
 * License: GPL v2 or later
 * Text Domain: galado-faq-schema
 */

if (!defined('ABSPATH')) exit;

define('GFAQ_VERSION', '1.0.0');
define('GFAQ_PATH', plugin_dir_path(__FILE__));
define('GFAQ_URL', plugin_dir_url(__FILE__));

// Load components
require_once GFAQ_PATH . 'includes/schema-generator.php';
require_once GFAQ_PATH . 'admin/settings.php';
require_once GFAQ_PATH . 'admin/meta-box.php';

// Enqueue admin styles
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'galado-faq-schema') !== false || in_array($hook, ['post.php', 'post-new.php'])) {
        wp_enqueue_style('gfaq-admin', GFAQ_URL . 'admin/style.css', [], GFAQ_VERSION);
    }
});

// Output schema in wp_head
add_action('wp_head', 'gfaq_output_schema');

// Register settings
add_action('admin_init', 'gfaq_register_settings');

// Add settings page — under GALADO hub if available, otherwise under Settings
add_action('admin_menu', function() {
    $parent = class_exists('Galado_Admin_Hub') ? 'galado-hub' : 'options-general.php';
    add_submenu_page(
        $parent,
        __('FAQ Schema Generator', 'galado-faq-schema'),
        __('FAQ Schema', 'galado-faq-schema'),
        'manage_options',
        'galado-faq-schema',
        'gfaq_settings_page'
    );
}, 20);

// Add meta box
add_action('add_meta_boxes', 'gfaq_add_meta_box');
add_action('save_post', 'gfaq_save_meta_box');

// Activation defaults
register_activation_hook(__FILE__, function() {
    $defaults = [
        'auto_detect' => 1,
        'post_types'  => ['page', 'post', 'product'],
    ];
    if (!get_option('gfaq_settings')) {
        update_option('gfaq_settings', $defaults);
    }
});
