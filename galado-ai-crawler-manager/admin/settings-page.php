<?php
if (!defined('ABSPATH')) exit;

/**
 * Render AI Crawler Manager settings page
 */
function gaic_settings_page() {
    if (!current_user_can('manage_options')) return;

    // Handle save
    if (isset($_POST['gaic_save']) && check_admin_referer('gaic_save_settings', 'gaic_nonce')) {
        $crawlers = gaic_get_crawlers();
        $settings = [];
        foreach ($crawlers as $bot => $info) {
            $settings[$bot] = isset($_POST['gaic_bot'][$bot]) ? 'allow' : 'disallow';
        }
        update_option('gaic_crawlers', $settings);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'galado-ai-crawler') . '</p></div>';
    }

    // Handle presets
    if (isset($_POST['gaic_preset']) && check_admin_referer('gaic_save_settings', 'gaic_nonce')) {
        $crawlers = gaic_get_crawlers();
        $preset = sanitize_text_field($_POST['gaic_preset']);
        $settings = [];
        foreach ($crawlers as $bot => $info) {
            if ($preset === 'allow_all') {
                $settings[$bot] = 'allow';
            } elseif ($preset === 'block_all') {
                $settings[$bot] = 'disallow';
            } elseif ($preset === 'recommended') {
                $settings[$bot] = $info['recommended'] ? 'allow' : 'disallow';
            }
        }
        update_option('gaic_crawlers', $settings);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Preset applied.', 'galado-ai-crawler') . '</p></div>';
    }

    $crawlers = gaic_get_crawlers();
    $settings = get_option('gaic_crawlers', []);
    ?>
    <div class="wrap gaic-wrap">
        <h1><?php esc_html_e('AI Crawler Manager', 'galado-ai-crawler'); ?></h1>
        <p class="gaic-subtitle"><?php esc_html_e('Control which AI engines can crawl and cite your website.', 'galado-ai-crawler'); ?></p>

        <div class="gaic-info-box">
            <strong><?php esc_html_e('How it works', 'galado-ai-crawler'); ?></strong>
            <p><?php esc_html_e('Allowing AI crawlers helps your content appear in AI search results (ChatGPT, Perplexity, Google AI Overviews, Claude). Blocking them prevents your content from being used for AI training.', 'galado-ai-crawler'); ?></p>
            <p><?php esc_html_e('Rules are injected into your virtual robots.txt automatically.', 'galado-ai-crawler'); ?>
                <a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank" rel="noopener"><?php esc_html_e('View robots.txt →', 'galado-ai-crawler'); ?></a>
            </p>
        </div>

        <form method="post">
            <?php wp_nonce_field('gaic_save_settings', 'gaic_nonce'); ?>

            <!-- Preset buttons -->
            <div class="gaic-presets">
                <button type="submit" name="gaic_preset" value="recommended" class="button gaic-preset-btn gaic-preset-recommended">
                    ⭐ <?php esc_html_e('Recommended', 'galado-ai-crawler'); ?>
                </button>
                <button type="submit" name="gaic_preset" value="allow_all" class="button gaic-preset-btn">
                    <?php esc_html_e('Allow All', 'galado-ai-crawler'); ?>
                </button>
                <button type="submit" name="gaic_preset" value="block_all" class="button gaic-preset-btn">
                    <?php esc_html_e('Block All', 'galado-ai-crawler'); ?>
                </button>
            </div>

            <!-- Crawler cards -->
            <div class="gaic-grid">
                <?php foreach ($crawlers as $bot => $info) :
                    $is_allowed = isset($settings[$bot]) ? ($settings[$bot] === 'allow') : $info['recommended'];
                ?>
                <div class="gaic-card <?php echo $is_allowed ? 'gaic-card--allowed' : 'gaic-card--blocked'; ?>">
                    <div class="gaic-card-icon" style="background-color: <?php echo esc_attr($info['color']); ?>">
                        <?php echo esc_html(substr($info['owner'], 0, 1)); ?>
                    </div>
                    <div class="gaic-card-info">
                        <div class="gaic-card-name">
                            <?php echo esc_html($bot); ?>
                            <span class="gaic-card-owner"><?php echo esc_html($info['owner']); ?></span>
                        </div>
                        <div class="gaic-card-desc"><?php echo esc_html($info['desc']); ?></div>
                    </div>
                    <div class="gaic-card-toggle">
                        <span class="gaic-status <?php echo $is_allowed ? 'gaic-status--on' : 'gaic-status--off'; ?>">
                            <?php echo $is_allowed ? esc_html__('Allowed', 'galado-ai-crawler') : esc_html__('Blocked', 'galado-ai-crawler'); ?>
                        </span>
                        <label class="gaic-switch">
                            <input type="checkbox" name="gaic_bot[<?php echo esc_attr($bot); ?>]" value="1" <?php checked($is_allowed); ?>>
                            <span class="gaic-slider"></span>
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <p class="submit">
                <button type="submit" name="gaic_save" value="1" class="button button-primary button-large">
                    <?php esc_html_e('Save Changes', 'galado-ai-crawler'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}
