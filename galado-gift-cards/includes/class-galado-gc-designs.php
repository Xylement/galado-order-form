<?php
/**
 * Card designs: the buyer picks one on the product page and sees a live preview of the card with
 * the amount, the recipient's name and the message. The chosen design travels with the card line
 * and heads the recipient's email.
 *
 * Artwork is a plain background image (no words: the headline and amount are laid over it as
 * text, so they stay sharp and translatable). The plugin ships placeholders in assets/designs/.
 * To use real artwork without touching the plugin, put a JPG with the same name in the child
 * theme at woocommerce/galado-gift-cards/designs/{key}.jpg (1200 x 750). Names and headlines can
 * be changed, or designs added, with the galado_gift_cards_designs filter.
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

    /** @return array<string, array{label: string, headline: string}> key => names, first is the default */
    public static function all() {
        $designs = [
            'classic'  => ['label' => __('Classic', 'galado-gift-cards'), 'headline' => __('A gift for you', 'galado-gift-cards')],
            'birthday' => ['label' => __('Birthday', 'galado-gift-cards'), 'headline' => __('Happy birthday', 'galado-gift-cards')],
            'thanks'   => ['label' => __('Thank you', 'galado-gift-cards'), 'headline' => __('Thank you', 'galado-gift-cards')],
            'festive'  => ['label' => __('Festive', 'galado-gift-cards'), 'headline' => __('Happy holidays', 'galado-gift-cards')],
        ];
        return (array) apply_filters('galado_gift_cards_designs', $designs);
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

    /** Absolute URL of a design's artwork (emails need absolute URLs): the child theme's, else ours. */
    public static function image_url($key) {
        $file = sanitize_key(self::resolve($key)) . '.jpg';
        foreach ([[get_stylesheet_directory(), get_stylesheet_directory_uri()], [get_template_directory(), get_template_directory_uri()]] as $theme) {
            if (is_readable(trailingslashit($theme[0]) . self::THEME_DIR . $file)) {
                return trailingslashit($theme[1]) . self::THEME_DIR . $file;
            }
        }
        return GALADO_GC_URL . 'assets/designs/' . $file;
    }

    public static function enqueue() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $product = wc_get_product(get_queried_object_id());
        if (!$product || !Galado_GC_Product::is_gift_card_product($product)) {
            return;
        }
        wp_enqueue_style('galado-gift-cards', GALADO_GC_URL . 'assets/gift-card.css', [], GALADO_GC_VERSION);
        wp_enqueue_script('galado-gift-cards', GALADO_GC_URL . 'assets/gift-card.js', [], GALADO_GC_VERSION, true);
    }

    /** The live preview and the design choice, inside the add-to-cart form. */
    public static function render_picker($selected = '') {
        $selected = self::resolve($selected);
        ?>
        <div class="galado-gc-preview" aria-hidden="true" data-galado-gc-preview
             data-attribute="attribute_<?php echo esc_attr(Galado_GC_Product::ATTRIBUTE_KEY); ?>"
             data-custom-label="<?php echo esc_attr(Galado_GC_Product::CUSTOM_LABEL); ?>"
             data-empty-amount="<?php esc_attr_e('Choose an amount', 'galado-gift-cards'); ?>"
             data-empty-name="<?php esc_attr_e("Recipient's name", 'galado-gift-cards'); ?>"
             data-empty-message="<?php esc_attr_e('Your message appears here.', 'galado-gift-cards'); ?>">
            <div class="galado-gc-card" data-gc-card style="background-image:url('<?php echo esc_url(self::image_url($selected)); ?>')">
                <span class="galado-gc-card__brand">GALADO</span>
                <span class="galado-gc-card__headline" data-gc-headline><?php echo esc_html(self::headline($selected)); ?></span>
                <span class="galado-gc-card__amount" data-gc-amount><?php esc_html_e('Choose an amount', 'galado-gift-cards'); ?></span>
            </div>
            <div class="galado-gc-note">
                <p class="galado-gc-note__to"><?php esc_html_e('To', 'galado-gift-cards'); ?> <span data-gc-to><?php esc_html_e("Recipient's name", 'galado-gift-cards'); ?></span></p>
                <p class="galado-gc-note__message" data-gc-message><?php esc_html_e('Your message appears here.', 'galado-gift-cards'); ?></p>
            </div>
        </div>
        <fieldset class="galado-gc-designs">
            <legend><?php esc_html_e('Choose a design', 'galado-gift-cards'); ?></legend>
            <?php foreach (self::all() as $key => $design) : ?>
                <label class="galado-gc-design">
                    <input type="radio" name="<?php echo esc_attr(self::FIELD); ?>" value="<?php echo esc_attr($key); ?>"
                           data-image="<?php echo esc_url(self::image_url($key)); ?>" data-headline="<?php echo esc_attr($design['headline']); ?>"
                        <?php checked($key, $selected); ?>>
                    <span class="galado-gc-design__swatch" style="background-image:url('<?php echo esc_url(self::image_url($key)); ?>')"></span>
                    <span class="galado-gc-design__name"><?php echo esc_html($design['label']); ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }
}
