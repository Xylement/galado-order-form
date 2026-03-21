<?php
if (!defined('ABSPATH')) exit;

/**
 * Add FAQ Schema meta box to post/page/product editors
 */
function gfaq_add_meta_box() {
    $settings = get_option('gfaq_settings', []);
    $post_types = isset($settings['post_types']) ? $settings['post_types'] : ['page', 'post', 'product'];

    foreach ($post_types as $type) {
        add_meta_box(
            'gfaq_meta_box',
            __('FAQ Schema', 'galado-faq-schema'),
            'gfaq_render_meta_box',
            $type,
            'normal',
            'default'
        );
    }
}

/**
 * Render meta box content
 */
function gfaq_render_meta_box($post) {
    wp_nonce_field('gfaq_save_meta_box', 'gfaq_nonce');

    $enabled = get_post_meta($post->ID, '_gfaq_enabled', true);
    if ($enabled === '') $enabled = 'yes';

    $faqs = get_post_meta($post->ID, '_gfaq_manual_faqs', true);
    if (!is_array($faqs)) $faqs = [];
    ?>
    <div class="gfaq-metabox">
        <div class="gfaq-toggle-row">
            <label>
                <strong><?php esc_html_e('Enable FAQ Schema for this page:', 'galado-faq-schema'); ?></strong>
                <select name="gfaq_enabled" style="margin-left:8px;">
                    <option value="yes" <?php selected($enabled, 'yes'); ?>><?php esc_html_e('Yes', 'galado-faq-schema'); ?></option>
                    <option value="no" <?php selected($enabled, 'no'); ?>><?php esc_html_e('No', 'galado-faq-schema'); ?></option>
                </select>
            </label>
        </div>

        <hr style="margin:12px 0;">

        <h4 style="margin:0 0 8px;"><?php esc_html_e('Manual FAQ Entries', 'galado-faq-schema'); ?></h4>
        <p class="description" style="margin-bottom:12px;"><?php esc_html_e('Add FAQs manually. These are combined with any auto-detected FAQs from the page content.', 'galado-faq-schema'); ?></p>

        <div id="gfaq-faqs-container">
            <?php if (!empty($faqs)) : foreach ($faqs as $i => $faq) : ?>
                <div class="gfaq-faq-row" data-index="<?php echo (int) $i; ?>">
                    <div class="gfaq-faq-header">
                        <span class="gfaq-faq-num">#<?php echo (int) $i + 1; ?></span>
                        <button type="button" class="gfaq-remove-faq button-link" title="<?php esc_attr_e('Remove', 'galado-faq-schema'); ?>">&times;</button>
                    </div>
                    <label><?php esc_html_e('Question:', 'galado-faq-schema'); ?></label>
                    <input type="text" name="gfaq_faqs[<?php echo (int) $i; ?>][question]" value="<?php echo esc_attr($faq['question']); ?>" class="widefat" placeholder="<?php esc_attr_e('e.g. How long does shipping take?', 'galado-faq-schema'); ?>">
                    <label><?php esc_html_e('Answer:', 'galado-faq-schema'); ?></label>
                    <textarea name="gfaq_faqs[<?php echo (int) $i; ?>][answer]" class="widefat" rows="3" placeholder="<?php esc_attr_e('Provide a clear, complete answer...', 'galado-faq-schema'); ?>"><?php echo esc_textarea($faq['answer']); ?></textarea>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <button type="button" id="gfaq-add-faq" class="button" style="margin-top:8px;">
            + <?php esc_html_e('Add FAQ', 'galado-faq-schema'); ?>
        </button>
    </div>

    <script>
    (function() {
        var container = document.getElementById('gfaq-faqs-container');
        var addBtn = document.getElementById('gfaq-add-faq');
        var index = container.querySelectorAll('.gfaq-faq-row').length;

        addBtn.addEventListener('click', function() {
            var row = document.createElement('div');
            row.className = 'gfaq-faq-row';
            row.dataset.index = index;
            row.innerHTML = '<div class="gfaq-faq-header"><span class="gfaq-faq-num">#' + (index + 1) + '</span><button type="button" class="gfaq-remove-faq button-link" title="Remove">&times;</button></div>' +
                '<label>Question:</label>' +
                '<input type="text" name="gfaq_faqs[' + index + '][question]" class="widefat" placeholder="e.g. How long does shipping take?">' +
                '<label>Answer:</label>' +
                '<textarea name="gfaq_faqs[' + index + '][answer]" class="widefat" rows="3" placeholder="Provide a clear, complete answer..."></textarea>';
            container.appendChild(row);
            index++;
            row.querySelector('input').focus();
        });

        container.addEventListener('click', function(e) {
            if (e.target.classList.contains('gfaq-remove-faq')) {
                e.target.closest('.gfaq-faq-row').remove();
            }
        });
    })();
    </script>
    <?php
}

/**
 * Save meta box data
 */
function gfaq_save_meta_box($post_id) {
    if (!isset($_POST['gfaq_nonce']) || !wp_verify_nonce($_POST['gfaq_nonce'], 'gfaq_save_meta_box')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Save toggle
    if (isset($_POST['gfaq_enabled'])) {
        update_post_meta($post_id, '_gfaq_enabled', sanitize_text_field($_POST['gfaq_enabled']));
    }

    // Save manual FAQs
    $faqs = [];
    if (isset($_POST['gfaq_faqs']) && is_array($_POST['gfaq_faqs'])) {
        foreach ($_POST['gfaq_faqs'] as $faq) {
            $q = isset($faq['question']) ? sanitize_text_field($faq['question']) : '';
            $a = isset($faq['answer']) ? sanitize_textarea_field($faq['answer']) : '';
            if ($q && $a) {
                $faqs[] = ['question' => $q, 'answer' => $a];
            }
        }
    }
    update_post_meta($post_id, '_gfaq_manual_faqs', $faqs);
}
