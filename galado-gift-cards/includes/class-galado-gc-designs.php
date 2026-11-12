<?php
/**
 * Card designs: the buyer picks one on the product page and sees a live preview of the card with
 * the amount, the recipient's name and the message. The chosen design travels with the card line
 * and heads the recipient's email.
 *
 * 18 designs in four groups, drawn to Brand Guidelines v1.0 (flat brand and campaign colours, the
 * galado wordmark, the headline in Archivo). Artwork is a plain background image with no words:
 * the wordmark, headline and amount are laid over it, so they stay sharp. tools/make-designs.py
 * draws assets/designs/{key}.jpg (1200 x 750). To use other artwork without touching the plugin,
 * put a JPG with the same name in the child theme at woocommerce/galado-gift-cards/designs/.
 * Names, headlines, groups and text colour can be changed, or designs added, with the
 * galado_gift_cards_designs filter:
 *   key => ['label' => 'Raya', 'headline' => 'Selamat Hari Raya', 'group' => 'festival', 'text' => 'ink']
 * 'text' is the colour over the art: 'ink' (dark, for light art) or 'white' (the default).
 * Keys are lowercase letters, digits, "-" and "_" (other keys are normalised to that, so the
 * picker and the cart always agree). The picker shows on the product page only: a theme's quick
 * view gets no picker and the card is sold in the default design.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Galado_GC_Designs {

    const FIELD = 'galado_gc_design';
    const META = '_galado_gc_design';
    const THEME_DIR = 'woocommerce/galado-gift-cards/designs/';

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    /** The picker's groups, in order: key => heading. */
    public static function groups() {
        return [
            'any'       => __('Any day', 'galado-gift-cards'),
            'celebrate' => __('Celebrations', 'galado-gift-cards'),
            'festival'  => __('Festivals', 'galado-gift-cards'),
            'love'      => __('Love and family', 'galado-gift-cards'),
        ];
    }

    /**
     * @return array<string, array{label: string, headline: string, group: string, text: string}>
     *         key => design, the first is the default
     */
    public static function all() {
        $d = function ($label, $headline, $group, $text) {
            return ['label' => $label, 'headline' => $headline, 'group' => $group, 'text' => $text];
        };
        $designs = [
            'classic'     => $d(__('Classic', 'galado-gift-cards'), __('A gift for you', 'galado-gift-cards'), 'any', 'white'),
            'thanks'      => $d(__('Thank you', 'galado-gift-cards'), __('Thank you', 'galado-gift-cards'), 'any', 'ink'),
            'justbecause' => $d(__('Just because', 'galado-gift-cards'), __('Just because', 'galado-gift-cards'), 'any', 'ink'),
            'thinking'    => $d(__('Thinking of you', 'galado-gift-cards'), __('Thinking of you', 'galado-gift-cards'), 'any', 'ink'),
            'getwell'     => $d(__('Get well soon', 'galado-gift-cards'), __('Get well soon', 'galado-gift-cards'), 'any', 'ink'),
            'birthday'    => $d(__('Birthday', 'galado-gift-cards'), __('Happy birthday', 'galado-gift-cards'), 'celebrate', 'ink'),
            'congrats'    => $d(__('Congratulations', 'galado-gift-cards'), __('Congratulations', 'galado-gift-cards'), 'celebrate', 'white'),
            'graduation'  => $d(__('Graduation', 'galado-gift-cards'), __('Congrats, graduate', 'galado-gift-cards'), 'celebrate', 'ink'),
            'wedding'     => $d(__('Wedding', 'galado-gift-cards'), __('Happily ever after', 'galado-gift-cards'), 'celebrate', 'ink'),
            'anniversary' => $d(__('Anniversary', 'galado-gift-cards'), __('Happy anniversary', 'galado-gift-cards'), 'celebrate', 'ink'),
            'newbaby'     => $d(__('New baby', 'galado-gift-cards'), __('Welcome, little one', 'galado-gift-cards'), 'celebrate', 'ink'),
            'raya'        => $d(__('Hari Raya', 'galado-gift-cards'), __('Selamat Hari Raya', 'galado-gift-cards'), 'festival', 'ink'),
            'cny'         => $d(__('Chinese New Year', 'galado-gift-cards'), __('Gong Xi Fa Cai', 'galado-gift-cards'), 'festival', 'white'),
            'deepavali'   => $d(__('Deepavali', 'galado-gift-cards'), __('Happy Deepavali', 'galado-gift-cards'), 'festival', 'white'),
            'christmas'   => $d(__('Christmas', 'galado-gift-cards'), __('Merry Christmas', 'galado-gift-cards'), 'festival', 'white'),
            'valentines'  => $d(__('Valentine’s Day', 'galado-gift-cards'), __('Happy Valentine’s Day', 'galado-gift-cards'), 'love', 'ink'),
            'mothers'     => $d(__('Mother’s Day', 'galado-gift-cards'), __('Happy Mother’s Day', 'galado-gift-cards'), 'love', 'ink'),
            'fathers'     => $d(__('Father’s Day', 'galado-gift-cards'), __('Happy Father’s Day', 'galado-gift-cards'), 'love', 'white'),
        ];
        // Whatever the filter returns is normalised the way a posted choice is (sanitize_key), so a
        // design the picker shows can always be bought; entries without both names are skipped.
        $groups = self::groups();
        $out = [];
        foreach ((array) apply_filters('galado_gift_cards_designs', $designs) as $key => $design) {
            $key = sanitize_key((string) $key);
            if ('' === $key || !is_array($design) || !isset($design['label'], $design['headline'])) {
                continue;
            }
            $group = isset($design['group']) ? (string) $design['group'] : '';
            $out[$key] = [
                'label'    => (string) $design['label'],
                'headline' => (string) $design['headline'],
                'group'    => isset($groups[$group]) ? $group : 'any',
                'text'     => (isset($design['text']) && 'ink' === $design['text']) ? 'ink' : 'white',
            ];
        }
        return $out ?: $designs;
    }

    /** The designs per picker group, in group order: [heading => [key => design]]. */
    public static function by_group() {
        $out = [];
        $all = self::all();
        foreach (self::groups() as $group => $heading) {
            foreach ($all as $key => $design) {
                if ($design['group'] === $group) {
                    $out[$heading][$key] = $design;
                }
            }
        }
        return $out;
    }

    public static function text_colour($key) {
        $all = self::all();
        $key = self::resolve($key);
        return isset($all[$key]['text']) ? $all[$key]['text'] : 'white';
    }

    public static function default_key() {
        $keys = array_keys(self::all());
        return $keys ? (string) $keys[0] : '';
    }

    public static function exists($key) {
        return '' !== (string) $key && array_key_exists((string) $key, self::all());
    }

    /** A known design key, or the default for anything else (orders made before designs existed). */
    public static function resolve($key) {
        return self::exists($key) ? (string) $key : self::default_key();
    }

    public static function label($key) {
        $all = self::all();
        $key = self::resolve($key);
        return isset($all[$key]['label']) ? (string) $all[$key]['label'] : '';
    }

    public static function headline($key) {
        $all = self::all();
        $key = self::resolve($key);
        return isset($all[$key]['headline']) ? (string) $all[$key]['headline'] : '';
    }

    /**
     * Absolute URL of a design's artwork (emails need absolute URLs): the child theme's, else ours.
     * Versioned by the file's time, so new artwork is never hidden behind a browser's cached copy.
     */
    public static function image_url($key) {
        $file = sanitize_key(self::resolve($key)) . '.jpg';
        foreach ([[get_stylesheet_directory(), get_stylesheet_directory_uri()], [get_template_directory(), get_template_directory_uri()]] as $theme) {
            $path = trailingslashit($theme[0]) . self::THEME_DIR . $file;
            if (is_readable($path)) {
                return self::versioned(trailingslashit($theme[1]) . self::THEME_DIR . $file, $path);
            }
        }
        return self::versioned(GALADO_GC_URL . 'assets/designs/' . $file, GALADO_GC_DIR . 'assets/designs/' . $file);
    }

    /** A plugin asset's URL, versioned by the file's time. */
    public static function asset_url($relative) {
        return self::versioned(GALADO_GC_URL . $relative, GALADO_GC_DIR . $relative);
    }

    private static function versioned($url, $path) {
        $time = is_readable($path) ? (int) filemtime($path) : 0;
        return $time ? add_query_arg('v', $time, $url) : $url;
    }

    public static function enqueue() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $product = wc_get_product(get_queried_object_id());
        if (!$product || !Galado_GC_Product::is_gift_card_product($product)) {
            return;
        }
        // Versioned by file time, not the plugin version, so a changed file reaches browsers at once.
        $ver = function ($file) {
            return (string) (@filemtime(GALADO_GC_DIR . $file) ?: GALADO_GC_VERSION);
        };
        wp_enqueue_style('galado-gift-cards', GALADO_GC_URL . 'assets/gift-card.css', [], $ver('assets/gift-card.css'));
        wp_enqueue_script('galado-gift-cards', GALADO_GC_URL . 'assets/gift-card.js', [], $ver('assets/gift-card.js'), true);
    }

    /**
     * The live preview and the design choice, inside the add-to-cart form. Product page only: its
     * script and styles load there, and nowhere else (a theme's quick view would show bare radio
     * buttons and a preview that never updates; without the picker the card gets the default design).
     */
    public static function render_picker($selected = '') {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $selected = self::resolve($selected);
        $card_class = 'galado-gc-card' . ('ink' === self::text_colour($selected) ? ' galado-gc-card--ink' : '');
        ?>
        <div class="galado-gc-preview" aria-hidden="true" data-galado-gc-preview
             data-attribute="attribute_<?php echo esc_attr(Galado_GC_Product::ATTRIBUTE_KEY); ?>"
             data-custom-label="<?php echo esc_attr(Galado_GC_Product::CUSTOM_LABEL); ?>"
             data-empty-amount="<?php esc_attr_e('Choose an amount', 'galado-gift-cards'); ?>"
             data-empty-name="<?php esc_attr_e("Recipient's name", 'galado-gift-cards'); ?>"
             data-empty-message="<?php esc_attr_e('Your message appears here.', 'galado-gift-cards'); ?>">
            <div class="<?php echo esc_attr($card_class); ?>" data-gc-card style="background-image:url('<?php echo esc_url(self::image_url($selected)); ?>')">
                <img class="galado-gc-card__wordmark galado-gc-card__wordmark--white" src="<?php echo esc_url(self::asset_url('assets/wordmark-white.png')); ?>" alt="" width="360" height="140">
                <img class="galado-gc-card__wordmark galado-gc-card__wordmark--ink" src="<?php echo esc_url(self::asset_url('assets/wordmark-black.png')); ?>" alt="" width="360" height="140">
                <span class="galado-gc-card__headline" data-gc-headline><?php echo esc_html(self::headline($selected)); ?></span>
                <span class="galado-gc-card__amount" data-gc-amount><?php esc_html_e('Choose an amount', 'galado-gift-cards'); ?></span>
            </div>
            <div class="galado-gc-note" data-clarity-mask="True">
                <p class="galado-gc-note__to"><?php esc_html_e('To', 'galado-gift-cards'); ?> <span data-gc-to><?php esc_html_e("Recipient's name", 'galado-gift-cards'); ?></span></p>
                <p class="galado-gc-note__message" data-gc-message><?php esc_html_e('Your message appears here.', 'galado-gift-cards'); ?></p>
            </div>
        </div>
        <fieldset class="galado-gc-designs">
            <legend><?php esc_html_e('Choose a design', 'galado-gift-cards'); ?></legend>
            <?php foreach (self::by_group() as $heading => $designs) : ?>
                <div class="galado-gc-designs__group">
                    <p class="galado-gc-designs__title"><?php echo esc_html($heading); ?></p>
                    <div class="galado-gc-designs__list">
                        <?php foreach ($designs as $key => $design) : ?>
                            <label class="galado-gc-design">
                                <input type="radio" name="<?php echo esc_attr(self::FIELD); ?>" value="<?php echo esc_attr($key); ?>"
                                       data-image="<?php echo esc_url(self::image_url($key)); ?>" data-headline="<?php echo esc_attr($design['headline']); ?>"
                                       data-text="<?php echo esc_attr($design['text']); ?>" <?php checked($key, $selected); ?>>
                                <span class="galado-gc-design__swatch" style="background-image:url('<?php echo esc_url(self::image_url($key)); ?>')"></span>
                                <span class="galado-gc-design__name"><?php echo esc_html($design['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }
}
