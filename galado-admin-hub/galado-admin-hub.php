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
        add_action('admin_menu', [$this, 'add_extra_links'], 999);
        // Discover plugins AFTER all admin menus are registered so we can find settings URLs
        add_action('admin_menu', [$this, 'discover_plugins'], 9999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('admin_init', [$this, 'handle_save_links']);
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
     * Add extra links (Git Sync relocation + external links)
     * GALADO plugins register themselves directly under galado-hub
     */
    public function add_extra_links() {
        global $submenu;

        // Relocate Git Sync from Tools to GALADO hub
        if (isset($submenu['tools.php'])) {
            foreach ($submenu['tools.php'] as $key => $item) {
                if ($item[2] === 'flavor-git-sync') {
                    add_submenu_page(
                        'galado-hub',
                        $item[0],
                        'Git Sync',
                        'manage_options',
                        'flavor-git-sync'
                    );
                    unset($submenu['tools.php'][$key]);
                    break;
                }
            }
        }

        // External links
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
     * Handle saving quick links
     */
    public function handle_save_links() {
        if (!isset($_POST['galado_hub_links_nonce'])) return;
        if (!wp_verify_nonce($_POST['galado_hub_links_nonce'], 'galado_hub_save_links')) return;
        if (!current_user_can('manage_options')) return;

        $links = [];
        if (isset($_POST['galado_links']) && is_array($_POST['galado_links'])) {
            foreach ($_POST['galado_links'] as $link) {
                $icon = sanitize_text_field($link['icon'] ?? '');
                $label = sanitize_text_field($link['label'] ?? '');
                $url = esc_url_raw($link['url'] ?? '');
                if ($label || $url) {
                    $links[] = ['icon' => $icon, 'label' => $label, 'url' => $url];
                }
            }
        }
        update_option('galado_hub_quick_links', $links);

        // Redirect back to avoid form resubmission
        wp_redirect(admin_url('admin.php?page=galado-hub&links-saved=1'));
        exit;
    }

    /**
     * Auto-discover all GALADO plugins + manually registered ones
     * Runs at admin_menu priority 9999 so all menus are registered
     */
    public function discover_plugins() {
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

            // Resolve settings URL using known mappings first, then folder-based guessing
            $folder_clean = str_replace('galado-', '', $folder);
            $found_slug = '';

            // Check known settings map
            if (isset(self::$known_settings[$folder])) {
                $mapped = self::$known_settings[$folder];
                if (strpos($mapped, '.php') !== false) {
                    // Direct URL like edit.php?post_type=product
                    $settings_url = admin_url($mapped);
                } else {
                    $found_slug = $mapped;
                    $settings_url = admin_url('admin.php?page=' . $mapped);
                }
            } else {
                // Try common slug patterns
                $slug_attempts = [
                    'galado-' . $folder_clean,
                    $folder,
                    $folder_clean,
                ];

                foreach ($slug_attempts as $try_slug) {
                    // Check if this page exists in registered submenus
                    global $submenu;
                    if (is_array($submenu)) {
                        foreach ($submenu as $parent => $items) {
                            foreach ($items as $sub_item) {
                                if ($sub_item[2] === $try_slug) {
                                    $found_slug = $try_slug;
                                    $settings_url = admin_url('admin.php?page=' . $try_slug);
                                    break 3;
                                }
                            }
                        }
                    }
                }

                // If still not found, default to the plugin's admin page slug
                if (!$found_slug) {
                    $settings_url = admin_url('admin.php?page=galado-' . $folder_clean);
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

            <?php
            // Quick links - configurable via Settings
            $quick_links = get_option('galado_hub_quick_links', [
                ['icon' => '📝', 'label' => 'Walk-In Order Form', 'url' => 'https://xylement.github.io/galado-order-form/'],
                ['icon' => '📦', 'label' => 'GitHub Repository', 'url' => 'https://github.com/Xylement/galado-order-form'],
                ['icon' => '📊', 'label' => 'Order Submissions (Google Sheets)', 'url' => ''],
            ]);
            $has_links = false;
            foreach ($quick_links as $link) { if (!empty($link['url'])) $has_links = true; }
            ?>

            <div class="galado-hub-links">
                <h2>Quick Links
                    <a href="#" id="galado-hub-edit-links" style="font-size:13px;font-weight:400;margin-left:12px;text-decoration:none;">Edit Links</a>
                </h2>

                <!-- Display mode -->
                <div class="galado-hub-links-grid" id="galado-hub-links-display">
                    <?php foreach ($quick_links as $link): ?>
                        <?php if (!empty($link['url'])): ?>
                        <a href="<?php echo esc_url($link['url']); ?>" target="_blank" class="galado-hub-link">
                            <span class="link-icon"><?php echo esc_html($link['icon']); ?></span>
                            <span class="link-text"><?php echo esc_html($link['label']); ?></span>
                            <span class="link-arrow">↗</span>
                        </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$has_links): ?>
                        <p style="color:#78716c;">No quick links configured yet. Click "Edit Links" to add some.</p>
                    <?php endif; ?>
                </div>

                <!-- Edit mode (hidden by default) -->
                <form method="post" action="" id="galado-hub-links-form" style="display:none;">
                    <?php wp_nonce_field('galado_hub_save_links', 'galado_hub_links_nonce'); ?>
                    <div id="galado-hub-links-editor">
                        <?php foreach ($quick_links as $i => $link): ?>
                        <div class="galado-hub-link-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
                            <input type="text" name="galado_links[<?php echo $i; ?>][icon]" value="<?php echo esc_attr($link['icon']); ?>" style="width:50px;text-align:center;" placeholder="📝">
                            <input type="text" name="galado_links[<?php echo $i; ?>][label]" value="<?php echo esc_attr($link['label']); ?>" style="flex:1;" placeholder="Link name">
                            <input type="url" name="galado_links[<?php echo $i; ?>][url]" value="<?php echo esc_attr($link['url']); ?>" style="flex:2;" placeholder="https://...">
                            <button type="button" class="button galado-hub-remove-link" title="Remove">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:8px;">
                        <button type="button" id="galado-hub-add-link" class="button">+ Add Link</button>
                        <button type="submit" class="button button-primary">Save Links</button>
                        <button type="button" id="galado-hub-cancel-links" class="button">Cancel</button>
                    </div>
                </form>
            </div>

            <script>
            (function() {
                var editBtn = document.getElementById('galado-hub-edit-links');
                var display = document.getElementById('galado-hub-links-display');
                var form = document.getElementById('galado-hub-links-form');
                var editor = document.getElementById('galado-hub-links-editor');
                var cancelBtn = document.getElementById('galado-hub-cancel-links');
                var addBtn = document.getElementById('galado-hub-add-link');
                var idx = editor.querySelectorAll('.galado-hub-link-row').length;

                editBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    display.style.display = 'none';
                    form.style.display = 'block';
                });
                cancelBtn.addEventListener('click', function() {
                    form.style.display = 'none';
                    display.style.display = 'flex';
                });
                addBtn.addEventListener('click', function() {
                    var row = document.createElement('div');
                    row.className = 'galado-hub-link-row';
                    row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';
                    row.innerHTML = '<input type="text" name="galado_links[' + idx + '][icon]" style="width:50px;text-align:center;" placeholder="📝">' +
                        '<input type="text" name="galado_links[' + idx + '][label]" style="flex:1;" placeholder="Link name">' +
                        '<input type="url" name="galado_links[' + idx + '][url]" style="flex:2;" placeholder="https://...">' +
                        '<button type="button" class="button galado-hub-remove-link" title="Remove">&times;</button>';
                    editor.appendChild(row);
                    idx++;
                });
                editor.addEventListener('click', function(e) {
                    if (e.target.classList.contains('galado-hub-remove-link')) {
                        e.target.closest('.galado-hub-link-row').remove();
                    }
                });
            })();
            </script>
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
