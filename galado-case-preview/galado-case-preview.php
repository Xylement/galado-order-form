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

        $enabled   = get_post_meta($post->ID, '_galado_case_preview_enabled', true);
        $x         = get_post_meta($post->ID, '_galado_case_preview_x', true);
        $y         = get_post_meta($post->ID, '_galado_case_preview_y', true);
        $rotate    = get_post_meta($post->ID, '_galado_case_preview_rotate', true);
        $max_width = get_post_meta($post->ID, '_galado_case_preview_max_width', true) ?: 60;
        $font_size = get_post_meta($post->ID, '_galado_case_preview_font_size', true) ?: 28;

        // Backward compat: if never set, fall back to center
        if ($x === '')      $x      = 50;
        if ($y === '')      $y      = 50;
        if ($rotate === '') $rotate = 0;

        $thumb_url = get_the_post_thumbnail_url($post->ID, 'medium') ?: '';
        ?>
        <style>
            .gcp-meta label { display: block; margin: 8px 0 4px; font-weight: 600; font-size: 12px; }
            .gcp-meta input[type=number] { width: 100%; }
            .gcp-meta .description { font-size: 11px; color: #666; margin-top: 2px; }

            /* Drag box */
            .gcp-drag-box {
                position: relative; width: 100%; padding-bottom: 120%;
                background: #e0e0e0; border-radius: 8px; overflow: hidden;
                cursor: crosshair; margin: 8px 0; border: 2px solid #ccc;
                user-select: none; -webkit-user-select: none;
            }
            .gcp-drag-box img {
                position: absolute; top: 0; left: 0;
                width: 100%; height: 100%; object-fit: cover;
                pointer-events: none; display: block;
            }
            .gcp-drag-placeholder {
                position: absolute; top: 0; left: 0; width: 100%; height: 100%;
                display: flex; align-items: center; justify-content: center;
                font-size: 11px; color: #999; pointer-events: none;
            }
            .gcp-drag-dot {
                position: absolute; width: 16px; height: 16px;
                background: #0071e3; border-radius: 50%;
                transform: translate(-50%, -50%);
                box-shadow: 0 0 0 4px rgba(0,113,227,0.3), 0 2px 8px rgba(0,0,0,0.25);
                cursor: grab; z-index: 3;
            }
            .gcp-drag-dot:active { cursor: grabbing; }
            .gcp-drag-tag {
                position: absolute; font-size: 10px; color: #0071e3;
                font-weight: 700; white-space: nowrap; pointer-events: none;
                transform: translate(-50%, 8px); z-index: 4;
                text-shadow: 0 1px 2px #fff;
            }
            .gcp-coords { font-size: 11px; color: #555; margin-bottom: 6px; }

            /* Preset quick-start buttons */
            .gcp-presets { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 10px; }
            .gcp-preset-btn {
                flex: 1 1 45%; padding: 4px 6px; font-size: 11px;
                background: #f0f0f0; border: 1px solid #ccc; border-radius: 4px;
                cursor: pointer; text-align: center; line-height: 1.4;
            }
            .gcp-preset-btn:hover { background: #ddd; }
        </style>
        <div class="gcp-meta">
            <label>
                <input type="checkbox" name="gcp_enabled" value="1" <?php checked($enabled, '1'); ?>>
                Enable Live Preview on this product
            </label>

            <label>Text Position — drag the dot or click to place</label>
            <div class="gcp-drag-box" id="gcp-drag-box">
                <?php if ($thumb_url): ?>
                    <img src="<?php echo esc_url($thumb_url); ?>" alt="">
                <?php else: ?>
                    <div class="gcp-drag-placeholder">Click anywhere to set position</div>
                <?php endif; ?>
                <div class="gcp-drag-dot" id="gcp-drag-dot"></div>
                <div class="gcp-drag-tag" id="gcp-drag-tag">Name</div>
            </div>
            <div class="gcp-coords" id="gcp-coords">
                X: <?php echo esc_html($x); ?>% &nbsp; Y: <?php echo esc_html($y); ?>%
            </div>

            <div class="gcp-presets">
                <?php foreach ($this->presets as $p): ?>
                    <button type="button" class="gcp-preset-btn"
                        data-x="<?php echo esc_attr($p['x']); ?>"
                        data-y="<?php echo esc_attr($p['y']); ?>"
                        data-rotate="<?php echo esc_attr($p['rotate']); ?>">
                        <?php echo esc_html($p['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <input type="hidden" name="gcp_x" id="gcp_x" value="<?php echo esc_attr($x); ?>">
            <input type="hidden" name="gcp_y" id="gcp_y" value="<?php echo esc_attr($y); ?>">

            <label for="gcp_rotate">Rotation (degrees)</label>
            <input type="number" name="gcp_rotate" id="gcp_rotate" value="<?php echo esc_attr($rotate); ?>" min="-45" max="45" step="5">
            <p class="description">Tilt the text. 0 = straight, negative = lean left.</p>

            <label for="gcp_max_width">Max Text Width (%)</label>
            <input type="number" name="gcp_max_width" id="gcp_max_width" value="<?php echo esc_attr($max_width); ?>" min="20" max="90" step="5">
            <p class="description">How wide the text can be relative to the image.</p>

            <label for="gcp_font_size">Base Font Size (px)</label>
            <input type="number" name="gcp_font_size" id="gcp_font_size" value="<?php echo esc_attr($font_size); ?>" min="12" max="60" step="2">
            <p class="description">Auto-shrinks for longer names.</p>
        </div>

        <script>
        (function() {
            var box      = document.getElementById('gcp-drag-box');
            var dot      = document.getElementById('gcp-drag-dot');
            var tag      = document.getElementById('gcp-drag-tag');
            var coords   = document.getElementById('gcp-coords');
            var inputX   = document.getElementById('gcp_x');
            var inputY   = document.getElementById('gcp_y');
            var inputRot = document.getElementById('gcp_rotate');

            var curX = parseFloat(inputX.value) || 50;
            var curY = parseFloat(inputY.value) || 50;
            var dragging = false;

            function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

            function moveDot(x, y) {
                curX = clamp(x, 0, 100);
                curY = clamp(y, 0, 100);
                dot.style.left = curX + '%';
                dot.style.top  = curY + '%';
                tag.style.left = curX + '%';
                tag.style.top  = curY + '%';
                var rx = Math.round(curX), ry = Math.round(curY);
                inputX.value = rx;
                inputY.value = ry;
                coords.innerHTML = 'X: ' + rx + '% &nbsp; Y: ' + ry + '%';
            }

            function pctFromEvent(e) {
                var rect = box.getBoundingClientRect();
                return {
                    x: ((e.clientX - rect.left) / rect.width)  * 100,
                    y: ((e.clientY - rect.top)  / rect.height) * 100
                };
            }

            // Initialise dot position
            moveDot(curX, curY);

            // Click anywhere in box to move dot (unless starting a drag)
            box.addEventListener('click', function(e) {
                if (dragging) return;
                var p = pctFromEvent(e);
                moveDot(p.x, p.y);
            });

            // Drag the dot
            dot.addEventListener('mousedown', function(e) {
                dragging = true;
                e.preventDefault();
                e.stopPropagation();
            });
            document.addEventListener('mousemove', function(e) {
                if (!dragging) return;
                var p = pctFromEvent(e);
                moveDot(p.x, p.y);
            });
            document.addEventListener('mouseup', function() { dragging = false; });

            // Touch drag
            dot.addEventListener('touchstart', function(e) {
                dragging = true;
                e.preventDefault();
            }, { passive: false });
            document.addEventListener('touchmove', function(e) {
                if (!dragging) return;
                var t = e.touches[0];
                var rect = box.getBoundingClientRect();
                moveDot(
                    ((t.clientX - rect.left) / rect.width)  * 100,
                    ((t.clientY - rect.top)  / rect.height) * 100
                );
            }, { passive: true });
            document.addEventListener('touchend', function() { dragging = false; });

            // Preset buttons
            document.querySelectorAll('.gcp-preset-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    moveDot(parseFloat(this.dataset.x), parseFloat(this.dataset.y));
                    inputRot.value = parseFloat(this.dataset.rotate) || 0;
                });
            });
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

        update_post_meta($post_id, '_galado_case_preview_enabled',   isset($_POST['gcp_enabled']) ? '1' : '0');
        update_post_meta($post_id, '_galado_case_preview_x',         absint($_POST['gcp_x']         ?? 50));
        update_post_meta($post_id, '_galado_case_preview_y',         absint($_POST['gcp_y']         ?? 50));
        update_post_meta($post_id, '_galado_case_preview_rotate',    intval($_POST['gcp_rotate']    ?? 0));
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

        // Pass config to JS — use free-position values; fall back to preset for old products
        $x      = get_post_meta($post->ID, '_galado_case_preview_x',      true);
        $y      = get_post_meta($post->ID, '_galado_case_preview_y',      true);
        $rotate = get_post_meta($post->ID, '_galado_case_preview_rotate', true);

        if ($x === '') {
            // Backward compat: read old preset key if no free position saved yet
            $preset_key = get_post_meta($post->ID, '_galado_case_preview_preset', true) ?: 'center';
            $preset = $this->presets[$preset_key] ?? $this->presets['center'];
            $x      = $preset['x'];
            $y      = $preset['y'];
            $rotate = $preset['rotate'];
        }

        wp_localize_script('galado-case-preview', 'gcpConfig', array(
            'enabled'  => true,
            'x'        => $x        ?: 50,
            'y'        => $y        ?: 50,
            'rotate'   => $rotate !== '' ? $rotate : 0,
            'maxWidth' => get_post_meta($post->ID, '_galado_case_preview_max_width', true) ?: 60,
            'fontSize' => get_post_meta($post->ID, '_galado_case_preview_font_size', true) ?: 28,
            'fonts'    => $this->get_font_slugs(),
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
