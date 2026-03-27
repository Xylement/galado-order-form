<?php
/**
 * Plugin Name: GALADO Font Preview
 * Description: Adds a live font preview selector to selected WooCommerce products. Customers type their name, see it in custom fonts, and tap to select.
 * Version: 1.0.0
 * Author: GALADO
 * Requires Plugins: woocommerce
 * Text Domain: galado-font-preview
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Galado_Font_Preview {

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

    public function __construct() {
        // Admin: Add sidebar meta box on product edit page
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_option' ) );

        // Frontend: Display on product page
        add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'display_font_preview' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Validate before add to cart
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );

        // Save custom data to cart
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );

        // Display in cart and checkout
        add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

        // Save to order meta
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

        // Display in admin order
        add_action( 'woocommerce_admin_order_item_headers', array( $this, 'admin_order_item_headers' ) );
    }

    /**
     * ── ADMIN: Sidebar Meta Box ──
     */
    public function add_meta_box() {
        add_meta_box(
            'galado_font_preview_meta',
            'GALADO Font Preview',
            array( $this, 'render_meta_box' ),
            'product',
            'side',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        $enabled = get_post_meta( $post->ID, '_galado_font_preview_enabled', true );
        wp_nonce_field( 'galado_font_preview_nonce', 'galado_font_preview_nonce_field' );
        ?>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:4px 0;">
            <input type="checkbox" name="_galado_font_preview_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> style="width:18px;height:18px;">
            <span style="font-size:13px;">Enable font style preview on this product</span>
        </label>
        <p style="margin:8px 0 0;color:#666;font-size:12px;">
            When enabled, customers can type their name and select a font style before adding to cart.
        </p>
        <?php
    }

    public function save_product_option( $post_id ) {
        if ( ! isset( $_POST['galado_font_preview_nonce_field'] ) ||
             ! wp_verify_nonce( $_POST['galado_font_preview_nonce_field'], 'galado_font_preview_nonce' ) ) {
            return;
        }
        $enabled = isset( $_POST['_galado_font_preview_enabled'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_galado_font_preview_enabled', $enabled );
    }

    /**
     * ── Check if font preview is enabled for a product ──
     */
    private function is_enabled( $product_id = null ) {
        if ( ! $product_id ) {
            global $product;
            $product_id = $product ? $product->get_id() : 0;
        }
        return get_post_meta( $product_id, '_galado_font_preview_enabled', true ) === 'yes';
    }

    /**
     * ── FRONTEND: Enqueue CSS & JS ──
     */
    public function enqueue_assets() {
        if ( ! is_product() ) return;

        global $product;
        if ( ! $this->is_enabled( $product ? $product->get_id() : 0 ) ) return;

        $plugin_url = plugin_dir_url( __FILE__ );

        // Register custom font faces via inline CSS
        $font_css = '';
        foreach ( $this->fonts as $name => $file ) {
            $url    = $plugin_url . 'fonts/' . rawurlencode( $file );
            $format = str_ends_with( $file, '.otf' ) ? 'opentype' : 'truetype';
            $font_css .= "@font-face { font-family: '{$name}'; src: url('{$url}') format('{$format}'); font-display: swap; }\n";
        }

        wp_enqueue_style( 'galado-font-preview', $plugin_url . 'style.css', array(), '1.0.0' );
        wp_add_inline_style( 'galado-font-preview', $font_css );
        wp_enqueue_script( 'galado-font-preview', $plugin_url . 'script.js', array( 'jquery' ), '1.0.0', true );

        // Pass font list to JS
        wp_localize_script( 'galado-font-preview', 'galadoFonts', array(
            'fonts' => array_keys( $this->fonts ),
        ) );
    }

    /**
     * ── FRONTEND: Display font preview on product page ──
     */
    public function display_font_preview() {
        global $product;
        if ( ! $this->is_enabled( $product->get_id() ) ) return;
        ?>
        <div class="galado-font-preview-wrap">
            <!-- Personalisation Checkbox -->
            <label class="galado-fp-toggle" for="galado-fp-enable">
                <input type="checkbox" id="galado-fp-enable" name="galado_personalise" value="yes">
                <span class="galado-fp-toggle-box"></span>
                <span class="galado-fp-toggle-label">
                    <strong>Add Personalisation</strong>
                    <small>Customise with your name and font style</small>
                </span>
            </label>

            <!-- Personalisation Fields (hidden until checkbox ticked) -->
            <div class="galado-fp-fields" id="galado-fp-fields" style="display:none;">
                <div class="galado-fp-input-wrap">
                    <label class="galado-fp-field-label" for="galado-fp-text">Your Text</label>
                    <input type="text"
                           id="galado-fp-text"
                           name="galado_font_text"
                           class="galado-fp-input"
                           placeholder="Type your name or text here..."
                           maxlength="30">
                </div>

                <div class="galado-fp-input-wrap">
                    <label class="galado-fp-field-label">Font Colour</label>
                    <div class="galado-fp-color-options">
                        <label class="galado-fp-color-option">
                            <input type="radio" name="galado_font_color" value="Black">
                            <span class="galado-fp-color-swatch galado-fp-swatch-black"></span>
                            <span>Black</span>
                        </label>
                        <label class="galado-fp-color-option">
                            <input type="radio" name="galado_font_color" value="White">
                            <span class="galado-fp-color-swatch galado-fp-swatch-white"></span>
                            <span>White</span>
                        </label>
                    </div>
                </div>

                <?php do_action('galado_fp_after_colour'); ?>

                <div class="galado-fp-input-wrap">
                    <label class="galado-fp-field-label">Select Font Style</label>
                    <div class="galado-fp-grid" id="galado-fp-grid">
                        <div class="galado-fp-placeholder">Type your text above to see font previews</div>
                    </div>
                </div>

                <input type="hidden" name="galado_font_name" id="galado-fp-selected" value="">

                <div class="galado-fp-badge" id="galado-fp-badge" style="display:none;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;color:#16a34a;"><polyline points="20 6 9 17 4 12"/></svg>
                    Selected: <strong id="galado-fp-badge-name"></strong>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * ── Validate: Require font selection before add to cart ──
     */
    public function validate_add_to_cart( $passed, $product_id, $quantity ) {
        if ( ! $this->is_enabled( $product_id ) ) return $passed;

        // If personalisation not selected, allow through
        if ( empty( $_POST['galado_personalise'] ) ) return $passed;

        // Personalisation is selected — all fields required
        $errors = array();
        if ( empty( $_POST['galado_font_text'] ) ) {
            $errors[] = 'your text';
        }
        if ( empty( $_POST['galado_font_color'] ) ) {
            $errors[] = 'a font colour';
        }
        if ( empty( $_POST['galado_font_name'] ) ) {
            $errors[] = 'a font style';
        }

        if ( ! empty( $errors ) ) {
            wc_add_notice( 'Please enter ' . implode( ', ', $errors ) . ' to personalise your case.', 'error' );
            return false;
        }
        return $passed;
    }

    /**
     * ── Save font data to cart item ──
     */
    public function add_cart_item_data( $cart_item_data, $product_id ) {
        if ( ! $this->is_enabled( $product_id ) ) return $cart_item_data;

        // Only save if personalisation is selected
        if ( empty( $_POST['galado_personalise'] ) ) return $cart_item_data;

        if ( ! empty( $_POST['galado_font_name'] ) ) {
            $cart_item_data['galado_font_name'] = sanitize_text_field( $_POST['galado_font_name'] );
        }
        if ( ! empty( $_POST['galado_font_text'] ) ) {
            $cart_item_data['galado_font_text'] = sanitize_text_field( $_POST['galado_font_text'] );
        }
        if ( ! empty( $_POST['galado_font_color'] ) ) {
            $cart_item_data['galado_font_color'] = sanitize_text_field( $_POST['galado_font_color'] );
        }
        // Make cart item unique
        $cart_item_data['unique_key'] = md5( microtime() . rand() );
        return $cart_item_data;
    }

    /**
     * ── Display font data in cart & checkout ──
     */
    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( ! empty( $cart_item['galado_font_text'] ) ) {
            $item_data[] = array(
                'key'   => 'Personalised Text',
                'value' => $cart_item['galado_font_text'],
            );
        }
        if ( ! empty( $cart_item['galado_font_name'] ) ) {
            $item_data[] = array(
                'key'   => 'Font Style',
                'value' => $cart_item['galado_font_name'],
            );
        }
        if ( ! empty( $cart_item['galado_font_color'] ) ) {
            $item_data[] = array(
                'key'   => 'Font Colour',
                'value' => $cart_item['galado_font_color'],
            );
        }
        return $item_data;
    }

    /**
     * ── Save to order line item meta ──
     */
    public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( ! empty( $values['galado_font_text'] ) ) {
            $item->add_meta_data( 'Personalised Text', $values['galado_font_text'], true );
        }
        if ( ! empty( $values['galado_font_name'] ) ) {
            $item->add_meta_data( 'Font Style', $values['galado_font_name'], true );
        }
        if ( ! empty( $values['galado_font_color'] ) ) {
            $item->add_meta_data( 'Font Colour', $values['galado_font_color'], true );
        }
    }

    /**
     * ── Admin order: show custom data header ──
     */
    public function admin_order_item_headers() {
        // Meta is shown automatically by WooCommerce in order items
    }
}

// Initialize
new Galado_Font_Preview();
