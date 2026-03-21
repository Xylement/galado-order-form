<?php
/**
 * Plugin Name: GALADO Admin Hub
 * Plugin URI: https://galado.com.my
 * Description: Unified admin sidebar for all GALADO plugins. Groups FAQ Schema, AI Crawler Manager, Smart Cross-Sells, Font Preview, and Git Sync under one menu.
 * Version: 1.0.0
 * Author: GALADO
 * Author URI: https://galado.com.my
 * License: GPL v2 or later
 * Text Domain: galado-hub
 */

if (!defined('ABSPATH')) exit;

define('GALADO_HUB_VERSION', '1.0.0');
define('GALADO_HUB_PATH', plugin_dir_path(__FILE__));
define('GALADO_HUB_URL', plugin_dir_url(__FILE__));

class Galado_Admin_Hub {

    private static $instance = null;

    // Registry of all GALADO plugins
    private static $plugins = [];

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu'], 5);
        add_action('admin_menu', [$this, 'relocate_submenus'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);

        // Register known GALADO plugins
        $this->register_plugins();
    }

    /**
     * Register the main GALADO menu
     */
    public function register_menu() {
        add_menu_page(
            'GALADO',
            'GALADO',
            'manage_options',
            'galado-hub',
            [$this, 'render_dashboard'],
            self::get_icon_svg(),
            3 // Position near the top, after Dashboard
        );

        // Dashboard as first submenu
        add_submenu_page(
            'galado-hub',
            'GALADO Dashboard',
            'Dashboard',
            'manage_options',
            'galado-hub',
            [$this, 'render_dashboard']
        );
    }

    /**
     * Relocate other GALADO plugin menus under our hub
     */
    public function relocate_submenus() {
        global $submenu, $menu;

        // Map of plugin slugs to find and relocate
        $relocations = [
            // [original_parent, slug, new_title, capability, callback_or_slug]
            ['options-general.php', 'galado-faq-schema', 'FAQ Schema', 'manage_options'],
            ['options-general.php', 'galado-ai-crawler', 'AI Crawler Manager', 'manage_options'],
            ['woocommerce', 'galado-crosssells', 'Smart Cross-Sells', 'manage_woocommerce'],
            ['tools.php', 'flavor-git-sync', 'Git Sync', 'manage_options'],
        ];

        foreach ($relocations as $reloc) {
            list($parent, $slug, $title, $cap) = $reloc;

            // Check if the submenu exists under its original parent
            if (isset($submenu[$parent])) {
                foreach ($submenu[$parent] as $key => $item) {
                    if ($item[2] === $slug) {
                        // Add under GALADO menu
                        add_submenu_page(
                            'galado-hub',
                            $item[0],
                            $title,
                            $cap,
                            $slug
                        );
                        // Remove from original location
                        unset($submenu[$parent][$key]);
                        break;
                    }
                }
            }
        }

        // Check for Font Preview in WooCommerce product settings
        // (it's a product meta box, not a separate page — add a link to products)
        add_submenu_page(
            'galado-hub',
            'Font Preview',
            'Font Preview',
            'manage_woocommerce',
            'edit.php?post_type=product',
            ''
        );

        // Add external useful links
        add_submenu_page(
            'galado-hub',
            'Order Form',
            '↗ Order Form',
            'manage_options',
            'galado-order-form-link',
            [$this, 'redirect_order_form']
        );
    }

    /**
     * Auto-discover all GALADO plugins + manually registered ones
     */
    private function register_plugins() {
        // Auto-discover: scan all installed plugins for "GALADO" in Author or Name
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        self::$plugins = [];

        foreach ($all_plugins as $file => $data) {
            // Skip this hub plugin itself
            if (strpos($file, 'galado-admin-hub') !== false) continue;

            // Check if Author or Plugin Name contains "GALADO" (case-insensitive)
            $is_galado = (
                stripos($data['Author'] ?? '', 'GALADO') !== false ||
                stripos($data['Name'] ?? '', 'GALADO') !== false
            );

            if (!$is_galado) continue;

            // Try to find the settings page slug from known patterns
            $folder = dirname($file);
            $settings_url = admin_url('plugins.php'); // fallback

            // Check if plugin registered an admin page with a matching slug
            $possible_slugs = [
                'galado-' . str_replace(['galado-', '-'], ['', '-'], $folder),
                $folder,
                str_replace('galado-', '', $folder),
            ];

            // Search global menu/submenu for this plugin's admin page
            global $submenu, $menu;
            $found_slug = '';
            if (is_array($submenu)) {
                foreach ($submenu as $parent => $items) {
                    foreach ($items as $item) {
                        if (stripos($item[2], 'galado') !== false && stripos($item[2], 'hub') === false) {
                            // Check if this menu item relates to this plugin
                            $item_slug = $item[2];
                            if (stripos($item_slug, str_replace('galado-', '', $folder)) !== false) {
                                $found_slug = $item_slug;
                                $settings_url = admin_url('admin.php?page=' . $item_slug);
                                break 2;
                            }
                        }
                    }
                }
            }

            // Auto-detect category from description keywords
            $desc = strtolower($data['Description'] ?? '');
            $category = 'General';
            if (preg_match('/seo|schema|crawler|sitemap|robot/i', $desc)) $category = 'SEO';
            elseif (preg_match('/cross.?sell|upsell|aov|cart|checkout|sales/i', $desc)) $category = 'Sales';
            elseif (preg_match('/product|woocommerce|font|preview|custom/i', $desc)) $category = 'Products';
            elseif (preg_match('/sync|git|deploy|tool/i', $desc)) $category = 'Tools';
            elseif (preg_match('/ai|personali|recommend|intelligence/i', $desc)) $category = 'AI';

            self::$plugins[] = [
                'name' => str_replace('GALADO ', '', $data['Name']),
                'slug' => $found_slug ?: $folder,
                'description' => $data['Description'] ?? '',
                'settings_url' => $settings_url,
                'file' => $file,
                'icon' => self::get_auto_icon($category),
                'category' => $category,
                'version' => $data['Version'] ?? '',
            ];
        }

        // Also include Git Sync if installed (not authored by GALADO but we want it grouped)
        if (isset($all_plugins['flavor-git-sync/flavor-git-sync.php'])) {
            $gs = $all_plugins['flavor-git-sync/flavor-git-sync.php'];
            self::$plugins[] = [
                'name' => 'Git Sync',
                'slug' => 'flavor-git-sync',
                'description' => $gs['Description'] ?? 'Sync plugins from GitHub repositories.',
                'settings_url' => admin_url('tools.php?page=flavor-git-sync'),
                'file' => 'flavor-git-sync/flavor-git-sync.php',
                'icon' => '🔄',
                'category' => 'Tools',
                'version' => $gs['Version'] ?? '',
            ];
        }

        // Sort: active first, then by category
        $active_plugins = get_option('active_plugins', []);
        usort(self::$plugins, function($a, $b) use ($active_plugins) {
            $a_active = in_array($a['file'], $active_plugins) ? 0 : 1;
            $b_active = in_array($b['file'], $active_plugins) ? 0 : 1;
            if ($a_active !== $b_active) return $a_active - $b_active;
            return strcmp($a['category'], $b['category']);
        });

        // Allow manual additions via filter
        self::$plugins = apply_filters('galado_hub_plugins', self::$plugins);
    }

    /**
     * Auto-assign icon based on category
     */
    private static function get_auto_icon($category) {
        $icons = [
            'SEO' => '🔍',
            'Sales' => '🛒',
            'Products' => '📦',
            'Tools' => '🔧',
            'AI' => '🤖',
            'General' => '⚡',
        ];
        return $icons[$category] ?? '⚡';
    }

    // Known settings page slugs for URL resolution fallback
    private static $known_settings = [
        'galado-faq-schema' => 'galado-faq-schema',
        'galado-ai-crawler-manager' => 'galado-ai-crawler',
        'galado-smart-crosssells' => 'galado-crosssells',
        'galado-font-preview' => 'edit.php?post_type=product',
    ];

    /**
     * Render the dashboard page
     */
    public function render_dashboard() {
        $active_plugins = get_option('active_plugins', []);
        ?>
        <div class="wrap galado-hub-wrap">
            <div class="galado-hub-header">
                <h1>GALADO</h1>
                <p>Plugin Control Center</p>
            </div>

            <div class="galado-hub-stats">
                <?php
                $active_count = 0;
                foreach (self::$plugins as $plugin) {
                    if (in_array($plugin['file'], $active_plugins)) $active_count++;
                }
                ?>
                <div class="galado-hub-stat">
                    <span class="stat-number"><?php echo count(self::$plugins); ?></span>
                    <span class="stat-label">Total Plugins</span>
                </div>
                <div class="galado-hub-stat">
                    <span class="stat-number"><?php echo $active_count; ?></span>
                    <span class="stat-label">Active</span>
                </div>
                <div class="galado-hub-stat">
                    <span class="stat-number"><?php echo count(self::$plugins) - $active_count; ?></span>
                    <span class="stat-label">Inactive</span>
                </div>
            </div>

            <div class="galado-hub-grid">
                <?php foreach (self::$plugins as $plugin): ?>
                    <?php
                    $is_installed = file_exists(WP_PLUGIN_DIR . '/' . $plugin['file']);
                    $is_active = in_array($plugin['file'], $active_plugins);
                    $status_class = $is_active ? 'active' : ($is_installed ? 'inactive' : 'not-installed');
                    $status_text = $is_active ? 'Active' : ($is_installed ? 'Inactive' : 'Not Installed');
                    ?>
                    <div class="galado-hub-card galado-hub-card--<?php echo $status_class; ?>">
                        <div class="galado-hub-card__header">
                            <span class="galado-hub-card__icon"><?php echo $plugin['icon']; ?></span>
                            <span class="galado-hub-card__category"><?php echo esc_html($plugin['category']); ?></span>
                        </div>
                        <h3 class="galado-hub-card__name"><?php echo esc_html($plugin['name']); ?></h3>
                        <p class="galado-hub-card__desc"><?php echo esc_html($plugin['description']); ?></p>
                        <div class="galado-hub-card__footer">
                            <span class="galado-hub-card__status galado-hub-card__status--<?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </span>
                            <?php if ($is_active): ?>
                                <a href="<?php echo esc_url($plugin['settings_url']); ?>" class="button button-primary button-small">Settings</a>
                            <?php elseif ($is_installed): ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('plugins.php?action=activate&plugin=' . urlencode($plugin['file'])), 'activate-plugin_' . $plugin['file'])); ?>" class="button button-small">Activate</a>
                            <?php else: ?>
                                <span class="galado-hub-card__install-note">Upload via Plugins → Add New</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="galado-hub-links">
                <h2>Quick Links</h2>
                <div class="galado-hub-links-grid">
                    <a href="https://xylement.github.io/galado-order-form/" target="_blank" class="galado-hub-link">
                        <span class="link-icon">📝</span>
                        <span class="link-text">Walk-In Order Form</span>
                        <span class="link-arrow">↗</span>
                    </a>
                    <a href="https://github.com/Xylement/galado-order-form" target="_blank" class="galado-hub-link">
                        <span class="link-icon">📦</span>
                        <span class="link-text">GitHub Repository</span>
                        <span class="link-arrow">↗</span>
                    </a>
                    <a href="https://docs.google.com/spreadsheets/" target="_blank" class="galado-hub-link">
                        <span class="link-icon">📊</span>
                        <span class="link-text">Order Submissions (Google Sheets)</span>
                        <span class="link-arrow">↗</span>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Redirect to order form
     */
    public function redirect_order_form() {
        wp_redirect('https://xylement.github.io/galado-order-form/');
        exit;
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_styles($hook) {
        if (strpos($hook, 'galado-hub') === false) return;

        wp_enqueue_style('galado-hub', GALADO_HUB_URL . 'admin/style.css', [], GALADO_HUB_VERSION);
    }

    /**
     * SVG icon for admin menu (GALADO "G" monogram)
     */
    private static function get_icon_svg() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10 1C5 1 1 5 1 10s4 9 9 9 9-4 9-9-4-9-9-9zm3.5 10.5H10V14c0 .6-.4 1-1 1s-1-.4-1-1v-2.5H5.5c-.6 0-1-.4-1-1s.4-1 1-1H8V7c0-.6.4-1 1-1s1 .4 1 1v2.5h3.5c.6 0 1 .4 1 1s-.4 1-1 1z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

// Initialize
add_action('plugins_loaded', function() {
    Galado_Admin_Hub::instance();
});
