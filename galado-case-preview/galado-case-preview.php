<?php
/**
 * Plugin Name: GALADO Live Case Preview
 * Plugin URI: https://galado.com.my
 * Description: Live text overlay on product images. Customers see their name on the actual case as they type and select fonts.
 * Version: 1.0.0
 * Author: GALADO
 * Author URI: https://galado.com.my
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: galado-case-preview
 */

if (!defined('ABSPATH')) exit;

class GALADO_Case_Preview {

    private static $instance = null;

    // Font registry — shared with galado-font-preview plugin
    private $fonts = array(
        'Rustling Sound'    => 'Rustling Sound.ttf',
        'Ayla Handwritten'  => 'AylaHandwritten-Regular.ttf',
        'Right Strongline'  => 'Right Strongline.ttf',
        'Angelic Bonques'   => 'Angelic_Bonques_Script.ttf',
        'Bebas'             => 'Bebas-Regular.otf',
        'Orange Gummy'      => 'Orange Gummy.otf',
        'LadylikeBB'        => 'LadylikeBB.otf',
        'Kiss Me or Not'    => 'Kiss Me or Not - OTF.otf',
        'Shorelines Script' => 'Shorelines Script Bold.otf',
        'Gotcha'            => 'gotcha-regular.ttf',
    );

    // Preset positions
    private $presets = array(
        'center'        => array('label' => 'Center',        'x' => 50, 'y' => 50, 'rotate' => 0),
        'bottom-center' => array('label' => 'Bottom Center', 'x' => 50, 'y' => 72, 'rotate' => 0),
        'top-center'    => array('label' => 'Top Center',    'x' => 50, 'y' => 30, 'rotate' => 0),
        'angled-center' => array('label' => 'Angled',        'x' => 50, 'y' => 50, 'rotate' => -15),
        'lower-third'   => array('label' => 'Lower Third',   'x' => 50, 'y' => 65, 'rotate' => 0),
    );

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Admin
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post_product', array($this, 'save_meta_box'));

        // Frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend'));
        add_action('woocommerce_before_single_product_summary', array($this, 'inject_preview_overlay'), 25);

        // GALADO Admin Hub integration
        add_filter('galado_admin_hub_plugins', array($this, 'register_with_hub'));
    }

    /**
     * Register with GALADO Admin Hub
     */
    public function register_with_hub($plugins) {
        $plugins['galado-case-preview'] = array(
            'name'    => 'Live Case Preview',
            'icon'    => '👁️',
            'version' => '1.0.0',
        );
        return $plugins;
    }

    // =========================================================================
    // ADMIN: Product meta box
    // =========================================================================

    public function add_meta_box() {
        add_meta_box(
            'galado_case_preview_settings',
            'GALADO Live Case Preview',
            array($this, 'render_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('galado_case_preview_save', 'galado_case_preview_nonce');

        $enabled  = get_post_meta($post->ID, '_galado_case_preview_enabled', true);
        $preset   = get_post_meta($post->ID, '_galado_case_preview_preset', true) ?: 'center';
        $max_width = get_post_meta($post->ID, '_galado_case_preview_max_width', true) ?: 60;
        $font_size = get_post_meta($post->ID, '_galado_case_preview_font_size', true) ?: 28;
        ?>
        <style>
            .gcp-meta label { display: block; margin: 8px 0 4px; font-weight: 600; font-size: 12px; }
            .gcp-meta select, .gcp-meta input[type=number] { width: 100%; }
            .gcp-meta .description { font-size: 11px; color: #666; margin-top: 2px; }
            .gcp-preset-preview {
                margin: 10px 0;
                background: #f0f0f0;
                border-radius: 8px;
                position: relative;
                width: 100%;
                padding-bottom: 120%;
                overflow: hidden;
            }
            .gcp-preset-dot {
                position: absolute;
                width: 8px; height: 8px;
                background: #0071e3;
                border-radius: 50%;
                transform: translate(-50%, -50%);
                box-shadow: 0 0 0 3px rgba(0,113,227,0.3);
            }
            .gcp-preset-text {
                position: absolute;
                transform: translate(-50%, -50%);
                font-size: 11px;
                color: #0071e3;
                font-weight: 600;
                white-space: nowrap;
            }
        </style>
        <div class="gcp-meta">
            <label>
                <input type="checkbox" name="gcp_enabled" value="1" <?php checked($enabled, '1'); ?>>
                Enable Live Preview on this product
            </label>

            <label for="gcp_preset">Text Position Preset</label>
            <select name="gcp_preset" id="gcp_preset">
                <?php foreach ($this->presets as $key => $p): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($preset, $key); ?>
                        data-x="<?php echo esc_attr($p['x']); ?>"
                        data-y="<?php echo esc_attr($p['y']); ?>"
                        data-rotate="<?php echo esc_attr($p['rotate']); ?>">
                        <?php echo esc_html($p['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="gcp-preset-preview" id="gcp-preview-box">
                <div class="gcp-preset-dot" id="gcp-preview-dot"></div>
                <div class="gcp-preset-text" id="gcp-preview-text">GALADO</div>
            </div>

            <label for="gcp_max_width">Max Text Width (%)</label>
            <input type="number" name="gcp_max_width" id="gcp_max_width" value="<?php echo esc_attr($max_width); ?>" min="20" max="90" step="5">
            <p class="description">How wide the text can be relative to the image.</p>

            <label for="gcp_font_size">Base Font Size (px)</label>
            <input type="number" name="gcp_font_size" id="gcp_font_size" value="<?php echo esc_attr($font_size); ?>" min="12" max="60" step="2">
            <p class="description">Auto-shrinks for longer names.</p>
        </div>

        <script>
        (function() {
            var sel = document.getElementById('gcp_preset');
            var dot = document.getElementById('gcp-preview-dot');
            var txt = document.getElementById('gcp-preview-text');

            function updatePreview() {
                var opt = sel.options[sel.selectedIndex];
                var x = opt.dataset.x + '%';
                var y = opt.dataset.y + '%';
                var r = opt.dataset.rotate || 0;
                dot.style.left = x;
                dot.style.top = y;
                txt.style.left = x;
                txt.style.top = (parseFloat(opt.dataset.y) - 8) + '%';
                txt.style.transform = 'translate(-50%, -50%) rotate(' + r + 'deg)';
            }
            sel.addEventListener('change', updatePreview);
            updatePreview();
        })();
        </script>
        <?php
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST['galado_case_preview_nonce']) ||
            !wp_verify_nonce($_POST['galado_case_preview_nonce'], 'galado_case_preview_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_galado_case_preview_enabled', isset($_POST['gcp_enabled']) ? '1' : '0');
        update_post_meta($post_id, '_galado_case_preview_preset', sanitize_text_field($_POST['gcp_preset'] ?? 'center'));
        update_post_meta($post_id, '_galado_case_preview_max_width', absint($_POST['gcp_max_width'] ?? 60));
        update_post_meta($post_id, '_galado_case_preview_font_size', absint($_POST['gcp_font_size'] ?? 28));
    }

    // =========================================================================
    // FRONTEND
    // =========================================================================

    public function enqueue_frontend() {
        if (!is_product()) return;

        global $post;
        $enabled = get_post_meta($post->ID, '_galado_case_preview_enabled', true);
        if ($enabled !== '1') return;

        // Register custom fonts
        $font_url = $this->get_font_directory_url();
        $css = '';
        foreach ($this->fonts as $name => $file) {
            $slug = sanitize_title($name);
            $css .= "@font-face { font-family: '{$slug}'; src: url('{$font_url}{$file}') format('" . $this->get_font_format($file) . "'); font-display: swap; }\n";
        }
        wp_register_style('galado-case-preview-fonts', false);
        wp_enqueue_style('galado-case-preview-fonts');
        wp_add_inline_style('galado-case-preview-fonts', $css);

        // Main styles
        wp_enqueue_style('galado-case-preview', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '1.0.0');

        // Main script
        wp_enqueue_script('galado-case-preview', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), '1.0.0', true);

        // Pass config to JS
        $preset_key = get_post_meta($post->ID, '_galado_case_preview_preset', true) ?: 'center';
        $preset = $this->presets[$preset_key] ?? $this->presets['center'];

        wp_localize_script('galado-case-preview', 'gcpConfig', array(
            'enabled'   => true,
            'x'         => $preset['x'],
            'y'         => $preset['y'],
            'rotate'    => $preset['rotate'],
            'maxWidth'  => get_post_meta($post->ID, '_galado_case_preview_max_width', true) ?: 60,
            'fontSize'  => get_post_meta($post->ID, '_galado_case_preview_font_size', true) ?: 28,
            'fonts'     => $this->get_font_slugs(),
        ));
    }

    /**
     * Inject the overlay div inside the product image gallery
     */
    public function inject_preview_overlay() {
        if (!is_product()) return;

        global $post;
        $enabled = get_post_meta($post->ID, '_galado_case_preview_enabled', true);
        if ($enabled !== '1') return;

        // Output the overlay container — JS will position it over the main image
        ?>
        <div id="gcp-overlay-text" class="gcp-overlay-text" style="display:none;" aria-hidden="true"></div>
        <?php
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function get_font_directory_url() {
        // Try to use fonts from galado-font-preview plugin first
        $font_preview_dir = WP_PLUGIN_DIR . '/galado-font-preview/fonts/';
        if (is_dir($font_preview_dir)) {
            return plugins_url('galado-font-preview/fonts/');
        }
        // Fallback to bundled fonts
        return plugin_dir_url(__FILE__) . 'fonts/';
    }

    private function get_font_format($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $formats = array(
            'ttf'  => 'truetype',
            'otf'  => 'opentype',
            'woff' => 'woff',
            'woff2'=> 'woff2',
        );
        return $formats[$ext] ?? 'truetype';
    }

    private function get_font_slugs() {
        $slugs = array();
        foreach ($this->fonts as $name => $file) {
            $slugs[] = array(
                'name' => $name,
                'slug' => sanitize_title($name),
            );
        }
        return $slugs;
    }
}

// Initialize
GALADO_Case_Preview::instance();
