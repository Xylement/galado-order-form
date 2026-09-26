<?php
/**
 * Runs every unit test file in its own PHP process (so their stubs never collide).
 *   php tests/run.php
 * Integration tests against real WooCommerce: tests/integration/run.sh (needs Docker).
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$php = escapeshellarg(PHP_BINARY);
$failed = [];
$files = glob(__DIR__ . '/unit/test-*.php');
sort($files);
foreach ($files as $file) {
    $out = [];
    exec($php . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $last = end($out);
    echo str_pad(basename($file), 34) . ($code === 0 ? 'ok    ' : 'FAIL  ') . $last . "\n";
    if ($code !== 0) {
        $failed[] = basename($file);
        foreach ($out as $line) {
            if (0 === strpos($line, 'FAIL') || 0 === strpos($line, '     ') || false !== stripos($line, 'error')) {
                echo '    ' . $line . "\n";
            }
        }
    }
}
echo $failed ? "\nFAILED: " . implode(', ', $failed) . "\n" : "\nAll unit test files passed on PHP " . PHP_VERSION . ".\n";
exit($failed ? 1 : 0);
