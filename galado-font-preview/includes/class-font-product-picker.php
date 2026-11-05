<?php
/**
 * "Find your font" on a product page (dry run: Silver Mirror - Underside 301661, Clement 5 Nov 2026).
 *
 * Per-product switch _galado_font_picker: '' off, 'preview' (only on ?gfp=preview, for checking before launch),
 * 'yes' (every shopper). Separate from the older _galado_font_preview_enabled box, which keeps its own three
 * order rows (Quantum Guard - Underside 251808 stays on it, Clement's call).
 *
 * Customers never see a font name (Clement, 5 Nov: "No labels on customer end, they see and taps the picture"):
 * the page, cart, checkout, thank-you page, My Account, customer emails and the public config carry opaque font ids
 * and neutral copies of the font files (uploads/galado-font/<id>.<ext>; the originals stay as they are for /font/).
 * Where an order line is shown to the customer they get a PICTURE of their text in the chosen font and colour.
 * Staff surfaces (wp-admin, the admin new-order email, the store REST API) keep the row
 *   Font Style: Bebas - Jasmine - Black          (font - text - colour)
 * plus hidden _galado_font {"v":1,"id","font","text","colour"} and _galado_font_img (the picture's URL).
 * App orders arrive through the Club bridge with the row "Font Style: <id> - <text> - <colour>"; it is rewritten to
 * the real name when the item is saved, so web and app orders end up identical.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Galado_Font_Product_Picker {

    const META       = '_galado_font_picker';
    const ROW        = 'Font Style';
    const HIDDEN     = '_galado_font';
    const IMG        = '_galado_font_img';
    const CUSTOMER   = 'Your lettering';
    const MAX_LENGTH = 30;
    const COLOURS    = array( 'Black', 'White' );
    /** Make It Mine (WCPA form 226934) field labels the picker replaces; the app matches labels too. */
    const HIDE_WCPA_LABELS = array( 'Add text', 'Type your text', 'Text colour' );

    /** @var array name => file */
    private $fonts;
    /** @var array id => name */
    private $by_id = array();
    /** Set while WooCommerce renders an email: true for a customer email. */
    private $email_to_customer = null;

    public function __construct( array $fonts ) {
        $this->fonts = $fonts;
        foreach ( array_keys( $fonts ) as $name ) {
            $this->by_id[ self::id_for( $name ) ] = $name;
        }
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_meta_box' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ), 5 );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 2 );
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 10, 2 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'show_in_cart' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'write_web_item' ), 10, 3 );
        add_action( 'woocommerce_new_order_item', array( $this, 'complete_app_item' ), 10, 3 );
        add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'customer_view' ), 20, 2 );
        add_action( 'woocommerce_email_order_details', array( $this, 'email_start' ), 1, 4 );
        add_action( 'woocommerce_email_order_details', array( $this, 'email_end' ), 999 );
        add_action( 'rest_api_init', array( $this, 'routes' ) );
    }

    /** Stable opaque id for a font, e.g. "f3a9c1e"; never shown, never the name. */
    public static function id_for( $name ) {
        return 'f' . substr( md5( 'gfp|' . $name ), 0, 6 );
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
        echo '</select><p style="margin:6px 0 0;color:#666;font-size:11px;line-height:1.4">Replaces the Make It Mine text fields with the name-in-every-font picker. Customers see pictures only; staff see Font Style: font - text - colour.</p>';
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

    /** Font given as an opaque id (customers, app) or, for old rows and staff tools, by name. */
    private function font_name( $font ) {
        if ( isset( $this->by_id[ $font ] ) ) return $this->by_id[ $font ];
        return isset( $this->fonts[ $font ] ) ? $font : '';
    }

    /** @return array{id:string,font:string,text:string,colour:string}|WP_Error  Messages never name a font. */
    public function check( $font, $text, $colour ) {
        $name = $this->font_name( (string) $font );
        $text = self::clean_text( $text );
        if ( '' === $name ) return new WP_Error( 'gfp_font', 'Please pick a lettering style.' );
        if ( ! in_array( $colour, self::COLOURS, true ) ) return new WP_Error( 'gfp_colour', 'Please pick black or white lettering.' );
        if ( '' === $text ) return new WP_Error( 'gfp_text', 'Please type the text for your case.' );
        if ( mb_strlen( $text ) > self::MAX_LENGTH ) return new WP_Error( 'gfp_long', sprintf( 'Your text can be up to %d characters.', self::MAX_LENGTH ) );
        return array( 'id' => self::id_for( $name ), 'font' => $name, 'text' => $text, 'colour' => $colour );
    }

    /** The staff row: real font name. */
    public static function row( array $c ) {
        return $c['font'] . ' - ' . $c['text'] . ' - ' . $c['colour'];
    }

    /** Reads "<id or font> - text - colour" back (the text may itself contain " - "). */
    public function parse_row( $value ) {
        $value = (string) $value;
        foreach ( self::COLOURS as $colour ) {
            $tail = ' - ' . $colour;
            if ( substr( $value, -strlen( $tail ) ) !== $tail ) continue;
            $head = substr( $value, 0, -strlen( $tail ) );
            foreach ( array_merge( array_keys( $this->by_id ), array_keys( $this->fonts ) ) as $font ) {
                if ( 0 === strpos( $head, $font . ' - ' ) ) {
                    return $this->check( $font, substr( $head, strlen( $font ) + 3 ), $colour );
                }
            }
        }
        return new WP_Error( 'gfp_row', 'Unreadable Font Style row.' );
    }

    /* ---------- neutral font files and lettering pictures (uploads/galado-font/) ---------- */

    private function store( $sub = '' ) {
        $u = wp_upload_dir();
        return array( trailingslashit( $u['basedir'] ) . 'galado-font/' . $sub, trailingslashit( $u['baseurl'] ) . 'galado-font/' . $sub );
    }

    /** id => URL of a neutral copy of each font file (copied once; the originals are left for /font/). */
    public function font_urls() {
        list( $dir, $url ) = $this->store();
        wp_mkdir_p( $dir );
        $src = plugin_dir_path( dirname( __FILE__ ) ) . 'fonts/';
        $out = array();
        foreach ( $this->fonts as $name => $file ) {
            $id   = self::id_for( $name );
            $dest = $id . '.' . strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
            if ( ! file_exists( $dir . $dest ) || filesize( $dir . $dest ) !== filesize( $src . $file ) ) {
                @copy( $src . $file, $dir . $dest );
            }
            $out[ $id ] = $url . $dest;
        }
        return $out;
    }

    /** A PNG of the customer's text in the chosen font and colour, made once per (font, text, colour). */
    public function picture( array $c ) {
        list( $dir, $url ) = $this->store( 'l/' );
        $file = substr( hash( 'sha256', 'gfp|' . $c['id'] . '|' . $c['text'] . '|' . $c['colour'] ), 0, 24 ) . '.png';
        if ( file_exists( $dir . $file ) ) return $url . $file;
        $font = plugin_dir_path( dirname( __FILE__ ) ) . 'fonts/' . $this->fonts[ $c['font'] ];
        if ( ! function_exists( 'imagettfbbox' ) || ! is_readable( $font ) ) return '';
        wp_mkdir_p( $dir );
        $size = 56;
        $box  = imagettfbbox( $size, 0, $font, $c['text'] );
        if ( ! $box ) return '';
        $w   = max( $box[2], $box[4] ) - min( $box[0], $box[6] );
        $h   = max( $box[1], $box[3] ) - min( $box[5], $box[7] );
        $pad = 28;
        $im  = imagecreatetruecolor( $w + 2 * $pad, $h + 2 * $pad );
        $white = 'White' === $c['colour'];
        imagefill( $im, 0, 0, $white ? imagecolorallocate( $im, 58, 58, 58 ) : imagecolorallocate( $im, 255, 255, 255 ) );
        $ink = $white ? imagecolorallocate( $im, 255, 255, 255 ) : imagecolorallocate( $im, 17, 17, 17 );
        imagettftext( $im, $size, 0, $pad - min( $box[0], $box[6] ), $pad - min( $box[5], $box[7] ), $ink, $font, $c['text'] );
        $ok = imagepng( $im, $dir . $file, 6 );
        imagedestroy( $im );
        return $ok ? $url . $file : '';
    }

    private function picture_html( array $c ) {
        $src = $this->picture( $c );
        return $src ? '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $c['text'] ) . '" style="display:block;max-width:220px;height:auto;border-radius:8px;border:1px solid #e5e5e5;margin-top:4px">' : esc_html( $c['text'] );
    }

    /* ---------- product page ---------- */

    public function enqueue() {
        if ( ! is_product() ) return;
        $id = get_queried_object_id();
        if ( ! $id || ! $this->visible( $id ) ) return;
        $dir = plugin_dir_path( dirname( __FILE__ ) );
        $url = plugin_dir_url( dirname( __FILE__ ) );
        $css = '';
        foreach ( $this->font_urls() as $fid => $furl ) {
            $css .= sprintf( "@font-face{font-family:'gfpp-%s';src:url('%s');font-display:swap;}\n", $fid, esc_url( $furl ) );
        }
        wp_enqueue_style( 'galado-font-product-picker', $url . 'product-picker.css', array(), (string) filemtime( $dir . 'product-picker.css' ) );
        wp_add_inline_style( 'galado-font-product-picker', $css );
        wp_enqueue_script( 'galado-font-product-picker', $url . 'product-picker.js', array(), (string) filemtime( $dir . 'product-picker.js' ), true );
    }

    public function render() {
        global $product;
        if ( ! $product || ! $this->visible( $product->get_id() ) ) return;
        $cfg = array( 'fonts' => array_keys( $this->by_id ), 'max' => self::MAX_LENGTH, 'hide' => self::HIDE_WCPA_LABELS );
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
              <div class="gfpp-grid" role="radiogroup" aria-label="Lettering style"></div>
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

    /** Cart and checkout are customer surfaces: the picture, never the font name. */
    public function show_in_cart( $item_data, $cart_item ) {
        if ( ! empty( $cart_item['galado_font_pick'] ) ) {
            $c = $cart_item['galado_font_pick'];
            $item_data[] = array( 'key' => self::CUSTOMER, 'value' => $c['text'] . ' (' . $c['colour'] . ')', 'display' => $this->picture_html( $c ) );
        }
        return $item_data;
    }

    /** Sets the staff row, the hidden copy and the picture on the item object; WooCommerce's own save writes them.
        (woocommerce_new_order_item fires before the item's meta is saved, so writing to the table here would leave a
        second "Font Style" row.) */
    private function write( WC_Order_Item_Product $item, array $c ) {
        $item->update_meta_data( self::ROW, self::row( $c ) );
        $item->update_meta_data( self::HIDDEN, wp_json_encode( array( 'v' => 1, 'id' => $c['id'], 'font' => $c['font'], 'text' => $c['text'], 'colour' => $c['colour'] ) ) );
        $img = $this->picture( $c );
        if ( $img ) $item->update_meta_data( self::IMG, $img );
    }

    public function write_web_item( $item, $cart_item_key, $values ) {
        if ( ! empty( $values['galado_font_pick'] ) ) $this->write( $item, $values['galado_font_pick'] );
    }

    /* ---------- app orders (Club bridge app-order): "<id> - text - colour" becomes the staff row ---------- */

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
        $this->write( $item, $c );
    }

    /* ---------- who is looking at an order line ---------- */

    public function email_start( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
        $this->email_to_customer = ! $sent_to_admin;
    }

    public function email_end() {
        $this->email_to_customer = null;
    }

    /** Customer surfaces: customer emails, and front-end pages (thank-you, My Account). Everything else is staff. */
    private function customer_is_looking() {
        if ( null !== $this->email_to_customer ) return $this->email_to_customer;
        if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) return false;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            // Shopper-facing APIs (Store API, the Club API the app uses) are customer surfaces; the authenticated
            // store API (wc/v3, read by CP and the order tools) is staff.
            $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
            return false !== strpos( $uri, '/wc/store' ) || false !== strpos( $uri, '/galado-club/' );
        }
        return true;
    }

    public function customer_view( $formatted, $item ) {
        if ( ! $this->customer_is_looking() ) return $formatted;
        foreach ( $formatted as $meta_id => $m ) {
            if ( self::ROW !== $m->key ) continue;
            $c = $this->parse_row( $m->value );
            if ( is_wp_error( $c ) ) { unset( $formatted[ $meta_id ] ); continue; }
            $formatted[ $meta_id ] = (object) array( 'key' => self::CUSTOMER, 'value' => $c['text'], 'display_key' => self::CUSTOMER,
                'display_value' => $this->picture_html( $c ) );
        }
        return $formatted;
    }

    /* ---------- config for the app: ids and neutral file URLs only ---------- */

    public function routes() {
        register_rest_route( 'galado-font/v1', '/config', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array( $this, 'config' ),
        ) );
    }

    public function config( WP_REST_Request $req ) {
        $fonts = array();
        foreach ( $this->font_urls() as $fid => $furl ) {
            $fonts[] = array( 'id' => $fid, 'url' => $furl );
        }
        $modes = 'preview' === $req->get_param( 'include' ) ? array( 'yes', 'preview' ) : array( 'yes' );
        $ids   = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 200,
            'meta_query' => array( array( 'key' => self::META, 'value' => $modes, 'compare' => 'IN' ) ) ) );
        $products = array();
        foreach ( $ids as $id ) {
            $products[ (string) $id ] = array( 'fonts' => array_keys( $this->by_id ), 'default' => self::id_for( Galado_Font_Preview::DEFAULT_FONT ),
                'hide_wcpa_labels' => self::HIDE_WCPA_LABELS, 'mode' => $this->mode( $id ) );
        }
        // (object): an empty list must stay {} for the app, never [].
        $res = new WP_REST_Response( array( 'version' => 2, 'fonts' => $fonts, 'colours' => self::COLOURS,
            'max_length' => self::MAX_LENGTH, 'row_label' => self::ROW, 'products' => (object) $products ) );
        $res->header( 'Cache-Control', 'public, max-age=300' );
        return $res;
    }
}
