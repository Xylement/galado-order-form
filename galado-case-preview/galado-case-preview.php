<?php
/**
 * Plugin Name: GALADO Live Case Preview
 * Plugin URI: https://galado.com.my
 * Description: Live text overlay on a dedicated mockup image. Customers see their name on the actual case as they type and select fonts — right next to the font selector, no gallery involvement.
 * Version: 1.2.0
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

    // Preset rectangles: x/y = top-left corner %, w/h = size %
    private $presets = array(
        'center'  => array('label' => 'Center',       'x' => 20, 'y' => 35, 'w' => 60, 'h' => 30, 'rotate' => 0),
        'bottom'  => array('label' => 'Bottom',       'x' => 15, 'y' => 62, 'w' => 70, 'h' => 25, 'rotate' => 0),
        'top'     => array('label' => 'Top',          'x' => 20, 'y' => 10, 'w' => 60, 'h' => 25, 'rotate' => 0),
        'angled'  => array('label' => 'Angled',       'x' => 20, 'y' => 35, 'w' => 60, 'h' => 30, 'rotate' => -15),
        'wide'    => array('label' => 'Wide Band',    'x' => 8,  'y' => 45, 'w' => 84, 'h' => 18, 'rotate' => 0),
    );

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        add_action('add_meta_boxes',        array($this, 'add_meta_box'));
        add_action('save_post_product',     array($this, 'save_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));
        add_action('wp_enqueue_scripts',    array($this, 'enqueue_frontend'));
        add_action('galado_fp_after_colour',array($this, 'inject_preview_widget'));
        add_filter('galado_admin_hub_plugins', array($this, 'register_with_hub'));
    }

    public function register_with_hub($plugins) {
        $plugins['galado-case-preview'] = array(
            'name'    => 'Live Case Preview',
            'icon'    => '👁️',
            'version' => '1.2.0',
        );
        return $plugins;
    }

    // =========================================================================
    // ADMIN
    // =========================================================================

    public function enqueue_admin($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'))) return;
        global $post;
        if (!isset($post) || $post->post_type !== 'product') return;
        wp_enqueue_media();
    }

    public function add_meta_box() {
        add_meta_box(
            'galado_case_preview_settings',
            'GALADO Live Case Preview',
            array($this, 'render_meta_box'),
            'product', 'side', 'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('galado_case_preview_save', 'galado_case_preview_nonce');

        $enabled  = get_post_meta($post->ID, '_galado_case_preview_enabled',   true);
        $image_id = get_post_meta($post->ID, '_galado_case_preview_image_id',  true);
        $rect_x   = get_post_meta($post->ID, '_galado_case_preview_rect_x',    true);
        $rect_y   = get_post_meta($post->ID, '_galado_case_preview_rect_y',    true);
        $rect_w   = get_post_meta($post->ID, '_galado_case_preview_rect_w',    true);
        $rect_h   = get_post_meta($post->ID, '_galado_case_preview_rect_h',    true);
        $rotate   = get_post_meta($post->ID, '_galado_case_preview_rotate',    true);
        $font_size= get_post_meta($post->ID, '_galado_case_preview_font_size', true);

        // Defaults
        if ($rect_x   === '') $rect_x   = 20;
        if ($rect_y   === '') $rect_y   = 35;
        if ($rect_w   === '') $rect_w   = 60;
        if ($rect_h   === '') $rect_h   = 30;
        if ($rotate   === '') $rotate   = 0;
        if ($font_size === '') $font_size = 40;

        $preview_url = $image_id
            ? wp_get_attachment_image_url($image_id, 'medium')
            : get_the_post_thumbnail_url($post->ID, 'medium');
        ?>
        <style>
        .gcp-meta label{display:block;margin:8px 0 3px;font-weight:600;font-size:12px}
        .gcp-meta input[type=number]{width:100%}
        .gcp-meta .description{font-size:11px;color:#666;margin-top:2px}
        .gcp-image-wrap{margin:6px 0 8px}
        .gcp-image-wrap img{display:block;width:100%;border-radius:6px;margin-bottom:6px}
        .gcp-image-btns{display:flex;gap:6px}
        .gcp-image-btns .button{flex:1;font-size:11px;padding:3px 8px}

        /* Drag box */
        .gcp-drag-box{position:relative;width:100%;padding-bottom:100%;background:#d8d8d8;border-radius:8px;overflow:hidden;margin:8px 0;border:2px solid #ccc;user-select:none;-webkit-user-select:none}
        .gcp-drag-box img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;pointer-events:none;display:block}
        .gcp-drag-placeholder{position:absolute;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:11px;color:#999;pointer-events:none}

        /* Rectangle */
        #gcp-rect{position:absolute;border:2px solid #0071e3;background:rgba(0,113,227,0.10);box-sizing:border-box;cursor:move;display:flex;align-items:center;justify-content:center}
        #gcp-rect-label{font-size:10px;color:#0071e3;font-weight:700;pointer-events:none;text-shadow:0 1px 2px rgba(255,255,255,0.9);text-align:center;padding:2px;line-height:1.2}

        /* Resize handles */
        .gcp-h{position:absolute;width:10px;height:10px;background:#0071e3;border:2px solid #fff;border-radius:2px;box-sizing:border-box;z-index:5}
        .gcp-h-n {top:-5px;left:50%;transform:translateX(-50%);cursor:n-resize}
        .gcp-h-s {bottom:-5px;left:50%;transform:translateX(-50%);cursor:s-resize}
        .gcp-h-e {top:50%;right:-5px;transform:translateY(-50%);cursor:e-resize}
        .gcp-h-w {top:50%;left:-5px;transform:translateY(-50%);cursor:w-resize}
        .gcp-h-ne{top:-5px;right:-5px;cursor:ne-resize}
        .gcp-h-nw{top:-5px;left:-5px;cursor:nw-resize}
        .gcp-h-se{bottom:-5px;right:-5px;cursor:se-resize}
        .gcp-h-sw{bottom:-5px;left:-5px;cursor:sw-resize}

        .gcp-coords{font-size:11px;color:#555;margin-bottom:6px}
        .gcp-presets{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px}
        .gcp-preset-btn{flex:1 1 45%;padding:4px 6px;font-size:11px;background:#f0f0f0;border:1px solid #ccc;border-radius:4px;cursor:pointer;text-align:center;line-height:1.4}
        .gcp-preset-btn:hover{background:#ddd}
        </style>

        <div class="gcp-meta">
            <label>
                <input type="checkbox" name="gcp_enabled" value="1" <?php checked($enabled, '1'); ?>>
                Enable Live Preview on this product
            </label>

            <label>Mockup Image <span style="font-weight:400;color:#888;">(shown to customer)</span></label>
            <div class="gcp-image-wrap" id="gcp-image-wrap">
                <?php if ($preview_url): ?>
                    <img src="<?php echo esc_url($preview_url); ?>" id="gcp-image-thumb" alt="">
                <?php else: ?>
                    <p style="font-size:11px;color:#999;margin:0 0 6px;">No image — upload a flat-lay or mockup of the case.</p>
                <?php endif; ?>
                <div class="gcp-image-btns">
                    <button type="button" class="button" id="gcp-upload-btn">
                        <?php echo $image_id ? 'Change Image' : 'Select Image'; ?>
                    </button>
                    <?php if ($image_id): ?>
                        <button type="button" class="button" id="gcp-remove-btn">Remove</button>
                    <?php endif; ?>
                </div>
            </div>
            <input type="hidden" name="gcp_image_id" id="gcp_image_id" value="<?php echo esc_attr($image_id); ?>">

            <label>Text Zone — drag to move · handles to resize</label>
            <div class="gcp-drag-box" id="gcp-drag-box">
                <?php if ($preview_url): ?>
                    <img src="<?php echo esc_url($preview_url); ?>" id="gcp-drag-img" alt="">
                <?php else: ?>
                    <div class="gcp-drag-placeholder">Upload a mockup image first</div>
                <?php endif; ?>
                <div id="gcp-rect">
                    <div class="gcp-h gcp-h-n"  data-dir="n"></div>
                    <div class="gcp-h gcp-h-ne" data-dir="ne"></div>
                    <div class="gcp-h gcp-h-e"  data-dir="e"></div>
                    <div class="gcp-h gcp-h-se" data-dir="se"></div>
                    <div class="gcp-h gcp-h-s"  data-dir="s"></div>
                    <div class="gcp-h gcp-h-sw" data-dir="sw"></div>
                    <div class="gcp-h gcp-h-w"  data-dir="w"></div>
                    <div class="gcp-h gcp-h-nw" data-dir="nw"></div>
                    <div id="gcp-rect-label">Name</div>
                </div>
            </div>
            <div class="gcp-coords" id="gcp-coords"></div>

            <div class="gcp-presets">
                <?php foreach ($this->presets as $p): ?>
                    <button type="button" class="gcp-preset-btn"
                        data-x="<?php echo esc_attr($p['x']); ?>"
                        data-y="<?php echo esc_attr($p['y']); ?>"
                        data-w="<?php echo esc_attr($p['w']); ?>"
                        data-h="<?php echo esc_attr($p['h']); ?>"
                        data-rotate="<?php echo esc_attr($p['rotate']); ?>">
                        <?php echo esc_html($p['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <input type="hidden" name="gcp_rect_x" id="gcp_rect_x" value="<?php echo esc_attr($rect_x); ?>">
            <input type="hidden" name="gcp_rect_y" id="gcp_rect_y" value="<?php echo esc_attr($rect_y); ?>">
            <input type="hidden" name="gcp_rect_w" id="gcp_rect_w" value="<?php echo esc_attr($rect_w); ?>">
            <input type="hidden" name="gcp_rect_h" id="gcp_rect_h" value="<?php echo esc_attr($rect_h); ?>">

            <label for="gcp_rotate">Rotation (degrees)</label>
            <input type="number" name="gcp_rotate" id="gcp_rotate" value="<?php echo esc_attr($rotate); ?>" min="-45" max="45" step="5">
            <p class="description">0 = straight · negative = lean left</p>

            <label for="gcp_font_size">Max Font Size (px)</label>
            <input type="number" name="gcp_font_size" id="gcp_font_size" value="<?php echo esc_attr($font_size); ?>" min="12" max="80" step="2">
            <p class="description">Font auto-shrinks to fit the zone. This is the largest it will ever be.</p>
        </div>

        <script>
        (function() {
            var box    = document.getElementById('gcp-drag-box');
            var rect   = document.getElementById('gcp-rect');
            var coords = document.getElementById('gcp-coords');
            var iX = document.getElementById('gcp_rect_x');
            var iY = document.getElementById('gcp_rect_y');
            var iW = document.getElementById('gcp_rect_w');
            var iH = document.getElementById('gcp_rect_h');
            var iR = document.getElementById('gcp_rotate');

            var MIN_W = 10, MIN_H = 8;

            function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

            function applyRect(x, y, w, h) {
                w = clamp(w, MIN_W, 100);
                h = clamp(h, MIN_H, 100);
                x = clamp(x, 0, 100 - w);
                y = clamp(y, 0, 100 - h);
                rect.style.left   = x + '%';
                rect.style.top    = y + '%';
                rect.style.width  = w + '%';
                rect.style.height = h + '%';
                iX.value = Math.round(x);
                iY.value = Math.round(y);
                iW.value = Math.round(w);
                iH.value = Math.round(h);
                coords.textContent = 'X:' + Math.round(x) + '% Y:' + Math.round(y) + '% W:' + Math.round(w) + '% H:' + Math.round(h) + '%';
            }

            // Set initial rect
            applyRect(
                parseFloat(iX.value) || 20,
                parseFloat(iY.value) || 35,
                parseFloat(iW.value) || 60,
                parseFloat(iH.value) || 30
            );

            // ── Drag / resize state ──────────────────────────────────────────
            var state = null;

            function pct(px, total) { return (px / total) * 100; }

            function getState(e, dir) {
                var br = box.getBoundingClientRect();
                var cx = e.clientX || e.touches[0].clientX;
                var cy = e.clientY || e.touches[0].clientY;
                return {
                    dir:   dir,
                    sx:    cx, sy: cy,
                    ox:    parseFloat(iX.value),
                    oy:    parseFloat(iY.value),
                    ow:    parseFloat(iW.value),
                    oh:    parseFloat(iH.value),
                    bw:    br.width,
                    bh:    br.height
                };
            }

            function onMove(cx, cy) {
                if (!state) return;
                var dx = pct(cx - state.sx, state.bw);
                var dy = pct(cy - state.sy, state.bh);
                var x = state.ox, y = state.oy, w = state.ow, h = state.oh;
                switch (state.dir) {
                    case 'move': x += dx; y += dy; break;
                    case 'n':   y += dy; h -= dy; break;
                    case 's':   h += dy; break;
                    case 'e':   w += dx; break;
                    case 'w':   x += dx; w -= dx; break;
                    case 'ne':  y += dy; h -= dy; w += dx; break;
                    case 'nw':  y += dy; h -= dy; x += dx; w -= dx; break;
                    case 'se':  h += dy; w += dx; break;
                    case 'sw':  h += dy; x += dx; w -= dx; break;
                }
                applyRect(x, y, w, h);
            }

            // Mouse
            rect.addEventListener('mousedown', function(e) {
                if (e.target.classList.contains('gcp-h')) return;
                e.preventDefault(); state = getState(e, 'move');
            });
            document.querySelectorAll('.gcp-h').forEach(function(h) {
                h.addEventListener('mousedown', function(e) {
                    e.preventDefault(); e.stopPropagation();
                    state = getState(e, h.dataset.dir);
                });
            });
            document.addEventListener('mousemove', function(e) {
                if (!state) return;
                onMove(e.clientX, e.clientY);
            });
            document.addEventListener('mouseup', function() { state = null; });

            // Touch
            rect.addEventListener('touchstart', function(e) {
                if (e.target.classList.contains('gcp-h')) return;
                e.preventDefault(); state = getState(e, 'move');
            }, { passive: false });
            document.querySelectorAll('.gcp-h').forEach(function(h) {
                h.addEventListener('touchstart', function(e) {
                    e.preventDefault(); e.stopPropagation();
                    state = getState(e, h.dataset.dir);
                }, { passive: false });
            });
            document.addEventListener('touchmove', function(e) {
                if (!state) return;
                onMove(e.touches[0].clientX, e.touches[0].clientY);
            }, { passive: true });
            document.addEventListener('touchend', function() { state = null; });

            // Presets
            document.querySelectorAll('.gcp-preset-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    applyRect(
                        parseFloat(this.dataset.x),
                        parseFloat(this.dataset.y),
                        parseFloat(this.dataset.w),
                        parseFloat(this.dataset.h)
                    );
                    iR.value = parseFloat(this.dataset.rotate) || 0;
                });
            });

            // Media uploader
            jQuery(function($) {
                var mediaFrame;
                $('#gcp-upload-btn').on('click', function(e) {
                    e.preventDefault();
                    if (mediaFrame) { mediaFrame.open(); return; }
                    mediaFrame = wp.media({ title: 'Select Mockup Image', button: { text: 'Use this image' }, multiple: false });
                    mediaFrame.on('select', function() {
                        var att = mediaFrame.state().get('selection').first().toJSON();
                        $('#gcp_image_id').val(att.id);
                        if ($('#gcp-image-thumb').length) {
                            $('#gcp-image-thumb').attr('src', att.url);
                        } else {
                            $('#gcp-image-wrap').prepend('<img src="' + att.url + '" id="gcp-image-thumb" alt="">');
                            $('p', '#gcp-image-wrap').remove();
                        }
                        $('#gcp-upload-btn').text('Change Image');
                        if ($('#gcp-drag-img').length) {
                            $('#gcp-drag-img').attr('src', att.url);
                        } else {
                            $('.gcp-drag-placeholder').replaceWith('<img src="' + att.url + '" id="gcp-drag-img" alt="">');
                        }
                        if (!$('#gcp-remove-btn').length) {
                            $('#gcp-upload-btn').after('<button type="button" class="button" id="gcp-remove-btn">Remove</button>');
                        }
                    });
                    mediaFrame.open();
                });
                $(document).on('click', '#gcp-remove-btn', function() {
                    $('#gcp_image_id').val('');
                    $('#gcp-image-thumb').remove();
                    $('#gcp-image-wrap').prepend('<p style="font-size:11px;color:#999;margin:0 0 6px;">No image selected.</p>');
                    $('#gcp-upload-btn').text('Select Image');
                    $(this).remove();
                    $('#gcp-drag-img').replaceWith('<div class="gcp-drag-placeholder">Upload a mockup image first</div>');
                });
            });
        })();
        </script>
        <?php
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST['galado_case_preview_nonce']) ||
            !wp_verify_nonce($_POST['galado_case_preview_nonce'], 'galado_case_preview_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_galado_case_preview_enabled',   isset($_POST['gcp_enabled']) ? '1' : '0');
        update_post_meta($post_id, '_galado_case_preview_image_id',  absint($_POST['gcp_image_id']  ?? 0));
        update_post_meta($post_id, '_galado_case_preview_rect_x',    absint($_POST['gcp_rect_x']    ?? 20));
        update_post_meta($post_id, '_galado_case_preview_rect_y',    absint($_POST['gcp_rect_y']    ?? 35));
        update_post_meta($post_id, '_galado_case_preview_rect_w',    absint($_POST['gcp_rect_w']    ?? 60));
        update_post_meta($post_id, '_galado_case_preview_rect_h',    absint($_POST['gcp_rect_h']    ?? 30));
        update_post_meta($post_id, '_galado_case_preview_rotate',    intval($_POST['gcp_rotate']    ?? 0));
        update_post_meta($post_id, '_galado_case_preview_font_size', absint($_POST['gcp_font_size'] ?? 40));
    }

    // =========================================================================
    // FRONTEND
    // =========================================================================

    public function enqueue_frontend() {
        if (!is_product()) return;
        global $post;
        $enabled = get_post_meta($post->ID, '_galado_case_preview_enabled', true);
        if ($enabled !== '1') return;
        $image_id = get_post_meta($post->ID, '_galado_case_preview_image_id', true);
        if (!$image_id) return;

        // Register fonts
        $font_url = $this->get_font_directory_url();
        $css = '';
        foreach ($this->fonts as $name => $file) {
            $css .= "@font-face{font-family:'{$name}';src:url('{$font_url}{$file}') format('" . $this->get_font_format($file) . "');font-display:swap}\n";
        }
        wp_register_style('galado-case-preview-fonts', false);
        wp_enqueue_style('galado-case-preview-fonts');
        wp_add_inline_style('galado-case-preview-fonts', $css);

        $dir = plugin_dir_path(__FILE__) . 'assets/';
        wp_enqueue_style('galado-case-preview',  plugin_dir_url(__FILE__) . 'assets/style.css',  array(), filemtime($dir . 'style.css'));
        wp_enqueue_script('galado-case-preview', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), filemtime($dir . 'script.js'), true);

        $rect_x = get_post_meta($post->ID, '_galado_case_preview_rect_x', true);
        $rect_y = get_post_meta($post->ID, '_galado_case_preview_rect_y', true);
        $rect_w = get_post_meta($post->ID, '_galado_case_preview_rect_w', true);
        $rect_h = get_post_meta($post->ID, '_galado_case_preview_rect_h', true);
        $rotate = get_post_meta($post->ID, '_galado_case_preview_rotate', true);

        // Backward compat: if rect not set, fall back to old x/y dot system
        if ($rect_x === '') {
            $old_x  = get_post_meta($post->ID, '_galado_case_preview_x', true) ?: 50;
            $old_y  = get_post_meta($post->ID, '_galado_case_preview_y', true) ?: 50;
            $rect_w = get_post_meta($post->ID, '_galado_case_preview_max_width', true) ?: 60;
            $rect_h = 30;
            $rect_x = max(0, $old_x - $rect_w / 2);
            $rect_y = max(0, $old_y - $rect_h / 2);
        }

        wp_localize_script('galado-case-preview', 'gcpConfig', array(
            'enabled'  => true,
            'rectX'    => floatval($rect_x ?: 20),
            'rectY'    => floatval($rect_y ?: 35),
            'rectW'    => floatval($rect_w ?: 60),
            'rectH'    => floatval($rect_h ?: 30),
            'rotate'   => intval($rotate !== '' ? $rotate : 0),
            'fontSize' => intval(get_post_meta($post->ID, '_galado_case_preview_font_size', true) ?: 40),
        ));
    }

    public function inject_preview_widget() {
        if (!is_product()) return;
        global $post;
        $enabled  = get_post_meta($post->ID, '_galado_case_preview_enabled',  true);
        $image_id = get_post_meta($post->ID, '_galado_case_preview_image_id', true);
        if ($enabled !== '1' || !$image_id) return;
        $image_url = wp_get_attachment_image_url($image_id, 'medium');
        if (!$image_url) return;
        ?>
        <div class="gcp-preview-widget" id="gcp-preview-widget">
            <p class="gcp-preview-label">Preview your personalisation</p>
            <div class="gcp-preview-inner" id="gcp-preview-inner">
                <img src="<?php echo esc_url($image_url); ?>" alt="Case preview" loading="lazy">
                <div id="gcp-overlay-text" class="gcp-overlay-text" style="display:none;" aria-hidden="true"></div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function get_font_directory_url() {
        $path = WP_PLUGIN_DIR . '/galado-font-preview/fonts/';
        return is_dir($path) ? plugins_url('galado-font-preview/fonts/') : plugin_dir_url(__FILE__) . 'fonts/';
    }

    private function get_font_format($file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return array('ttf' => 'truetype', 'otf' => 'opentype', 'woff' => 'woff', 'woff2' => 'woff2')[$ext] ?? 'truetype';
    }

    private function get_font_slugs() {
        $slugs = array();
        foreach ($this->fonts as $name => $file) {
            $slugs[] = array('name' => $name, 'slug' => sanitize_title($name));
        }
        return $slugs;
    }
}

GALADO_Case_Preview::instance();
