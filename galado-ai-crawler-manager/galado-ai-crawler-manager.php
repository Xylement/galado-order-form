<?php
/**
 * Plugin Name: GALADO AI Crawler Manager
 * Plugin URI: https://galado.com.my
 * Description: Control which AI search engines and crawlers can access your website. Manage GPTBot, ClaudeBot, PerplexityBot, Google-Extended, and more from a simple dashboard.
 * Version: 1.0.0
 * Author: GALADO
 * Author URI: https://galado.com.my
 * License: GPL v2 or later
 * Text Domain: galado-ai-crawler
 */

if (!defined('ABSPATH')) exit;

define('GAIC_VERSION', '1.0.0');
define('GAIC_PATH', plugin_dir_path(__FILE__));
define('GAIC_URL', plugin_dir_url(__FILE__));

// Load components
require_once GAIC_PATH . 'includes/robots-handler.php';
require_once GAIC_PATH . 'admin/settings-page.php';

/**
 * Get crawler definitions
 */
function gaic_get_crawlers() {
    return [
        'GPTBot' => [
            'owner' => 'OpenAI',
            'desc'  => 'Powers ChatGPT search results',
            'color' => '#10A37F',
            'recommended' => true,
        ],
        'OAI-SearchBot' => [
            'owner' => 'OpenAI',
            'desc'  => 'SearchGPT results',
            'color' => '#10A37F',
            'recommended' => true,
        ],
        'ChatGPT-User' => [
            'owner' => 'OpenAI',
            'desc'  => 'ChatGPT browsing mode',
            'color' => '#10A37F',
            'recommended' => true,
        ],
        'ClaudeBot' => [
            'owner' => 'Anthropic',
            'desc'  => 'Claude AI search & answers',
            'color' => '#D4A574',
            'recommended' => true,
        ],
        'anthropic-ai' => [
            'owner' => 'Anthropic',
            'desc'  => 'Anthropic general crawler',
            'color' => '#D4A574',
            'recommended' => true,
        ],
        'PerplexityBot' => [
            'owner' => 'Perplexity',
            'desc'  => 'Perplexity AI search engine',
            'color' => '#20808D',
            'recommended' => true,
        ],
        'Google-Extended' => [
            'owner' => 'Google',
            'desc'  => 'Gemini AI training & features',
            'color' => '#4285F4',
            'recommended' => true,
        ],
        'Applebot-Extended' => [
            'owner' => 'Apple',
            'desc'  => 'Apple Intelligence features',
            'color' => '#333333',
            'recommended' => true,
        ],
        'Meta-ExternalFetcher' => [
            'owner' => 'Meta',
            'desc'  => 'Meta AI training data',
            'color' => '#0866FF',
            'recommended' => false,
        ],
        'Meta-ExternalAgent' => [
            'owner' => 'Meta',
            'desc'  => 'Meta AI features',
            'color' => '#0866FF',
            'recommended' => false,
        ],
        'CCBot' => [
            'owner' => 'Common Crawl',
            'desc'  => 'Open dataset used by many AI',
            'color' => '#E74C3C',
            'recommended' => false,
        ],
        'Bytespider' => [
            'owner' => 'ByteDance',
            'desc'  => 'TikTok / Doubao AI training',
            'color' => '#00F5D4',
            'recommended' => false,
        ],
        'cohere-ai' => [
            'owner' => 'Cohere',
            'desc'  => 'Cohere AI model training',
            'color' => '#7C3AED',
            'recommended' => false,
        ],
    ];
}

// Enqueue admin styles
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'galado-ai-crawler') === false) return;
    wp_enqueue_style('gaic-admin', GAIC_URL . 'admin/style.css', [], GAIC_VERSION);
});

// Add settings page
add_action('admin_menu', function() {
    add_options_page(
        __('AI Crawler Manager', 'galado-ai-crawler'),
        __('AI Crawlers', 'galado-ai-crawler'),
        'manage_options',
        'galado-ai-crawler',
        'gaic_settings_page'
    );
});

// Hook into robots.txt
add_filter('robots_txt', 'gaic_modify_robots_txt', 100, 2);

// Activation: set recommended defaults
register_activation_hook(__FILE__, function() {
    if (get_option('gaic_crawlers') !== false) return;
    $crawlers = gaic_get_crawlers();
    $defaults = [];
    foreach ($crawlers as $bot => $info) {
        $defaults[$bot] = $info['recommended'] ? 'allow' : 'disallow';
    }
    update_option('gaic_crawlers', $defaults);
});
