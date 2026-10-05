<?php
/**
 * The /studio/ page glue: mounts the frontend bundle and injects its config,
 * including the signed wp_claim that upgrades a logged-in customer to the
 * 6/day quota tier without studio-api ever reading WP cookies.
 *
 * Usage: create a WP page with the configured slug (default "studio") and
 * place [galado_studio] in it (template: full width). Assets are self-hosted
 * from this plugin (site discipline: no CDNs).
 */

if (!defined('ABSPATH')) exit;

class GSTUDIO_Page {

    public static function init() {
        add_shortcode('galado_studio', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_notices', [__CLASS__, 'hidden_models_notice']);
    }

    private static function is_studio_page() {
        $slug = gstudio_settings()['page_slug'];
        return is_page($slug);
    }

    public static function assets() {
        if (!self::is_studio_page()) return;

        wp_enqueue_style('gstudio', GSTUDIO_URL . 'public/studio.css', [], GSTUDIO_VERSION);
        wp_enqueue_script('gstudio', GSTUDIO_URL . 'public/studio.js', [], GSTUDIO_VERSION, true);
        wp_localize_script('gstudio', 'GSTUDIO_CFG', self::config());
        // The designer IS the Studio experience (v1 AI flow retired 18 Jul).
        wp_enqueue_script('gstudio-fabric', GSTUDIO_URL . 'public/vendor/fabric.min.js', [], GSTUDIO_VERSION, true);
        wp_enqueue_script('gstudio-designer', GSTUDIO_URL . 'public/designer.js', ['gstudio-fabric', 'gstudio'], GSTUDIO_VERSION, true);

        // Cloudflare Turnstile is the one allowed third-party script (bot
        // gate, spec section 3); loaded only on this page.
        wp_enqueue_script('cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, true);
    }

    public static function config() {
        $settings = gstudio_settings();
        $claim = '';
        if (is_user_logged_in() && gstudio_secret() !== '') {
            $claim = GSTUDIO_Token::sign(
                ['t' => 'wp', 'user_id' => get_current_user_id(), 'exp' => time() + 2 * HOUR_IN_SECONDS],
                gstudio_secret()
            );
        }

        return [
            'ver'        => GSTUDIO_VERSION,
            'api'        => gstudio_api_base(),
            'cart_url'   => esc_url_raw(rest_url('galado-studio/v1/cart')),
            // Used only when the designer is running inside the GALADO iOS app.
            'app_line'   => esc_url_raw(rest_url('galado-studio/v1/app-line')),
            'sitekey'    => (string) $settings['turnstile_sitekey'],
            'wp_claim'   => $claim,
            'logged_in'  => is_user_logged_in(),
            'login_url'  => wp_login_url(home_url('/' . $settings['page_slug'] . '/')),
            'models'     => self::models(),
            // Lettering previews reuse the exact font files the name-case
            // product already serves (galado-font-preview), loaded lazily.
            'fonts_base' => esc_url_raw(plugins_url('galado-font-preview/fonts/')),
            // Real product mock frames per model (from the Master Mock pass):
            // model_id -> { file, plate_pct [left, top, width, height] }.
            // Models without an entry fall back to the CSS case preview.
            'mocks_base' => esc_url_raw(GSTUDIO_URL . 'public/mocks/'),
            'mocks'      => self::mocks(),
        ];
    }

    /** Mock-frame manifest baked by the measurement pass (public/mocks/mocks.json). */
    public static function mocks() {
        $path = GSTUDIO_PATH . 'public/mocks/mocks.json';
        if (!file_exists($path)) return [];
        $data = json_decode((string) file_get_contents($path), true);
        return isset($data['mocks']) && is_array($data['mocks']) ? $data['mocks'] : [];
    }

    /** Model list for the picker: the Studio Case product's phones that the
     * Studio server can print. A phone put on sale before its print template
     * reaches the server is left out instead of failing every design at Looks
     * Good (live 2026-09-23 to 10-05: iPhone 18 Pro and 18 Pro Max). */
    public static function models() {
        $printable = self::api_models();
        $models = self::product_models();
        if (null === $printable) return $models;
        return array_values(array_filter($models, function ($m) use ($printable) {
            return in_array($m['model_id'], $printable, true);
        }));
    }

    /** Phones on sale in the Studio Case product that the picker hides. */
    public static function hidden_models() {
        $printable = self::api_models();
        if (null === $printable) return [];
        return array_values(array_filter(self::product_models(), function ($m) use ($printable) {
            return !in_array($m['model_id'], $printable, true);
        }));
    }

    /** The phones the Studio server can print (GET /v1/models), cached for ten
     * minutes. The last good answer stays as the fallback, so a slow or
     * unreachable server never puts a hidden phone back in the picker. Null
     * only before the first good answer ever: the picker is then unfiltered. */
    public static function api_models() {
        $cached = get_transient('gstudio_api_models');
        if (false !== $cached) return is_array($cached) ? $cached : null;

        // Short timeout: this runs while the Studio page renders.
        $res = wp_remote_get(gstudio_api_base() . '/v1/models', ['timeout' => 2]);
        $body = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
        $ids = (is_array($body) && isset($body['models']) && is_array($body['models']))
            ? array_values(array_filter($body['models'], function ($id) {
                return is_string($id) && 1 === preg_match('/^[a-z0-9-]+$/', $id);
            }))
            : [];
        if (200 === (int) wp_remote_retrieve_response_code($res) && $ids) {
            set_transient('gstudio_api_models', $ids, 10 * MINUTE_IN_SECONDS);
            update_option('gstudio_api_models', $ids, false);
            return $ids;
        }

        $why = is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($res);
        error_log('GALADO Studio: model list unavailable (' . $why . '), keeping the last good one');
        $last = get_option('gstudio_api_models');
        $last = is_array($last) && $last ? $last : null;
        // Ask again in a minute, not on every page view.
        set_transient('gstudio_api_models', $last ?: 'unknown', MINUTE_IN_SECONDS);
        return $last;
    }

    /** Staff note on the Studio Case product screen naming the phones the
     * picker hides, so a new phone that "does not show up" explains itself. */
    public static function hidden_models_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $product_id = (int) gstudio_settings()['product_id'];
        if (!$screen || 'product' !== $screen->id || !$product_id) return;
        if ($product_id !== absint($_GET['post'] ?? 0)) return; // phpcs:ignore WordPress.Security.NonceVerification
        $hidden = self::hidden_models();
        if (!$hidden) return;
        $names = implode(', ', array_column($hidden, 'label'));
        $one = 1 === count($hidden);
        printf(
            '<div class="notice notice-warning"><p><strong>Studio:</strong> %s</p></div>',
            esc_html(sprintf(
                '%1$s %2$s hidden from customers in the Studio. The Studio server has no print template for %3$s yet, so %4$s designs could not be printed. %5$s in the Studio by %6$s within about ten minutes of the template going live on the Studio server.',
                $names,
                $one ? 'is' : 'are',
                $one ? 'this phone' : 'these phones',
                $one ? 'its' : 'their',
                $one ? 'It appears' : 'They appear',
                $one ? 'itself' : 'themselves'
            ))
        );
    }

    /** Every phone in the Studio Case product's variations (SKU convention
     * studio-<model_id>; label = attribute value). Source of truth for launch
     * models = the live product (spec section 2). */
    private static function product_models() {
        $product_id = (int) gstudio_settings()['product_id'];
        if (!$product_id || !function_exists('wc_get_product')) return [];
        $product = wc_get_product($product_id);
        if (!$product || 'variable' !== $product->get_type()) return [];

        $models = [];
        foreach ($product->get_children() as $child_id) {
            $v = wc_get_product($child_id);
            if (!$v) continue;
            $sku = (string) $v->get_sku();
            if (0 !== strpos($sku, 'studio-')) continue;
            if (!$v->is_purchasable() || 'publish' !== $v->get_status()) continue;
            $attrs = $v->get_variation_attributes();
            $label = $attrs ? (string) reset($attrs) : '';
            $label = $label !== '' ? $label : ucwords(str_replace('-', ' ', substr($sku, 7)));
            $models[] = ['model_id' => substr($sku, 7), 'label' => $label];
        }
        return $models;
    }

    /** Launch landing sections above the designer mount: photography-led
     * hero, three steps, library teaser, the shared ProGuard USP band. */
    private static function landing_html() {
        $shots = 'https://raw.githubusercontent.com/Xylement/threads-card-assets/main/studio/';
        $api   = esc_url(gstudio_api_base());
        $thumbs = ['pets/golden', 'daily-cute/boba', 'minis/heart', 'frames/polka-border', 'travel/campervan', 'celebrate/balloons', 'minis/star', 'makan/nasi-lemak'];
        $thumb_html = '';
        foreach ($thumbs as $t) {
            $parts = explode('/', $t);
            $thumb_html .= '<img loading="lazy" alt="" src="' . $api . '/v1/stickers/' . esc_attr($parts[0]) . '/' . esc_attr($parts[1]) . '.thumb" />';
        }
        ob_start();
        ?>
        <section class="gstudio-landing">
          <div class="gsl-hero">
            <h1>Design a case that is completely you.</h1>
            <p class="gsl-sub">Your photos, your words, your stickers. Arranged by you, printed by us on a crystal clear ProGuard case.</p>
            <div class="gsl-shots">
              <img src="<?php echo esc_url($shots . 'dyoc-1.jpg'); ?>" alt="A Studio case with a photo, paw prints and Good Day lettering" loading="eager" fetchpriority="high" />
              <img src="<?php echo esc_url($shots . 'dyoc-2.jpg'); ?>" alt="A Studio case with a family photo in a crayon scribble frame" loading="lazy" />
              <img src="<?php echo esc_url($shots . 'dyoc-3.jpg'); ?>" alt="A Studio case with a golden retriever and the name Bella" loading="lazy" />
              <img src="<?php echo esc_url($shots . 'dyoc-4.jpg'); ?>" alt="A Samsung Studio case with a campervan and towers artwork" loading="lazy" />
            </div>
            <a class="gstudio-btn gstudio-btn--red gsl-cta" href="#galado-studio">Start designing</a>
            <p class="gsl-price">RM169, free shipping included. Made one at a time, just for you.</p>
          </div>
          <div class="gsl-steps">
            <div class="gsl-step"><span>1</span><strong>Pick your model</strong><em>iPhone and Samsung, always the ProGuard case.</em></div>
            <div class="gsl-step"><span>2</span><strong>Make it yours</strong><em>Photos, ten lettering styles, 100+ stickers and frames.</em></div>
            <div class="gsl-step"><span>3</span><strong>We print and ship</strong><em>Checked by hand, delivered free to your door.</em></div>
          </div>
          <div class="gsl-library">
            <p class="gsl-libline">A growing library of house stickers and frames</p>
            <div class="gsl-thumbs"><?php echo $thumb_html; // escaped above ?></div>
          </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function render() {
        if (!self::is_studio_page()) {
            return '';
        }
        return self::landing_html() . '<div id="galado-studio" class="gstudio-root" data-version="' . esc_attr(GSTUDIO_VERSION) . '"></div>';
    }
}
