<?php
/**
 * Studio Assets: the owner's own sticker/frame manager (round 13 infra).
 *
 * WP admin page -> studio-api /v1/admin-assets, authenticated per request
 * with a short-lived t=assets token signed with the shared studio secret.
 * Upload a PNG, pick sticker or frame, and it appears in the designer
 * trays immediately; delete removes it just as fast.
 */

if (!defined('ABSPATH')) exit;

class GSTUDIO_Assets {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_gstudio_asset_upload', [__CLASS__, 'handle_upload']);
        add_action('admin_post_gstudio_asset_delete', [__CLASS__, 'handle_delete']);
    }

    public static function menu() {
        add_menu_page('Studio Assets', 'Studio Assets', 'manage_woocommerce', 'gstudio-assets', [__CLASS__, 'render'], 'dashicons-art', 58);
    }

    private static function assets_token() {
        return GSTUDIO_Token::sign(['t' => 'assets', 'exp' => time() + 300], gstudio_secret());
    }

    private static function api_post($path, $payload) {
        $res = wp_remote_post(gstudio_api_base() . $path, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);
        if (is_wp_error($res)) return ['ok' => false, 'error' => $res->get_error_message()];
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (200 !== wp_remote_retrieve_response_code($res) || empty($body['ok'])) {
            return ['ok' => false, 'error' => isset($body['human_message']) ? $body['human_message'] : 'Studio API refused the request.'];
        }
        return $body;
    }

    public static function render() {
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
        $api = gstudio_api_base();
        $manifest = [];
        $res = wp_remote_get($api . '/v1/stickers', ['timeout' => 15]);
        if (!is_wp_error($res)) {
            $body = json_decode(wp_remote_retrieve_body($res), true);
            if (isset($body['packs']) && is_array($body['packs'])) $manifest = $body['packs'];
        }
        $msg = isset($_GET['gsmsg']) ? sanitize_text_field(wp_unslash($_GET['gsmsg'])) : '';
        $err = isset($_GET['gserr']) ? sanitize_text_field(wp_unslash($_GET['gserr'])) : '';
        ?>
        <div class="wrap">
          <h1>Studio Assets</h1>
          <p>Upload your own stickers and frames as transparent PNGs. They show in the Studio designer immediately; deleting removes them just as fast. Nothing here touches customer designs already saved.</p>
          <?php if ($msg) : ?><div class="notice notice-success"><p><?php echo esc_html($msg); ?></p></div><?php endif; ?>
          <?php if ($err) : ?><div class="notice notice-error"><p><?php echo esc_html($err); ?></p></div><?php endif; ?>

          <h2>Add an asset</h2>
          <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;padding:16px;border:1px solid #ccd0d4;max-width:640px">
            <?php wp_nonce_field('gstudio_asset_upload'); ?>
            <input type="hidden" name="action" value="gstudio_asset_upload" />
            <table class="form-table" role="presentation">
              <tr><th scope="row">PNG files</th>
                <td><input type="file" name="gstudio_png[]" accept="image/png" multiple required />
                <p class="description">Transparent PNGs, up to 5MB each. Select as many as you like; each is resized to a 1600px box and named after its filename.</p></td></tr>
              <tr><th scope="row">Pack</th>
                <td>
                  <select name="pack_select">
                    <?php foreach ($manifest as $pid => $p) : if ('qa' === $pid) continue; ?>
                      <option value="<?php echo esc_attr($pid); ?>"><?php echo esc_html(isset($p['label']) ? $p['label'] : $pid); ?></option>
                    <?php endforeach; ?>
                    <option value="__new">New pack...</option>
                  </select>
                  <input type="text" name="new_pack" placeholder="new pack name" />
                  <p class="description">New packs need a category below; existing packs keep theirs.</p>
                </td></tr>
              <tr><th scope="row">Category</th>
                <td><label><input type="radio" name="category" value="sticker" checked /> Sticker</label>
                &nbsp;&nbsp;<label><input type="radio" name="category" value="frame" /> Frame</label></td></tr>
              <tr><th scope="row">Asset name</th>
                <td><input type="text" name="asset_id" placeholder="e.g. red-ribbon (optional, single file only)" />
                <p class="description">Leave empty for bulk uploads; every file is named from its filename (spaces become dashes).</p></td></tr>
            </table>
            <?php submit_button('Upload to Studio'); ?>
          </form>

          <h2 style="margin-top:28px">Current library</h2>
          <?php foreach ($manifest as $pid => $p) : if ('qa' === $pid) continue; ?>
            <h3><?php echo esc_html(isset($p['label']) ? $p['label'] : $pid); ?>
              <?php if (!empty($p['category']) && 'frame' === $p['category']) : ?><span class="dashicons dashicons-format-image" title="Frames"></span><?php endif; ?>
              <small>(<?php echo count($p['stickers']); ?>)</small></h3>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
              <?php foreach ($p['stickers'] as $st) : ?>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:6px;text-align:center;width:96px">
                  <img src="<?php echo esc_url($api . '/v1/stickers/' . rawurlencode($pid) . '/' . rawurlencode($st['id']) . '.thumb'); ?>" style="width:80px;height:80px;object-fit:contain;background:repeating-conic-gradient(#eee 0% 25%, #fff 0% 50%) 0 0/12px 12px" alt="" />
                  <div style="font-size:11px;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html($st['id']); ?></div>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Remove <?php echo esc_js($st['id']); ?> from the Studio?');">
                    <?php wp_nonce_field('gstudio_asset_delete'); ?>
                    <input type="hidden" name="action" value="gstudio_asset_delete" />
                    <input type="hidden" name="pack" value="<?php echo esc_attr($pid); ?>" />
                    <input type="hidden" name="asset_id" value="<?php echo esc_attr($st['id']); ?>" />
                    <button type="submit" class="button-link-delete" style="font-size:11px">Remove</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php
    }

    private static function back($msg, $is_error = false) {
        $url = add_query_arg($is_error ? 'gserr' : 'gsmsg', rawurlencode($msg), admin_url('admin.php?page=gstudio-assets'));
        wp_safe_redirect($url);
        exit;
    }

    public static function handle_upload() {
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
        check_admin_referer('gstudio_asset_upload');
        if (function_exists('set_time_limit')) @set_time_limit(300);

        // Normalise single and multiple uploads into one list.
        $files = [];
        if (!empty($_FILES['gstudio_png']['name'])) {
            $names = (array) $_FILES['gstudio_png']['name'];
            foreach ($names as $i => $n) {
                $files[] = [
                    'name'     => is_array($_FILES['gstudio_png']['name']) ? $_FILES['gstudio_png']['name'][$i] : $_FILES['gstudio_png']['name'],
                    'tmp_name' => is_array($_FILES['gstudio_png']['tmp_name']) ? $_FILES['gstudio_png']['tmp_name'][$i] : $_FILES['gstudio_png']['tmp_name'],
                    'size'     => is_array($_FILES['gstudio_png']['size']) ? $_FILES['gstudio_png']['size'][$i] : $_FILES['gstudio_png']['size'],
                    'error'    => is_array($_FILES['gstudio_png']['error']) ? $_FILES['gstudio_png']['error'][$i] : $_FILES['gstudio_png']['error'],
                ];
            }
        }
        if (!$files) self::back('No files arrived. Try again.', true);

        $pack = sanitize_title(wp_unslash($_POST['pack_select'] ?? ''));
        if ('__new' === $pack) $pack = sanitize_title(wp_unslash($_POST['new_pack'] ?? ''));
        $category = ('frame' === ($_POST['category'] ?? '')) ? 'frame' : 'sticker';
        $custom_id = sanitize_title(wp_unslash($_POST['asset_id'] ?? ''));
        if (!$pack) self::back('Give the pack a simple name (letters, numbers, dashes).', true);

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $added = [];
        $failed = [];
        foreach ($files as $file) {
            $label = sanitize_file_name((string) $file['name']);
            if (UPLOAD_ERR_OK !== (int) $file['error']) { $failed[$label] = 'did not upload'; continue; }
            if ((int) $file['size'] > 5 * 1024 * 1024) { $failed[$label] = 'over 5MB'; continue; }
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
            if ('image/png' !== $mime) { $failed[$label] = 'not a PNG'; continue; }
            $id = (1 === count($files) && $custom_id) ? $custom_id : sanitize_title(pathinfo($file['name'], PATHINFO_FILENAME));
            if (!$id) { $failed[$label] = 'unusable filename'; continue; }
            $out = self::api_post('/v1/admin-assets', [
                'token'      => self::assets_token(),
                'pack'       => $pack,
                'id'         => $id,
                'category'   => $category,
                'png_base64' => base64_encode(file_get_contents($file['tmp_name'])),
            ]);
            if (empty($out['ok'])) { $failed[$label] = $out['error'] ?? 'API refused it'; continue; }
            $added[] = $id;
        }

        if ($added && !$failed) {
            self::back(sprintf('%d asset%s added to %s and live in the Studio: %s.',
                count($added), 1 === count($added) ? '' : 's', $pack, implode(', ', $added)));
        }
        $bits = [];
        if ($added) $bits[] = sprintf('%d added to %s (%s)', count($added), $pack, implode(', ', $added));
        foreach ($failed as $name => $why) $bits[] = sprintf('%s failed: %s', $name, $why);
        self::back(implode('. ', $bits) . '.', !$added);
    }

    public static function handle_delete() {
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');
        check_admin_referer('gstudio_asset_delete');
        $pack = sanitize_title(wp_unslash($_POST['pack'] ?? ''));
        $id   = sanitize_title(wp_unslash($_POST['asset_id'] ?? ''));
        if (!$pack || !$id) self::back('Bad request.', true);
        $out = self::api_post('/v1/admin-assets/delete', [
            'token' => self::assets_token(),
            'pack'  => $pack,
            'id'    => $id,
        ]);
        if (empty($out['ok'])) self::back('Remove failed: ' . ($out['error'] ?? 'unknown error'), true);
        self::back(sprintf('%s removed from %s.', $id, $pack));
    }
}
