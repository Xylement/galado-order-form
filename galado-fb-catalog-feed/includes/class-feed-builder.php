<?php
/**
 * Batched, background builder for the Facebook catalog feed.
 *
 * Critical design rule: NO catalog work ever happens during a normal web
 * request. Building runs only in WP-Cron ticks. Each tick is time-boxed
 * (TIME_BUDGET seconds), processes a small batch, appends to temp files,
 * then either reschedules itself or finalises by atomically swapping the
 * temp files into place. This makes it impossible for the feed to slow
 * down or overload the site, even under a crawler flood.
 */

if (!defined('ABSPATH')) exit;

class GFBF_Feed_Builder {

    const STATE_OPTION       = 'gfbf_build_state';
    const LOCK_TRANSIENT     = 'gfbf_batch_lock';
    const PRODUCTS_PER_QUERY = 20;    // products fetched per inner iteration
    const TIME_BUDGET        = 20;    // seconds of work per cron tick
    const ROW_SOFT_CAP       = 20000; // hard safety cap on rows per build

    /**
     * Default build state.
     */
    public static function default_state() {
        return [
            'status'      => 'idle',   // idle | running | done | error
            'offset'      => 0,
            'rows'        => 0,
            'started_at'  => '',
            'started_ts'  => 0,        // UTC epoch — used for staleness checks
            'finished_at' => '',
            'message'     => '',
        ];
    }

    public static function get_state() {
        $state = get_option(self::STATE_OPTION, []);
        return is_array($state)
            ? array_merge(self::default_state(), $state)
            : self::default_state();
    }

    private static function save_state($state) {
        update_option(self::STATE_OPTION, $state, false);
    }

    // =========================================================================
    // PATHS
    // =========================================================================

    public static function feed_dir() {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'galado-fb-feed';
    }

    /**
     * Token-derived filename component, so the feed file isn't trivially
     * guessable at its uploads path.
     */
    private static function token_slug() {
        $settings = get_option('gfbf_settings', []);
        $token = isset($settings['token'])
            ? preg_replace('/[^A-Za-z0-9]/', '', (string) $settings['token'])
            : '';
        return $token !== '' ? $token : 'feed';
    }

    public static function feed_path($format) {
        $format = $format === 'csv' ? 'csv' : 'xml';
        return self::feed_dir() . '/feed-' . self::token_slug() . '.' . $format;
    }

    private static function temp_path($format) {
        return self::feed_path($format) . '.tmp';
    }

    // =========================================================================
    // SCHEDULING
    // =========================================================================

    /**
     * Make sure the recurring rebuild event matches the frequency setting.
     * Called on every load (git-sync deploys skip the activation hook).
     */
    public static function ensure_schedule() {
        $settings = get_option('gfbf_settings', []);
        $freq = isset($settings['frequency']) ? $settings['frequency'] : 'daily';

        if ($freq === 'manual') {
            wp_clear_scheduled_hook('gfbf_scheduled_rebuild');
            return;
        }
        if (!in_array($freq, ['daily', 'twicedaily'], true)) {
            $freq = 'daily';
        }

        $scheduled = wp_get_scheduled_event('gfbf_scheduled_rebuild');
        if (!$scheduled) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, $freq, 'gfbf_scheduled_rebuild');
        } elseif ($scheduled->schedule !== $freq) {
            wp_clear_scheduled_hook('gfbf_scheduled_rebuild');
            wp_schedule_event(time() + HOUR_IN_SECONDS, $freq, 'gfbf_scheduled_rebuild');
        }
    }

    private static function queue_next_batch() {
        if (!wp_next_scheduled('gfbf_run_batch')) {
            wp_schedule_single_event(time(), 'gfbf_run_batch');
        }
        // Nudge WP-Cron so batches flow without waiting for a visitor.
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    // =========================================================================
    // BUILD LIFECYCLE
    // =========================================================================

    /**
     * Begin a fresh build: prepare the directory, write file headers,
     * reset state, and queue the first batch. Does NOT process products.
     */
    public static function start() {
        // Don't stomp a build that's genuinely still in progress — but DO
        // allow restarting one that looks stuck (running for over an hour).
        $current = self::get_state();
        if ($current['status'] === 'running') {
            $age = time() - intval($current['started_ts']);
            if (intval($current['started_ts']) > 0 && $age >= 0 && $age < HOUR_IN_SECONDS) {
                return;
            }
        }

        $dir = self::feed_dir();
        if (!wp_mkdir_p($dir)) {
            self::save_state(array_merge(self::default_state(), [
                'status'  => 'error',
                'message' => 'Could not create feed directory: ' . $dir,
            ]));
            return;
        }
        if (!file_exists($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }

        // Remove stale temp files and any old-token feed files. The current
        // live feed files are left in place so the feed stays available
        // while the new one builds.
        foreach ((array) glob($dir . '/feed-*') as $f) {
            if (!is_file($f)) {
                continue;
            }
            if ($f === self::feed_path('xml') || $f === self::feed_path('csv')) {
                continue;
            }
            @unlink($f);
        }

        require_once GFBF_PATH . 'includes/class-feed-generator.php';
        $settings  = get_option('gfbf_settings', []);
        $generator = new GFBF_Feed_Generator($settings);

        // Fresh temp files with their headers.
        $xml_ok = false !== file_put_contents(self::temp_path('xml'), $generator->xml_header());

        $csv_fh = fopen(self::temp_path('csv'), 'w');
        $csv_ok = false;
        if ($csv_fh) {
            $generator->write_csv_header($csv_fh);
            fclose($csv_fh);
            $csv_ok = true;
        }

        if (!$xml_ok || !$csv_ok) {
            self::save_state(array_merge(self::default_state(), [
                'status'  => 'error',
                'message' => 'Could not write feed temp files — check uploads folder permissions.',
            ]));
            return;
        }

        self::save_state([
            'status'      => 'running',
            'offset'      => 0,
            'rows'        => 0,
            'started_at'  => current_time('mysql'),
            'started_ts'  => time(),
            'finished_at' => '',
            'message'     => '',
        ]);

        self::queue_next_batch();
    }

    /**
     * Process one time-boxed batch. Reschedules itself until the catalog is
     * exhausted, then finalises. Safe to call repeatedly — a transient lock
     * prevents overlapping runs.
     */
    public static function run_batch() {
        $state = self::get_state();
        if ($state['status'] !== 'running') {
            return;
        }
        if (get_transient(self::LOCK_TRANSIENT)) {
            return; // Another tick is already working.
        }
        set_transient(self::LOCK_TRANSIENT, 1, 2 * MINUTE_IN_SECONDS);

        // Generous ceiling, but we self-limit well under it via TIME_BUDGET.
        @set_time_limit(60);
        // Keep the object cache from ballooning during a long batch.
        if (function_exists('wp_suspend_cache_addition')) {
            wp_suspend_cache_addition(true);
        }

        require_once GFBF_PATH . 'includes/class-feed-generator.php';
        $settings  = get_option('gfbf_settings', []);
        $generator = new GFBF_Feed_Generator($settings);

        $deadline = microtime(true) + self::TIME_BUDGET;
        $done     = false;

        try {
            $xml_fh = fopen(self::temp_path('xml'), 'a');
            $csv_fh = fopen(self::temp_path('csv'), 'a');
            if (!$xml_fh || !$csv_fh) {
                throw new Exception('Could not open feed temp files for append — has a build been started?');
            }

            do {
                $products = wc_get_products([
                    'status'  => 'publish',
                    'limit'   => self::PRODUCTS_PER_QUERY,
                    'offset'  => $state['offset'],
                    'orderby' => 'ID',
                    'order'   => 'ASC',
                    'return'  => 'objects',
                ]);

                if (empty($products)) {
                    $done = true;
                    break;
                }

                foreach ($products as $product) {
                    foreach ($generator->rows_for_product($product) as $row) {
                        fwrite($xml_fh, $generator->row_to_xml($row));
                        $generator->write_csv_row($csv_fh, $row);
                        $state['rows']++;
                    }
                }
                $state['offset'] += count($products);

                if ($state['rows'] >= self::ROW_SOFT_CAP) {
                    $done = true;
                    break;
                }
            } while (microtime(true) < $deadline);

            fclose($xml_fh);
            fclose($csv_fh);

            if ($done) {
                self::finalize($generator, $state);
            } else {
                // More to do — persist progress and queue the next tick.
                self::save_state($state);
                delete_transient(self::LOCK_TRANSIENT);
                self::queue_next_batch();
                return;
            }
        } catch (Throwable $e) {
            $state['status']  = 'error';
            $state['message'] = $e->getMessage();
            self::save_state($state);
        }

        delete_transient(self::LOCK_TRANSIENT);
    }

    /**
     * Close the XML structure and atomically swap temp files into place.
     */
    private static function finalize($generator, $state) {
        file_put_contents(self::temp_path('xml'), $generator->xml_footer(), FILE_APPEND);

        @rename(self::temp_path('xml'), self::feed_path('xml'));
        @rename(self::temp_path('csv'), self::feed_path('csv'));

        $state['status']      = 'done';
        $state['finished_at'] = current_time('mysql');
        $state['message']     = '';
        self::save_state($state);

        update_option('gfbf_last_generated', current_time('mysql'), false);
    }
}
