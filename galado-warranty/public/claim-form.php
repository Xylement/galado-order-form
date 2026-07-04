<?php
/**
 * Customer warranty-claim submission: an inline form revealed under each active
 * warranty on My Warranties (issue description + photo/video uploads). Submits
 * as a normal multipart POST, handled early on template_redirect (PRG) so
 * uploads + the redirect happen before any output.
 */

if (!defined('ABSPATH')) exit;

// Upload caps (host php.ini upload_max_filesize/post_max_size still apply on top).
const GWARR_CLAIM_MAX_PHOTOS    = 4;
const GWARR_CLAIM_PHOTO_BYTES   = 5242880;    // 5 MB
const GWARR_CLAIM_VIDEO_BYTES   = 52428800;   // 50 MB
const GWARR_CLAIM_PHOTO_MIMES   = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
const GWARR_CLAIM_VIDEO_MIMES   = ['video/mp4', 'video/quicktime', 'video/webm'];

/**
 * Process a claim POST before output, then redirect back to My Warranties.
 */
add_action('template_redirect', 'gwarr_maybe_process_claim', 9);

function gwarr_maybe_process_claim() {
    if (empty($_POST['gwarr_claim_submit']) || empty($_POST['gwarr_claim_nonce'])) {
        return;
    }
    if (!is_user_logged_in()) {
        return;
    }
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gwarr_claim_nonce'])), 'gwarr_claim')) {
        return;
    }

    $notice = gwarr_handle_claim_submission();
    if ($notice !== '') {
        set_transient('gwarr_form_notice_' . get_current_user_id(), $notice, 60);
    }

    wp_safe_redirect(gwarr_my_warranties_url());
    exit;
}

/**
 * Validate + persist a claim (with uploads). Returns an HTML notice string.
 */
function gwarr_handle_claim_submission() {
    $user_id      = get_current_user_id();
    $warranty_id  = isset($_POST['warranty_id']) ? (int) $_POST['warranty_id'] : 0;
    $issue        = isset($_POST['issue_description']) ? trim(sanitize_textarea_field(wp_unslash($_POST['issue_description']))) : '';
    $item_label   = isset($_POST['claim_item']) ? trim(sanitize_text_field(wp_unslash($_POST['claim_item']))) : '';

    // The warranty must belong to this user and be claimable (active, not expired/claimed).
    $warranty = GWARR_DB::find($warranty_id);
    if (!$warranty || (int) $warranty->user_id !== (int) $user_id) {
        return gwarr_notice('error', 'We couldn\'t find that warranty on your account.');
    }
    if (!gwarr_warranty_is_claimable($warranty)) {
        return gwarr_notice('error', 'This warranty isn\'t eligible for a claim (it may be expired, already claimed, or under review).');
    }
    if (GWARR_Claims::has_open_claim($warranty_id)) {
        return gwarr_notice('info', 'You already have a claim under review for this item. We\'ll be in touch.');
    }
    if ($issue === '') {
        return gwarr_notice('error', 'Please describe the issue so we can help.');
    }

    // When the warranty covers multiple items, the customer must pick which one
    // the claim is for; validate it against the actual items on the warranty.
    $items = gwarr_parse_product_items($warranty->product_text);
    if (count($items) > 1) {
        if ($item_label === '' || !in_array($item_label, $items, true)) {
            return gwarr_notice('error', 'Please select which item this claim is for.');
        }
    } else {
        $item_label = $items ? $items[0] : ''; // single-item: implied
    }

    // Handle uploads (photos[] + optional video). Caps are enforced here; the
    // host's php.ini limits apply on top — if exceeded, $_FILES arrives empty.
    $media = gwarr_handle_claim_uploads();
    if (is_wp_error($media)) {
        return gwarr_notice('error', esc_html($media->get_error_message()));
    }

    $claim_id = GWARR_Claims::insert([
        'warranty_id'       => $warranty_id,
        'user_id'           => $user_id,
        'item_label'        => $item_label,
        'issue_description' => $issue,
        'media_ids'         => $media,
    ]);
    if (is_wp_error($claim_id)) {
        return gwarr_notice('error', esc_html($claim_id->get_error_message()));
    }

    // Notify (deferred so the customer isn't kept waiting on email/SMTP).
    if (class_exists('GWARR_Deferred')) {
        GWARR_Deferred::add(function () use ($claim_id) {
            $claim = GWARR_Claims::find($claim_id);
            if ($claim) {
                GWARR_Email::send_claim_received($claim);
                GWARR_Email::send_admin_claim_alert($claim);
            }
        });
    }

    return gwarr_notice('success',
        '<strong>Claim submitted.</strong> Our team will review it and email you with the next steps. '
        . 'You can track its status here on My Warranties.'
    );
}

/**
 * Process $_FILES into WP media-library attachments, enforcing type/size/count
 * caps. Returns an array of attachment ids or a WP_Error.
 */
function gwarr_handle_claim_uploads() {
    if (empty($_FILES)) {
        // Empty when the host's post_max_size was exceeded — surface a clear hint.
        if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST['gwarr_claim_submit'])) {
            return new WP_Error('gwarr_upload_too_big', 'Your files were too large to upload. Please use smaller photos or a shorter video.');
        }
        return []; // no files attached — allowed (issue text may be enough)
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $ids = [];

    // Photos — repeated field name photos[].
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $count = 0;
        foreach ($_FILES['photos']['name'] as $i => $name) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE || $name === '') {
                continue;
            }
            if (++$count > GWARR_CLAIM_MAX_PHOTOS) {
                return new WP_Error('gwarr_too_many', 'Please attach at most ' . GWARR_CLAIM_MAX_PHOTOS . ' photos.');
            }
            $err = gwarr_validate_upload($_FILES['photos'], $i, GWARR_CLAIM_PHOTO_MIMES, GWARR_CLAIM_PHOTO_BYTES, 'photo');
            if (is_wp_error($err)) {
                return $err;
            }
            $id = gwarr_sideload_single('photos', $i);
            if (is_wp_error($id)) {
                return $id;
            }
            $ids[] = $id;
        }
    }

    // Video — single optional field.
    if (!empty($_FILES['video']) && !is_array($_FILES['video']['name'])
        && $_FILES['video']['error'] !== UPLOAD_ERR_NO_FILE && $_FILES['video']['name'] !== '') {
        $err = gwarr_validate_single($_FILES['video'], GWARR_CLAIM_VIDEO_MIMES, GWARR_CLAIM_VIDEO_BYTES, 'video');
        if (is_wp_error($err)) {
            return $err;
        }
        $id = media_handle_upload('video', 0);
        if (is_wp_error($id)) {
            return new WP_Error('gwarr_video_failed', 'We couldn\'t process that video. Please try a shorter MP4.');
        }
        $ids[] = $id;
    }

    return $ids;
}

/**
 * Validate one item within a multi-file ($_FILES['photos']) array.
 */
function gwarr_validate_upload($files, $i, $allowed_mimes, $max_bytes, $label) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
        return new WP_Error('gwarr_upload_err', 'One of your ' . $label . 's failed to upload. Please try again.');
    }
    if ((int) $files['size'][$i] > $max_bytes) {
        return new WP_Error('gwarr_upload_big', 'Each ' . $label . ' must be under ' . round($max_bytes / 1048576) . ' MB.');
    }
    $check = wp_check_filetype_and_ext($files['tmp_name'][$i], $files['name'][$i]);
    $mime  = $check['type'] ?: '';
    if (!in_array($mime, $allowed_mimes, true)) {
        return new WP_Error('gwarr_upload_type', 'Unsupported ' . $label . ' format. Use JPG, PNG, or WEBP.');
    }
    return true;
}

/**
 * Validate a single-file ($_FILES['video']) entry.
 */
function gwarr_validate_single($file, $allowed_mimes, $max_bytes, $label) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('gwarr_upload_err', 'Your ' . $label . ' failed to upload. Please try again.');
    }
    if ((int) $file['size'] > $max_bytes) {
        return new WP_Error('gwarr_upload_big', 'The ' . $label . ' must be under ' . round($max_bytes / 1048576) . ' MB.');
    }
    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    $mime  = $check['type'] ?: '';
    if (!in_array($mime, $allowed_mimes, true)) {
        return new WP_Error('gwarr_upload_type', 'Unsupported ' . $label . ' format. Use MP4, MOV, or WEBM.');
    }
    return true;
}

/**
 * Sideload one entry of a multi-file field into the media library by
 * temporarily reshaping $_FILES into a single-file entry media_handle_upload
 * understands.
 */
function gwarr_sideload_single($field, $i) {
    $orig = $_FILES[$field];
    $_FILES[$field] = [
        'name'     => $orig['name'][$i],
        'type'     => $orig['type'][$i],
        'tmp_name' => $orig['tmp_name'][$i],
        'error'    => $orig['error'][$i],
        'size'     => $orig['size'][$i],
    ];
    $id = media_handle_upload($field, 0);
    $_FILES[$field] = $orig; // restore for any later iterations
    if (is_wp_error($id)) {
        return new WP_Error('gwarr_photo_failed', 'We couldn\'t process one of your photos. Please try a JPG or PNG.');
    }
    return (int) $id;
}

/**
 * A warranty is claimable when it's active (approved), not expired, not claimed.
 */
function gwarr_warranty_is_claimable($warranty) {
    if (!$warranty || $warranty->status !== 'approved') {
        return false;
    }
    if ($warranty->warranty_ends && strtotime($warranty->warranty_ends) < strtotime(current_time('Y-m-d'))) {
        return false; // expired
    }
    return true;
}

/**
 * Render the inline "Submit a claim" form for one warranty card. Reveals on
 * click via <details>. Shown only for claimable warranties without an open claim.
 */
function gwarr_render_claim_form($warranty) {
    $items = gwarr_parse_product_items($warranty->product_text);
    ?>
    <details class="gwarr-claim">
        <summary class="gwarr-claim-toggle">Submit a warranty claim</summary>
        <form method="post" enctype="multipart/form-data" class="gwarr-claim-form">
            <?php wp_nonce_field('gwarr_claim', 'gwarr_claim_nonce'); ?>
            <input type="hidden" name="warranty_id" value="<?php echo (int) $warranty->id; ?>">

            <?php if (count($items) > 1): ?>
                <label class="gwarr-field">
                    <span class="gwarr-label">Which item is this claim for?</span>
                    <select name="claim_item" required>
                        <option value="">Select the item…</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?php echo esc_attr($item); ?>"><?php echo esc_html($item); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label class="gwarr-field">
                <span class="gwarr-label">What's wrong?</span>
                <textarea name="issue_description" rows="3" maxlength="1000" required
                          placeholder="Tell us what happened, e.g. the strap detached after 2 weeks."></textarea>
            </label>

            <label class="gwarr-field">
                <span class="gwarr-label">Photos <span class="gwarr-optional">(up to <?php echo GWARR_CLAIM_MAX_PHOTOS; ?>, max 5MB each)</span></span>
                <input type="file" name="photos[]" accept="image/*" multiple>
            </label>

            <label class="gwarr-field">
                <span class="gwarr-label">Video <span class="gwarr-optional">(optional, 1 clip, max 50MB)</span></span>
                <input type="file" name="video" accept="video/*">
            </label>

            <p class="gwarr-actions">
                <button type="submit" name="gwarr_claim_submit" value="1" class="button gwarr-btn">Submit claim</button>
            </p>
            <p class="gwarr-fineprint">Clear photos (and a short video if relevant) help us resolve your claim faster.</p>
        </form>
    </details>
    <?php
}
