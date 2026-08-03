<?php
/**
 * Settings screen under the GALADO hub menu.
 *
 * The two phase toggles, the Klaviyo key (write-only field, shown masked as
 * prefix + last 4), the optional throwaway-domain blocklist, plus a status
 * readout and the latest captures so acceptance can be verified in wp-admin.
 */

if (!defined('ABSPATH')) exit;

class GALADO_Recovery_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
    }

    public static function menu() {
        $parent = class_exists('Galado_Admin_Hub') ? 'galado-hub' : 'options-general.php';
        add_submenu_page(
            $parent,
            'Cart Recovery', 'Cart Recovery', 'manage_woocommerce',
            'galado-recovery-settings', [__CLASS__, 'render']
        );
    }

    private static function save() {
        if (!isset($_POST['galado_recovery_save']) || !check_admin_referer('galado_recovery_settings')) return;

        $current = galado_recovery_settings();
        $new = [
            'enabled'         => isset($_POST['enabled']) ? '1' : '0',
            'cart_prompt'     => isset($_POST['cart_prompt']) ? '1' : '0',
            'klaviyo_api_key' => $current['klaviyo_api_key'],
            'blocklist'       => sanitize_textarea_field(wp_unslash($_POST['blocklist'] ?? '')),
        ];

        // Key is write-only: an empty input keeps the stored value. Stored
        // encrypted (AES-256-GCM under the wp-config salts), never plaintext.
        $posted_key = trim((string) wp_unslash($_POST['klaviyo_api_key'] ?? ''));
        if ('' !== $posted_key) {
            $new['klaviyo_api_key'] = galado_recovery_encrypt(sanitize_text_field($posted_key));
        }
        if (isset($_POST['clear_key'])) {
            $new['klaviyo_api_key'] = '';
        }

        update_option('galado_recovery_settings', $new, false);
        echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }

    private static function mask($key) {
        $len = strlen($key);
        if ($len <= 10) return str_repeat('*', $len);
        return substr($key, 0, 3) . str_repeat('*', $len - 7) . substr($key, -4);
    }

    public static function render() {
        if (!current_user_can('manage_woocommerce')) return;
        self::save();

        $s          = galado_recovery_settings();
        $active_key = galado_recovery_klaviyo_key();
        $key_source = '' !== $s['klaviyo_api_key'] ? 'this plugin'
            : ('' !== $active_key ? 'reused from another GALADO plugin' : 'none');
        $encrypted  = 0 === strpos((string) $s['klaviyo_api_key'], GALADO_RECOVERY_CIPHER_TAG);
        $counts = GALADO_Recovery_DB::counts();
        $recent = GALADO_Recovery_DB::recent(10);
        $last_error = get_option('galado_recovery_last_error', '');
        $last_push  = get_option('galado_recovery_last_push', '');
        ?>
        <div class="wrap">
          <h1>GALADO Cart Recovery</h1>
          <form method="post">
            <?php wp_nonce_field('galado_recovery_settings'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th>Phase 1: checkout capture</th>
                <td>
                  <label><input type="checkbox" name="enabled" value="1" <?php checked('1', $s['enabled']); ?>>
                  Move the email field to the top of checkout, capture it on entry, and push Started Checkout to Klaviyo</label>
                  <p class="description">Guests only. Logged-in customers are already tracked by the Klaviyo plugin.</p>
                </td>
              </tr>
              <tr>
                <th>Phase 2: cart prompt</th>
                <td>
                  <label><input type="checkbox" name="cart_prompt" value="1" <?php checked('1', $s['cart_prompt']); ?>>
                  Show the dismissible save-your-cart prompt below the Proceed to Checkout button</label>
                  <p class="description">Needs Phase 1 on. Dismissal hides it for 30 days.</p>
                </td>
              </tr>
              <tr>
                <th>Klaviyo private API key</th>
                <td>
                  <input type="password" name="klaviyo_api_key" value="" autocomplete="new-password" class="regular-text"
                         placeholder="<?php echo '' !== $active_key ? esc_attr(self::mask($active_key)) : 'pk_...'; ?>">
                  <p class="description">Active key source: <?php echo esc_html($key_source); ?><?php
                    if ('' !== $s['klaviyo_api_key']) echo $encrypted ? ' (encrypted at rest)' : ' (stored unencrypted: re-save the key to encrypt it)';
                  ?>. Leave blank to keep the stored key.
                  <?php if ('' !== $s['klaviyo_api_key']) : ?>
                    <label style="margin-left:8px"><input type="checkbox" name="clear_key" value="1"> Clear stored key</label>
                  <?php endif; ?></p>
                </td>
              </tr>
              <tr>
                <th>Throwaway domain blocklist</th>
                <td>
                  <textarea name="blocklist" rows="3" class="large-text" placeholder="mailinator.com"><?php echo esc_textarea($s['blocklist']); ?></textarea>
                  <p class="description">Optional. One domain per line. Captures from these domains are ignored silently.</p>
                </td>
              </tr>
            </table>
            <p><button type="submit" name="galado_recovery_save" value="1" class="button button-primary">Save</button></p>
          </form>

          <h2>Status</h2>
          <ul style="line-height:1.9">
            <li>Retry queue: <?php echo function_exists('as_enqueue_async_action') ? 'Action Scheduler' : 'WP-Cron fallback'; ?></li>
            <li>Rows: <?php echo esc_html(wp_json_encode($counts ?: new stdClass())); ?></li>
            <li>Last successful push: <?php echo $last_push ? esc_html($last_push . ' UTC') : 'none yet'; ?></li>
            <?php if ($last_error) : ?><li>Last error: <code><?php echo esc_html($last_error); ?></code></li><?php endif; ?>
          </ul>

          <h2>Latest captures</h2>
          <table class="widefat striped">
            <thead><tr><th>Email</th><th>Total</th><th>Status</th><th>Consent</th><th>Source</th><th>Updated (UTC)</th></tr></thead>
            <tbody>
            <?php if ($recent) : foreach ($recent as $r) : ?>
              <tr>
                <td><?php echo esc_html($r->email); ?></td>
                <td><?php echo esc_html($r->currency . ' ' . number_format((float) $r->cart_total, 2)); ?></td>
                <td><?php echo esc_html($r->status); ?></td>
                <td><?php echo $r->consent ? 'yes' : 'no'; ?></td>
                <td><?php echo esc_html($r->source); ?></td>
                <td><?php echo esc_html($r->updated_at); ?></td>
              </tr>
            <?php endforeach; else : ?>
              <tr><td colspan="6">No captures yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
}
