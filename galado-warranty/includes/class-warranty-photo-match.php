<?php
/**
 * Repeat-photo check for warranty claims.
 *
 * Every claim upload gets a fingerprint, saved on the attachment:
 *   - sha: SHA-256 of the file, so an exact re-upload (photo, video or HEIC) matches;
 *   - dh:  a 64-bit difference hash of the picture (JPEG/PNG/GIF/WebP through GD), so
 *          the same photo resized, re-compressed or screenshotted still matches.
 * A claim is flagged when one of its files matches a file on ANY other claim, from
 * any customer. Staff decide; nothing is declined automatically.
 */

if (!defined('ABSPATH')) exit;

class GWARR_Photo_Match {

    const META     = '_gwarr_fp';
    const VERSION  = 1;
    const NEAR     = 6;   // max differing bits (of 64) to count as the same picture
    const CRON     = 'gwarr_photo_fp_backfill';
    const BATCH    = 25;

    /** @var array|null claim id => [user_id, created_at, status, media => [id => fp]] */
    private static $index = null;

    public static function init() {
        add_action(self::CRON, [__CLASS__, 'run_backfill']);
    }

    /**
     * Fingerprint one attachment and store it. Returns the fingerprint or null
     * when the file is missing. Never throws.
     */
    public static function fingerprint($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $file = $attachment_id ? get_attached_file($attachment_id) : '';
        if (!$file || !is_readable($file)) {
            return null;
        }
        $fp = ['v' => self::VERSION, 'sha' => (string) hash_file('sha256', $file), 'dh' => null];
        try {
            $fp['dh'] = self::dhash($file, (string) get_post_mime_type($attachment_id));
        } catch (Throwable $e) {
            $fp['dh'] = null;
        }
        update_post_meta($attachment_id, self::META, $fp);
        return $fp;
    }

    /** Stored fingerprint, or null when it has not been made yet. */
    public static function stored($attachment_id) {
        $fp = get_post_meta((int) $attachment_id, self::META, true);
        return (is_array($fp) && ($fp['v'] ?? 0) === self::VERSION && !empty($fp['sha'])) ? $fp : null;
    }

    /** Fingerprint every file of a claim (used right after the claim is saved). */
    public static function fingerprint_claim_media(array $media_ids) {
        foreach ($media_ids as $id) {
            self::fingerprint((int) $id);
        }
        self::$index = null;
    }

    /**
     * 16-hex-character difference hash, or null for files GD cannot read.
     * Grey 9x8 thumbnail, one bit per horizontal neighbour pair.
     */
    private static function dhash($file, $mime) {
        if (!function_exists('imagecreatefromstring')) return null;
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return null;
        $img = @imagecreatefromstring((string) file_get_contents($file));
        if (!$img) return null;
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
        imagedestroy($img);
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            $prev = null;
            for ($x = 0; $x < 9; $x++) {
                $rgb  = imagecolorat($small, $x, $y);
                $grey = (($rgb >> 16) & 255) * 299 + (($rgb >> 8) & 255) * 587 + ($rgb & 255) * 114;
                if ($prev !== null) $bits .= $grey > $prev ? '1' : '0';
                $prev = $grey;
            }
        }
        imagedestroy($small);
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) $hex .= dechex(bindec($nibble));
        return $hex;
    }

    /** Number of differing bits between two 16-hex hashes. */
    public static function distance($a, $b) {
        $n = 0;
        for ($i = 0; $i < 16; $i += 4) {
            $x = hexdec(substr($a, $i, 4)) ^ hexdec(substr($b, $i, 4));
            $n += substr_count(decbin($x), '1');
        }
        return $n;
    }

    /** All claims with their stored fingerprints, built once per request. */
    private static function index() {
        if (self::$index !== null) return self::$index;
        global $wpdb;
        self::$index = [];
        $rows = $wpdb->get_results('SELECT id, user_id, status, created_at, media_ids FROM ' . GWARR_Claims::table());
        foreach ((array) $rows as $r) {
            $media = [];
            foreach (GWARR_Claims::media_ids($r) as $mid) {
                $fp = self::stored($mid);
                if ($fp) $media[$mid] = $fp;
            }
            self::$index[(int) $r->id] = [
                'user_id'    => (int) $r->user_id,
                'status'     => (string) $r->status,
                'created_at' => (string) $r->created_at,
                'media'      => $media,
            ];
        }
        return self::$index;
    }

    /**
     * Files of this claim that match a file on another claim.
     * Each: mediaId, matchClaimId, matchMediaId, kind (identical|near),
     * sameCustomer, matchStatus, matchCreatedAt.
     */
    public static function matches($claim) {
        $index = self::index();
        $id    = (int) $claim->id;
        if (!isset($index[$id])) return [];
        $mine = $index[$id];
        $out  = [];
        foreach ($mine['media'] as $mid => $fp) {
            foreach ($index as $other_id => $other) {
                if ($other_id === $id) continue;
                foreach ($other['media'] as $omid => $ofp) {
                    $kind = null;
                    if ($fp['sha'] === $ofp['sha']) {
                        $kind = 'identical';
                    } elseif (!empty($fp['dh']) && !empty($ofp['dh']) && self::distance($fp['dh'], $ofp['dh']) <= self::NEAR) {
                        $kind = 'near';
                    }
                    if (!$kind) continue;
                    $out[] = [
                        'mediaId'        => (int) $mid,
                        'matchClaimId'   => (int) $other_id,
                        'matchMediaId'   => (int) $omid,
                        'kind'           => $kind,
                        'sameCustomer'   => $other['user_id'] === $mine['user_id'],
                        'matchStatus'    => $other['status'],
                        'matchCreatedAt' => $other['created_at'],
                    ];
                }
            }
        }
        return $out;
    }

    /** Files on this claim that have no fingerprint yet (backfill still running). */
    public static function unchecked_count($claim) {
        $n = 0;
        foreach (GWARR_Claims::media_ids($claim) as $mid) {
            if (!self::stored($mid)) $n++;
        }
        return $n;
    }

    /** One plain line per match, for the staff email and wp-admin. */
    public static function describe(array $matches) {
        $lines = [];
        foreach ($matches as $m) {
            $who  = $m['sameCustomer'] ? 'same customer' : 'another customer';
            $what = $m['kind'] === 'identical' ? 'Identical file to' : 'Same picture as';
            $date = $m['matchCreatedAt'] ? date_i18n('j M Y', strtotime($m['matchCreatedAt'])) : '';
            $lines[] = sprintf('%s claim #%d (%s, %s, %s)', $what, $m['matchClaimId'], $who, $m['matchStatus'], $date);
        }
        return array_values(array_unique($lines));
    }

    /** Queue the backfill of older claims (idempotent). */
    public static function schedule_backfill() {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_single_event(time() + 30, self::CRON);
        }
    }

    /** Fingerprint up to BATCH unfingerprinted claim files, then queue the next batch. */
    public static function run_backfill() {
        global $wpdb;
        $todo = [];
        foreach ((array) $wpdb->get_col('SELECT media_ids FROM ' . GWARR_Claims::table()) as $json) {
            $ids = json_decode((string) $json, true);
            foreach ((array) $ids as $mid) {
                $mid = absint($mid);
                $file = $mid ? get_attached_file($mid) : '';
                if ($file && is_readable($file) && !self::stored($mid)) {
                    $todo[$mid] = true;
                }
            }
        }
        $done = 0;
        foreach (array_slice(array_keys($todo), 0, self::BATCH) as $mid) {
            if (self::fingerprint($mid)) $done++;
        }
        if (count($todo) > self::BATCH) {
            wp_schedule_single_event(time() + 60, self::CRON);
        }
        return $done;
    }
}
