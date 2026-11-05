<?php
/**
 * "Find your font": a public page where a customer types their name, sees it in
 * every lettering font, picks black or white and copies one line to paste to us
 * in chat, e.g. "Font Style : Bebas - Jasmine - Black".
 *
 * Shortcode: [galado_font_picker] on a page (galado.com.my/font/). Uses this
 * plugin's own font files and names, so what customers send matches the name
 * cases on the shop. Static: nothing is sent or stored; the customer's name is
 * only ever handled in their browser, as text.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Galado_Font_Picker_Page {

    const SHORTCODE = 'galado_font_picker';

    /** @var array name => file, from Galado_Font_Preview */
    private $fonts;

    public function __construct( array $fonts ) {
        $this->fonts = $fonts;
        add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp', array( $this, 'quiet_club_popup' ) );
    }

    /**
     * Customers come here from chat to do one thing, so the "Join the Club" popup and
     * its coin (GALADO Club Bridge, wp_footer at 40) stay off this page. Every other
     * page keeps them.
     */
    public function quiet_club_popup() {
        if ( $this->on_picker_page() ) {
            remove_action( 'wp_footer', array( 'Galado_Club_Bridge', 'join_popup' ), 40 );
        }
    }

    private function on_picker_page() {
        if ( ! is_singular() ) return false;
        $post = get_post();
        return $post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
    }

    public function enqueue_assets() {
        if ( ! $this->on_picker_page() ) return;

        $dir = plugin_dir_path( dirname( __FILE__ ) );
        $url = plugin_dir_url( dirname( __FILE__ ) );

        // Lettering fonts under their own family names, so nothing else on the
        // site can collide with them.
        $font_css = '';
        foreach ( $this->fonts as $name => $file ) {
            $format    = str_ends_with( $file, '.otf' ) ? 'opentype' : 'truetype';
            $font_css .= sprintf(
                "@font-face { font-family: 'GFP %s'; src: url('%s') format('%s'); font-display: block; }\n",
                $name,
                esc_url( $url . 'fonts/' . rawurlencode( $file ) ),
                $format
            );
        }

        wp_enqueue_style( 'galado-font-picker-type', 'https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=Inter:wght@400;500;600&display=swap', array(), null );
        wp_enqueue_style( 'galado-font-picker', $url . 'picker.css', array(), (string) filemtime( $dir . 'picker.css' ) );
        wp_add_inline_style( 'galado-font-picker', $font_css );
        wp_enqueue_script( 'galado-font-picker', $url . 'picker.js', array(), (string) filemtime( $dir . 'picker.js' ), true );
    }

    public function render() {
        $names = wp_json_encode( array_keys( $this->fonts ) );
        ob_start();
        ?>
        <div class="gfp-page" data-fonts="<?php echo esc_attr( $names ); ?>">
          <section class="gfp-intro" aria-labelledby="gfp-title">
            <p class="gfp-eyebrow">Name cases</p>
            <h1 id="gfp-title" class="gfp-title">Find the lettering that feels like you</h1>
            <p class="gfp-lede">Type your name to see it in all ten styles. Pick your favourite, choose black or white, then copy it and paste it to us in chat. A screenshot works too.</p>
          </section>

          <div class="gfp-controls">
            <div class="gfp-row">
              <div class="gfp-name-field">
                <label class="gfp-label" for="gfp-name">Your name</label>
                <input id="gfp-name" class="gfp-input" type="text" maxlength="30" placeholder="e.g. Jasmine" autocomplete="off" autocapitalize="words" spellcheck="false" enterkeyhint="done">
              </div>
              <fieldset class="gfp-colour">
                <legend class="gfp-label">Lettering colour</legend>
                <div class="gfp-seg">
                  <label class="gfp-seg-option"><input type="radio" name="gfp-colour" value="Black" checked><span style="--gfp-swatch:#111111">Black</span></label>
                  <label class="gfp-seg-option"><input type="radio" name="gfp-colour" value="White"><span style="--gfp-swatch:#FFFFFF">White</span></label>
                </div>
              </fieldset>
            </div>
          </div>

          <fieldset class="gfp-styles">
            <legend class="gfp-label">Pick a style</legend>
            <p class="gfp-hint">Tap the one you like best.</p>
            <div class="gfp-grid"></div>
          </fieldset>

          <p class="gfp-foot">Lettering is printed on your case exactly in the style you pick. Questions? Message us in the chat you came from.</p>

          <div class="gfp-pick" role="region" aria-label="Your pick">
            <div class="gfp-pick-inner">
              <div class="gfp-pick-preview" aria-hidden="true"></div>
              <div class="gfp-pick-text">
                <p class="gfp-pick-label">Your pick</p>
                <p class="gfp-pick-line is-waiting">Type your name to start</p>
              </div>
              <button type="button" class="gfp-btn" disabled>Copy</button>
            </div>
            <p class="gfp-status" role="status" aria-live="polite"></p>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
