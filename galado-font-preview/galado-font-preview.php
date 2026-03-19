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
    );

    public function __construct() {
        // Admin: Add product setting
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_product_option' ) );
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
     * ── ADMIN: Product Settings ──
     */
    public function add_product_option() {
        woocommerce_wp_checkbox( array(
            'id'          => '_galado_font_preview_enabled',
            'label'       => 'GALADO Font Preview',
            'description' => 'Enable the font style preview selector on this product page.',
        ) );
    }

    public function save_product_option( $post_id ) {
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
            <h4 class="galado-fp-title">Personalise Your Case</h4>
            <p class="galado-fp-subtitle">Type your name below and select a font style</p>

            <div class="galado-fp-input-wrap">
                <input type="text"
                       id="galado-fp-text"
                       name="galado_font_text"
                       class="galado-fp-input"
                       placeholder="Type your name here..."
                       maxlength="30"
                       required>
            </div>

            <div class="galado-fp-grid" id="galado-fp-grid">
                <div class="galado-fp-placeholder">Type a name above to see font previews</div>
            </div>

            <input type="hidden" name="galado_font_name" id="galado-fp-selected" value="">

            <div class="galado-fp-badge" id="galado-fp-badge" style="display:none;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;color:#16a34a;"><polyline points="20 6 9 17 4 12"/></svg>
                Selected: <strong id="galado-fp-badge-name"></strong>
            </div>
        </div>
        <?php
    }

    /**
     * ── Validate: Require font selection before add to cart ──
     */
    public function validate_add_to_cart( $passed, $product_id, $quantity ) {
        if ( ! $this->is_enabled( $product_id ) ) return $passed;

        if ( empty( $_POST['galado_font_name'] ) || empty( $_POST['galado_font_text'] ) ) {
            wc_add_notice( 'Please type your name and select a font style before adding to cart.', 'error' );
            return false;
        }
        return $passed;
    }

    /**
     * ── Save font data to cart item ──
     */
    public function add_cart_item_data( $cart_item_data, $product_id ) {
        if ( ! $this->is_enabled( $product_id ) ) return $cart_item_data;

        if ( ! empty( $_POST['galado_font_name'] ) ) {
            $cart_item_data['galado_font_name'] = sanitize_text_field( $_POST['galado_font_name'] );
        }
        if ( ! empty( $_POST['galado_font_text'] ) ) {
            $cart_item_data['galado_font_text'] = sanitize_text_field( $_POST['galado_font_text'] );
        }
        // Make cart item unique so same product with different fonts creates separate lines
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
