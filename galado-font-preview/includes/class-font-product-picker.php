<?php
/**
 * "Find your font" on a product page (dry run: Silver Mirror - Underside 301661, Clement 5 Nov 2026).
 *
 * Per-product switch _galado_font_picker: '' off, 'preview' (only on ?gfp=preview, for checking before launch),
 * 'yes' (every shopper). Separate from the older _galado_font_preview_enabled box, which keeps its own three
 * order rows (Quantum Guard - Underside 251808 stays on it, Clement's call).
 *
 * On a picker product the customer ticks "Add text", types up to 30 characters, picks Black or White and taps a
 * style; the Make It Mine (WCPA) text fields are hidden. The order line gets ONE visible row
 *   Font Style: Bebas - Jasmine - Black          (font - text - colour)
 * plus a hidden _galado_font {"v":1,"font","text","colour"} for CP and scripts. App orders arrive through the Club
 * bridge with the same visible row; the hidden copy is added server-side when that item is saved, so web and app
 * orders end up identical. The app reads GET /wp-json/galado-font/v1/config (fonts, colours, length, products).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Galado_Font_Product_Picker {

    const META       = '_galado_font_picker';
    const ROW        = 'Font Style';
    const HIDDEN     = '_galado_font';
    const MAX_LENGTH = 30;
    const COLOURS    = array( 'Black', 'White' );
    /** Make It Mine (WCPA form 226934) field labels the picker replaces; the app matches labels too. */
    const HIDE_WCPA_LABELS = array( 'Add text', 'Type your text', 'Text colour' );

    /** @var array name => file */
    private $fonts;

    public function __construct( array $fonts ) {
        $this->fonts = $fonts;
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_meta_box' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ), 5 );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 2 );
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 10, 2 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'show_in_cart' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'write_web_item' ), 10, 3 );
        add_action( 'woocommerce_new_order_item', array( $this, 'complete_app_item' ), 10, 3 );
        add_action( 'rest_api_init', array( $this, 'routes' ) );
    }

    /* ---------- the switch ---------- */

    private function mode( $product_id ) {
        $m = get_post_meta( (int) $product_id, self::META, true );
        return in_array( $m, array( 'preview', 'yes' ), true ) ? $m : '';
    }

    /** Shown to this visitor? 'yes' always; 'preview' only on ?gfp=preview (query URLs are never page-cached). */
    private function visible( $product_id ) {
        $m = $this->mode( $product_id );
        return 'yes' === $m || ( 'preview' === $m && isset( $_GET['gfp'] ) && 'preview' === $_GET['gfp'] );
    }

    public function add_meta_box() {
        add_meta_box( 'galado_font_picker_meta', 'GALADO Find your font', array( $this, 'render_meta_box' ), 'product', 'side', 'high' );
    }

    public function render_meta_box( $post ) {
        $m = $this->mode( $post->ID );
        wp_nonce_field( 'galado_font_picker', 'galado_font_picker_nonce' );
        echo '<select name="' . esc_attr( self::META ) . '" style="width:100%">';
        foreach ( array( '' => 'Off', 'preview' => 'Preview only (?gfp=preview)', 'yes' => 'On for every shopper' ) as $k => $label ) {
            echo '<option value="' . esc_attr( $k ) . '"' . selected( $m, $k, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><p style="margin:6px 0 0;color:#666;font-size:11px;line-height:1.4">Replaces the Make It Mine text fields with the name-in-every-font picker. Orders get one line: Font Style: font - text - colour.</p>';
    }

    public function save_meta_box( $post_id ) {
        if ( empty( $_POST['galado_font_picker_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['galado_font_picker_nonce'] ) ), 'galado_font_picker' ) ) return;
        $v = isset( $_POST[ self::META ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META ] ) ) : '';
        update_post_meta( $post_id, self::META, in_array( $v, array( 'preview', 'yes' ), true ) ? $v : '' );
    }

    /* ---------- one set of rules for web and app ---------- */

    /** Trims, folds spaces and strips emoji; returns '' when nothing printable is left. */
    public static function clean_text( $text ) {
        $t = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{E0020}-\x{E007F}]/u', '', (string) $text );
        return trim( preg_replace( '/\s+/u', ' ', (string) $t ) );
    }

    /** @return array{font:string,text:string,colour:string}|WP_Error */
    public function check( $font, $text, $colour ) {
        $text = self::clean_text( $text );
        if ( ! isset( $this->fonts[ $font ] ) ) return new WP_Error( 'gfp_font', 'Please pick a font style.' );
        if ( ! in_array( $colour, self::COLOURS, true ) ) return new WP_Error( 'gfp_colour', 'Please pick black or white lettering.' );
        if ( '' === $text ) return new WP_Error( 'gfp_text', 'Please type the text for your case.' );
        if ( mb_strlen( $text ) > self::MAX_LENGTH ) return new WP_Error( 'gfp_long', sprintf( 'Your text can be up to %d characters.', self::MAX_LENGTH ) );
        return array( 'font' => $font, 'text' => $text, 'colour' => $colour );
    }

    public static function row( array $c ) {
        return $c['font'] . ' - ' . $c['text'] . ' - ' . $c['colour'];
    }

    /** Reads "font - text - colour" back (the text may itself contain " - "). */
    public function parse_row( $value ) {
        $value = (string) $value;
        foreach ( self::COLOURS as $colour ) {
            $tail = ' - ' . $colour;
            if ( substr( $value, -strlen( $tail ) ) !== $tail ) continue;
            $head = substr( $value, 0, -strlen( $tail ) );
            foreach ( array_keys( $this->fonts ) as $font ) {
                if ( 0 === strpos( $head, $font . ' - ' ) ) {
                    return $this->check( $font, substr( $head, strlen( $font ) + 3 ), $colour );
                }
            }
        }
        return new WP_Error( 'gfp_row', 'Unreadable Font Style row.' );
    }

    /* ---------- product page ---------- */

    public function enqueue() {
        if ( ! is_product() ) return;
        $id = get_queried_object_id();
        if ( ! $id || ! $this->visible( $id ) ) return;
        $dir = plugin_dir_path( dirname( __FILE__ ) );
        $url = plugin_dir_url( dirname( __FILE__ ) );
        $css = '';
        foreach ( $this->fonts as $name => $file ) {
            $css .= sprintf( "@font-face{font-family:'GFP %s';src:url('%s') format('%s');font-display:swap;}\n",
                $name, esc_url( $url . 'fonts/' . rawurlencode( $file ) ), str_ends_with( $file, '.otf' ) ? 'opentype' : 'truetype' );
        }
        wp_enqueue_style( 'galado-font-product-picker', $url . 'product-picker.css', array(), (string) filemtime( $dir . 'product-picker.css' ) );
        wp_add_inline_style( 'galado-font-product-picker', $css );
        wp_enqueue_script( 'galado-font-product-picker', $url . 'product-picker.js', array(), (string) filemtime( $dir . 'product-picker.js' ), true );
    }

    public function render() {
        global $product;
        if ( ! $product || ! $this->visible( $product->get_id() ) ) return;
        $cfg = array( 'fonts' => array_keys( $this->fonts ), 'max' => self::MAX_LENGTH, 'hide' => self::HIDE_WCPA_LABELS );
        ?>
        <div class="gfpp" data-gfpp="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>">
          <label class="gfpp-toggle"><input type="checkbox" name="gfpp_on" value="1"> <span class="gfpp-toggle-t">Add text</span> <span class="gfpp-free">Free</span></label>
          <div class="gfpp-body" hidden>
            <label class="gfpp-label" for="gfpp-text">Your text</label>
            <input id="gfpp-text" class="gfpp-input" type="text" name="gfpp_text" maxlength="<?php echo (int) self::MAX_LENGTH; ?>" placeholder="e.g. Jasmine" autocomplete="off" autocapitalize="words" spellcheck="false">
            <fieldset class="gfpp-colour"><legend class="gfpp-label">Lettering colour</legend>
              <label><input type="radio" name="gfpp_colour" value="Black" checked><span class="gfpp-sw gfpp-sw-black"></span>Black</label>
              <label><input type="radio" name="gfpp_colour" value="White"><span class="gfpp-sw gfpp-sw-white"></span>White</label>
            </fieldset>
            <fieldset class="gfpp-styles"><legend class="gfpp-label">Pick a style</legend>
              <div class="gfpp-grid" role="radiogroup" aria-label="Font style"></div>
            </fieldset>
            <input type="hidden" name="gfpp_font" value="">
            <p class="gfpp-pick" aria-live="polite"></p>
          </div>
        </div>
        <?php
    }

    /* ---------- cart and order (web) ---------- */

    private function posted() {
        if ( empty( $_POST['gfpp_on'] ) ) return null;
        return $this->check(
            sanitize_text_field( wp_unslash( $_POST['gfpp_font'] ?? '' ) ),
            wp_unslash( $_POST['gfpp_text'] ?? '' ),
            sanitize_text_field( wp_unslash( $_POST['gfpp_colour'] ?? '' ) )
        );
    }

    public function validate( $passed, $product_id ) {
        if ( ! $this->mode( $product_id ) ) return $passed;
        $c = $this->posted();
        if ( is_wp_error( $c ) ) {
            wc_add_notice( $c->get_error_message(), 'error' );
            return false;
        }
        return $passed;
    }

    public function cart_item_data( $data, $product_id ) {
        if ( ! $this->mode( $product_id ) ) return $data;
        $c = $this->posted();
        if ( is_array( $c ) ) {
            $data['galado_font_pick'] = $c;
            $data['unique_key']       = md5( microtime() . wp_rand() );
        }
        return $data;
    }

    public function show_in_cart( $item_data, $cart_item ) {
        if ( ! empty( $cart_item['galado_font_pick'] ) ) {
            $item_data[] = array( 'key' => self::ROW, 'value' => self::row( $cart_item['galado_font_pick'] ) );
        }
        return $item_data;
    }

    public function write_web_item( $item, $cart_item_key, $values ) {
        if ( empty( $values['galado_font_pick'] ) ) return;
        $c = $values['galado_font_pick'];
        $item->add_meta_data( self::ROW, self::row( $c ), true );
        $item->add_meta_data( self::HIDDEN, wp_json_encode( array( 'v' => 1 ) + $c ), true );
    }

    /* ---------- app orders (Club bridge app-order): add the hidden copy from the visible row ---------- */

    public function complete_app_item( $item_id, $item, $order_id ) {
        if ( ! $item instanceof WC_Order_Item_Product || ! $this->mode( $item->get_product_id() ) ) return;
        if ( '' !== (string) $item->get_meta( self::HIDDEN ) ) return;
        $row = (string) $item->get_meta( self::ROW );
        if ( '' === $row ) return;
        $c = $this->parse_row( $row );
        if ( is_wp_error( $c ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) $order->add_order_note( 'Font Style row could not be read: ' . $row );
            return;
        }
        wc_update_order_item_meta( $item_id, self::HIDDEN, wp_json_encode( array( 'v' => 1 ) + $c ) );
    }

    /* ---------- config for the app ---------- */

    public function routes() {
        register_rest_route( 'galado-font/v1', '/config', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array( $this, 'config' ),
        ) );
    }

    public function config( WP_REST_Request $req ) {
        $url   = plugin_dir_url( dirname( __FILE__ ) );
        $fonts = array();
        foreach ( $this->fonts as $name => $file ) {
            $fonts[] = array( 'name' => $name, 'url' => $url . 'fonts/' . rawurlencode( $file ) );
        }
        $modes = 'preview' === $req->get_param( 'include' ) ? array( 'yes', 'preview' ) : array( 'yes' );
        $ids   = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 200,
            'meta_query' => array( array( 'key' => self::META, 'value' => $modes, 'compare' => 'IN' ) ) ) );
        $products = array();
        foreach ( $ids as $id ) {
            $products[ (string) $id ] = array( 'fonts' => array_keys( $this->fonts ), 'default' => Galado_Font_Preview::DEFAULT_FONT,
                'hide_wcpa_labels' => self::HIDE_WCPA_LABELS, 'mode' => $this->mode( $id ) );
        }
        $res = new WP_REST_Response( array( 'version' => 1, 'fonts' => $fonts, 'colours' => self::COLOURS,
            'max_length' => self::MAX_LENGTH, 'row_label' => self::ROW, 'products' => (object) $products ) );
        $res->header( 'Cache-Control', 'public, max-age=300' );
        return $res;
    }
}
