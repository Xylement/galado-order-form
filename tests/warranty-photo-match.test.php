<?php
/**
 * galado-warranty: repeat-photo check on claims (v1.13.0).
 * Run: php tests/warranty-photo-match.test.php  (needs GD; exits 1 on any failure)
 *
 * A claim is flagged when one of its files is the same as a file on another
 * claim: the exact file (any type, by SHA-256) or the same picture resized,
 * re-compressed or saved as PNG (difference hash). Different photos must not
 * match. Lives outside the plugin folder, so Git Sync never ships it.
 */

define('ABSPATH', __DIR__);

// ---------------------------------------------------------------- WP stubs
$GLOBALS['files'] = []; $GLOBALS['mimes'] = []; $GLOBALS['meta'] = []; $GLOBALS['cron'] = [];
function add_action() {}
function get_attached_file($id) { return $GLOBALS['files'][$id] ?? ''; }
function get_post_mime_type($id) { return $GLOBALS['mimes'][$id] ?? ''; }
function get_post_meta($id, $k, $single) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; }
function absint($v) { return abs((int) $v); }
function date_i18n($f, $t) { return date($f, $t); }
function wp_next_scheduled($h) { return in_array($h, $GLOBALS['cron'], true); }
function wp_schedule_single_event($t, $h) { $GLOBALS['cron'][] = $h; }
class GWARR_Claims {
    public static function table() { return 'claims'; }
    public static function media_ids($c) { $ids = json_decode((string) ($c->media_ids ?? ''), true); return is_array($ids) ? array_map('intval', $ids) : []; }
}
class FakeWpdb {
    public $rows = [];
    public function get_results($sql) { return $this->rows; }
    public function get_col($sql) { return array_map(function ($r) { return $r->media_ids; }, $this->rows); }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../galado-warranty/includes/class-warranty-photo-match.php';

// ----------------------------------------------------------------- runner
$failed = 0;
function check($label, $cond) {
    global $failed;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$cond) $failed++;
}

// ------------------------------------------------------------- test files
$dir = sys_get_temp_dir() . '/gwarr-pm-' . getmypid();
@mkdir($dir);
function photo($seed) {
    // A "photo": smooth gradients plus a few shapes, different per seed.
    mt_srand($seed);
    $im = imagecreatetruecolor(1200, 900);
    for ($y = 0; $y < 900; $y += 4) {
        $c = imagecolorallocate($im, ($y * $seed) % 256, (int) (128 + 100 * sin($y / 90 + $seed)), (int) (200 - $y / 6) & 255);
        imagefilledrectangle($im, 0, $y, 1199, $y + 3, $c);
    }
    for ($i = 0; $i < 6; $i++) {
        $c = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
        imagefilledellipse($im, mt_rand(100, 1100), mt_rand(100, 800), mt_rand(150, 400), mt_rand(150, 400), $c);
    }
    return $im;
}
function add_file($id, $path, $mime) { $GLOBALS['files'][$id] = $path; $GLOBALS['mimes'][$id] = $mime; }

$a = photo(3);
imagejpeg($a, "$dir/a.jpg", 92);                       add_file(1, "$dir/a.jpg", 'image/jpeg');
copy("$dir/a.jpg", "$dir/a-copy.jpg");                  add_file(2, "$dir/a-copy.jpg", 'image/jpeg');
$small = imagescale($a, 600, 450);
imagejpeg($small, "$dir/a-small.jpg", 55);              add_file(3, "$dir/a-small.jpg", 'image/jpeg');
imagepng($a, "$dir/a.png");                             add_file(4, "$dir/a.png", 'image/png');
imagejpeg(photo(11), "$dir/b.jpg", 90);                 add_file(5, "$dir/b.jpg", 'image/jpeg');
imagejpeg(photo(29), "$dir/c.jpg", 90);                 add_file(6, "$dir/c.jpg", 'image/jpeg');
file_put_contents("$dir/v.mp4", random_bytes(4096));    add_file(7, "$dir/v.mp4", 'video/mp4');
copy("$dir/v.mp4", "$dir/v-again.mp4");                 add_file(8, "$dir/v-again.mp4", 'video/mp4');
file_put_contents("$dir/w.mp4", random_bytes(4096));    add_file(9, "$dir/w.mp4", 'video/mp4');
add_file(10, "$dir/missing.jpg", 'image/jpeg');         // on record, file gone

// Claims: 101 (customer 1) is the original; 102 (customer 2) reuses it exactly;
// 103 (customer 3) uses a resized re-compressed copy; 104 (customer 1) a PNG of it;
// 105 (customer 4) has its own photos; 106 re-uploads 101's video; 107 a new video.
$claims = [
    101 => [1, [1, 7]], 102 => [2, [2]], 103 => [3, [3]], 104 => [1, [4]],
    105 => [4, [5, 6]], 106 => [5, [8]], 107 => [6, [9, 10]],
];
foreach ($claims as $cid => [$uid, $media]) {
    $GLOBALS['wpdb']->rows[] = (object) ['id' => $cid, 'user_id' => $uid, 'status' => 'approved', 'created_at' => '2026-10-0' . ($cid - 100) . ' 10:00:00', 'media_ids' => json_encode($media)];
}
$claim = function ($id) { foreach ($GLOBALS['wpdb']->rows as $r) if ((int) $r->id === $id) return $r; };

// ---------------------------------------------------------------- tests
$done = GWARR_Photo_Match::run_backfill();
check('backfill fingerprints every readable file (9 of 10)', $done === 9);
check('backfill does not queue another batch when nothing is left', $GLOBALS['cron'] === []);
check('missing file stays unfingerprinted', GWARR_Photo_Match::stored(10) === null);
check('video gets a file hash but no picture hash', !empty(GWARR_Photo_Match::stored(7)['sha']) && GWARR_Photo_Match::stored(7)['dh'] === null);

$kinds = function ($cid, $other) use ($claim) {
    $k = [];
    foreach (GWARR_Photo_Match::matches($claim($cid)) as $m) if ($m['matchClaimId'] === $other) $k[] = $m['kind'];
    return $k;
};
check('exact copy flags as identical (102 vs 101)', in_array('identical', $kinds(102, 101), true));
check('resized and re-compressed copy flags as near (103 vs 101)', $kinds(103, 101) === ['near']);
check('PNG of the same photo flags as near (104 vs 101)', $kinds(104, 101) === ['near']);
check('original is flagged back against the repeats', count(GWARR_Photo_Match::matches($claim(101))) >= 3);
check('different photos do not match anything (105)', GWARR_Photo_Match::matches($claim(105)) === []);
check('re-uploaded video flags as identical (106 vs 101)', $kinds(106, 101) === ['identical']);
check('new video does not match (107)', GWARR_Photo_Match::matches($claim(107)) === []);

$m104 = GWARR_Photo_Match::matches($claim(104));
check('same customer is marked (104 vs 101 both customer 1)', $m104[0]['sameCustomer'] === true);
check('another customer is marked (102 vs 101)', GWARR_Photo_Match::matches($claim(102))[0]['sameCustomer'] === false);
check('unchecked count reports the missing file on 107', GWARR_Photo_Match::unchecked_count($claim(107)) === 1);

$lines = GWARR_Photo_Match::describe(GWARR_Photo_Match::matches($claim(102)));
check('staff line reads plainly', $lines[0] === 'Identical file to claim #101 (another customer, approved, 1 Oct 2026)');
check('a copy also links to the other copies of the same photo (103, 104)', count($lines) === 3);

$d = GWARR_Photo_Match::stored(5)['dh']; $e = GWARR_Photo_Match::stored(6)['dh'];
check('distinct photos are far apart (> 6 bits)', GWARR_Photo_Match::distance($d, $e) > GWARR_Photo_Match::NEAR);
check('distance of a hash to itself is 0', GWARR_Photo_Match::distance($d, $d) === 0);

array_map('unlink', glob("$dir/*")); @rmdir($dir);
echo $failed ? "\n$failed FAILED\n" : "\nALL PASSED\n";
exit($failed ? 1 : 0);
