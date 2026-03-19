<?php
/**
 * Plugin Name: GALADO Git Sync
 * Description: Auto-sync WordPress plugins from public GitHub repos. Push to GitHub → your site updates automatically via webhook.
 * Version: 1.0.0
 * Author: GALADO
 * Text Domain: galado-git-sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Galado_Git_Sync {

    private $option_key = 'galado_git_sync_repos';
    private $webhook_slug = 'galado-git-sync-webhook';

    public function __construct() {
        // Admin page
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );

        // Webhook endpoint
        add_action( 'rest_api_init', array( $this, 'register_webhook' ) );

        // Admin notices
        add_action( 'admin_notices', array( $this, 'show_notices' ) );
    }

    /**
     * ── Get saved repos ──
     */
    private function get_repos() {
        return get_option( $this->option_key, array() );
    }

    private function save_repos( $repos ) {
        update_option( $this->option_key, $repos );
    }

    /**
     * ── Admin Menu ──
     */
    public function add_admin_menu() {
        add_management_page(
            'GALADO Git Sync',
            'Git Sync',
            'manage_options',
            'galado-git-sync',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * ── Inline Admin Styles ──
     */
    public function admin_styles( $hook ) {
        if ( $hook !== 'tools_page_galado-git-sync' ) return;
        wp_add_inline_style( 'wp-admin', '
            .ggs-wrap { max-width: 860px; }
            .ggs-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px 24px; margin-bottom: 16px; }
            .ggs-card h3 { margin: 0 0 4px; font-size: 15px; }
            .ggs-card p { color: #666; margin: 0 0 12px; font-size: 13px; }
            .ggs-meta { display: flex; gap: 20px; font-size: 12px; color: #888; margin-top: 10px; }
            .ggs-meta span { display: flex; align-items: center; gap: 4px; }
            .ggs-actions { display: flex; gap: 8px; margin-top: 12px; }
            .ggs-actions a, .ggs-actions button { font-size: 13px; padding: 6px 14px; border-radius: 6px; text-decoration: none; cursor: pointer; border: 1px solid #ddd; background: #f9f9f9; color: #333; }
            .ggs-actions a:hover, .ggs-actions button:hover { background: #f0f0f0; }
            .ggs-actions .ggs-sync { background: #1a1a1a; color: #fff; border-color: #1a1a1a; }
            .ggs-actions .ggs-sync:hover { background: #333; }
            .ggs-actions .ggs-remove { color: #d63638; border-color: #d63638; background: #fff; }
            .ggs-add-form { display: grid; grid-template-columns: 1fr 200px auto; gap: 10px; align-items: end; }
            .ggs-add-form label { font-size: 12px; font-weight: 600; color: #555; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px; }
            .ggs-add-form input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; width: 100%; }
            .ggs-add-form button { padding: 8px 20px; background: #1a1a1a; color: #fff; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; height: 38px; white-space: nowrap; }
            .ggs-webhook-url { background: #f5f5f5; padding: 10px 14px; border-radius: 6px; font-family: monospace; font-size: 13px; word-break: break-all; margin-top: 8px; border: 1px solid #e5e5e5; }
            .ggs-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
            .ggs-badge-ok { background: #e6f4ea; color: #137333; }
            .ggs-badge-miss { background: #fef7e0; color: #b45309; }
            .ggs-empty { text-align: center; padding: 40px; color: #999; }
            @media (max-width: 782px) { .ggs-add-form { grid-template-columns: 1fr; } }
        ' );
    }

    /**
     * ── Admin Page ──
     */
    public function render_admin_page() {
        $repos = $this->get_repos();
        $webhook_url = rest_url( 'galado-git-sync/v1/webhook' );
        $secret = get_option( 'galado_git_sync_secret', '' );
        if ( empty( $secret ) ) {
            $secret = wp_generate_password( 32, false );
            update_option( 'galado_git_sync_secret', $secret );
        }
        ?>
        <div class="wrap ggs-wrap">
            <h1 style="margin-bottom:4px;">GALADO Git Sync</h1>
            <p style="color:#666;margin-bottom:20px;">Sync WordPress plugins from public GitHub repos. Push to GitHub and your site updates automatically.</p>

            <!-- Add New Repo -->
            <div class="ggs-card">
                <h3>Add Repository</h3>
                <p>Enter the GitHub repo URL and the plugin folder name it should map to.</p>
                <form method="post" class="ggs-add-form">
                    <?php wp_nonce_field( 'ggs_add_repo' ); ?>
                    <div>
                        <label>GitHub Repository URL</label>
                        <input type="url" name="repo_url" placeholder="https://github.com/user/repo" required>
                    </div>
                    <div>
                        <label>Plugin Folder Name</label>
                        <input type="text" name="plugin_folder" placeholder="e.g. galado-font-preview" required>
                    </div>
                    <button type="submit" name="ggs_action" value="add_repo">Add</button>
                </form>
            </div>

            <!-- Webhook Info -->
            <div class="ggs-card">
                <h3>Webhook (Auto-Sync on Push)</h3>
                <p>Add this URL as a webhook in each GitHub repo → Settings → Webhooks → Add webhook. Set Content type to <code>application/json</code>.</p>
                <div class="ggs-webhook-url"><?php echo esc_html( $webhook_url ); ?></div>
                <p style="margin-top:8px;font-size:12px;color:#888;">
                    Secret: <code><?php echo esc_html( $secret ); ?></code> (optional — add as webhook secret for security)
                </p>
            </div>

            <!-- Repos List -->
            <?php if ( empty( $repos ) ) : ?>
                <div class="ggs-card ggs-empty">
                    <p>No repositories added yet. Add one above to get started.</p>
                </div>
            <?php else : ?>
                <?php foreach ( $repos as $key => $repo ) : ?>
                    <div class="ggs-card">
                        <div style="display:flex;justify-content:space-between;align-items:start;">
                            <div>
                                <h3><?php echo esc_html( $repo['folder'] ); ?></h3>
                                <p style="font-family:monospace;font-size:12px;"><?php echo esc_html( $repo['url'] ); ?></p>
                            </div>
                            <?php
                            $plugin_dir = WP_PLUGIN_DIR . '/' . $repo['folder'];
                            $installed = is_dir( $plugin_dir );
                            ?>
                            <span class="ggs-badge <?php echo $installed ? 'ggs-badge-ok' : 'ggs-badge-miss'; ?>">
                                <?php echo $installed ? 'Installed' : 'Not Found'; ?>
                            </span>
                        </div>
                        <div class="ggs-meta">
                            <?php if ( ! empty( $repo['last_sync'] ) ) : ?>
                                <span>Last sync: <?php echo esc_html( human_time_diff( strtotime( $repo['last_sync'] ) ) ); ?> ago</span>
                            <?php else : ?>
                                <span>Never synced</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $repo['subfolder'] ) ) : ?>
                                <span>Subfolder: <?php echo esc_html( $repo['subfolder'] ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ggs-actions">
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'ggs_sync_' . $key ); ?>
                                <input type="hidden" name="repo_key" value="<?php echo esc_attr( $key ); ?>">
                                <button type="submit" name="ggs_action" value="sync_repo" class="ggs-sync">Sync Now</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Remove this repo from tracking?');">
                                <?php wp_nonce_field( 'ggs_remove_' . $key ); ?>
                                <input type="hidden" name="repo_key" value="<?php echo esc_attr( $key ); ?>">
                                <button type="submit" name="ggs_action" value="remove_repo" class="ggs-remove">Remove</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ── Handle Admin Actions ──
     */
    public function handle_admin_actions() {
        if ( ! isset( $_POST['ggs_action'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $action = $_POST['ggs_action'];

        if ( $action === 'add_repo' ) {
            check_admin_nonce( 'ggs_add_repo' );
            $url    = esc_url_raw( trim( $_POST['repo_url'] ) );
            $folder = sanitize_file_name( trim( $_POST['plugin_folder'] ) );

            if ( empty( $url ) || empty( $folder ) ) {
                $this->set_notice( 'Please fill in both fields.', 'error' );
                return;
            }

            // Parse GitHub URL
            $parsed = $this->parse_github_url( $url );
            if ( ! $parsed ) {
                $this->set_notice( 'Invalid GitHub URL. Use format: https://github.com/user/repo', 'error' );
                return;
            }

            $repos = $this->get_repos();
            $repos[] = array(
                'url'       => $url,
                'owner'     => $parsed['owner'],
                'repo'      => $parsed['repo'],
                'subfolder' => $parsed['subfolder'],
                'branch'    => $parsed['branch'] ?: 'main',
                'folder'    => $folder,
                'last_sync' => '',
            );
            $this->save_repos( $repos );
            $this->set_notice( "Repository added: {$folder}. Click 'Sync Now' to install.", 'success' );
        }

        if ( $action === 'sync_repo' ) {
            $key = intval( $_POST['repo_key'] );
            check_admin_nonce( 'ggs_sync_' . $key );
            $result = $this->sync_repo( $key );
            if ( is_wp_error( $result ) ) {
                $this->set_notice( 'Sync failed: ' . $result->get_error_message(), 'error' );
            } else {
                $this->set_notice( 'Synced successfully!', 'success' );
            }
        }

        if ( $action === 'remove_repo' ) {
            $key = intval( $_POST['repo_key'] );
            check_admin_nonce( 'ggs_remove_' . $key );
            $repos = $this->get_repos();
            unset( $repos[ $key ] );
            $this->save_repos( array_values( $repos ) );
            $this->set_notice( 'Repository removed.', 'success' );
        }
    }

    /**
     * ── Parse GitHub URL ──
     */
    private function parse_github_url( $url ) {
        // Match: https://github.com/owner/repo or https://github.com/owner/repo/tree/branch/subfolder
        $pattern = '#github\.com/([^/]+)/([^/]+?)(?:\.git)?(?:/tree/([^/]+)(?:/(.+))?)?$#';
        if ( preg_match( $pattern, $url, $m ) ) {
            return array(
                'owner'     => $m[1],
                'repo'      => $m[2],
                'branch'    => isset( $m[3] ) ? $m[3] : '',
                'subfolder' => isset( $m[4] ) ? trim( $m[4], '/' ) : '',
            );
        }
        return false;
    }

    /**
     * ── Sync a repo ──
     */
    public function sync_repo( $key ) {
        $repos = $this->get_repos();
        if ( ! isset( $repos[ $key ] ) ) {
            return new WP_Error( 'not_found', 'Repository not found.' );
        }

        $repo   = $repos[ $key ];
        $owner  = $repo['owner'];
        $name   = $repo['repo'];
        $branch = $repo['branch'] ?: 'main';
        $folder = $repo['folder'];
        $subfolder = ! empty( $repo['subfolder'] ) ? $repo['subfolder'] : '';

        // Download zip from GitHub
        $zip_url = "https://github.com/{$owner}/{$name}/archive/refs/heads/{$branch}.zip";
        $tmp_file = download_url( $zip_url, 60 );

        if ( is_wp_error( $tmp_file ) ) {
            return new WP_Error( 'download_failed', 'Could not download from GitHub: ' . $tmp_file->get_error_message() );
        }

        // Extract
        $tmp_dir = sys_get_temp_dir() . '/ggs_' . uniqid();
        $unzip   = unzip_file( $tmp_file, $tmp_dir );
        @unlink( $tmp_file );

        if ( is_wp_error( $unzip ) ) {
            $this->rmdir_recursive( $tmp_dir );
            return new WP_Error( 'unzip_failed', 'Could not extract zip: ' . $unzip->get_error_message() );
        }

        // Find the extracted folder (GitHub names it repo-branch)
        $extracted = glob( $tmp_dir . '/*', GLOB_ONLYDIR );
        if ( empty( $extracted ) ) {
            $this->rmdir_recursive( $tmp_dir );
            return new WP_Error( 'empty_zip', 'Zip archive was empty.' );
        }

        $source = $extracted[0];

        // If subfolder specified, point to it
        if ( ! empty( $subfolder ) ) {
            $source = $source . '/' . $subfolder;
            if ( ! is_dir( $source ) ) {
                $this->rmdir_recursive( $tmp_dir );
                return new WP_Error( 'subfolder_missing', "Subfolder '{$subfolder}' not found in repository." );
            }
        }

        // Target plugin directory
        $target = WP_PLUGIN_DIR . '/' . $folder;

        // Remove existing plugin files (but not the entire wp-content/plugins!)
        if ( is_dir( $target ) ) {
            $this->rmdir_recursive( $target );
        }

        // Copy source to target
        $this->copy_recursive( $source, $target );

        // Cleanup
        $this->rmdir_recursive( $tmp_dir );

        // Update last sync time
        $repos[ $key ]['last_sync'] = current_time( 'mysql' );
        $this->save_repos( $repos );

        return true;
    }

    /**
     * ── Webhook Endpoint ──
     */
    public function register_webhook() {
        register_rest_route( 'galado-git-sync/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function handle_webhook( $request ) {
        $body = $request->get_json_params();

        // Optional: verify secret
        $secret = get_option( 'galado_git_sync_secret', '' );
        if ( ! empty( $secret ) ) {
            $signature = $request->get_header( 'X-Hub-Signature-256' );
            if ( $signature ) {
                $expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );
                if ( ! hash_equals( $expected, $signature ) ) {
                    return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 403 );
                }
            }
        }

        // Get repo full name from payload
        $repo_full = '';
        if ( isset( $body['repository']['full_name'] ) ) {
            $repo_full = $body['repository']['full_name']; // e.g. "Xylement/galado-order-form"
        }

        if ( empty( $repo_full ) ) {
            return new WP_REST_Response( array( 'error' => 'No repository info in payload' ), 400 );
        }

        // Find matching repos and sync them
        $repos = $this->get_repos();
        $synced = array();

        foreach ( $repos as $key => $repo ) {
            $full = $repo['owner'] . '/' . $repo['repo'];
            if ( strtolower( $full ) === strtolower( $repo_full ) ) {
                $result = $this->sync_repo( $key );
                $synced[] = array(
                    'folder' => $repo['folder'],
                    'status' => is_wp_error( $result ) ? $result->get_error_message() : 'success',
                );
            }
        }

        if ( empty( $synced ) ) {
            return new WP_REST_Response( array( 'message' => 'No matching repos found for ' . $repo_full ), 200 );
        }

        return new WP_REST_Response( array( 'synced' => $synced ), 200 );
    }

    /**
     * ── Helpers ──
     */
    private function copy_recursive( $src, $dst ) {
        $dir = opendir( $src );
        @mkdir( $dst, 0755, true );
        while ( ( $file = readdir( $dir ) ) !== false ) {
            if ( $file === '.' || $file === '..' ) continue;
            $s = $src . '/' . $file;
            $d = $dst . '/' . $file;
            if ( is_dir( $s ) ) {
                $this->copy_recursive( $s, $d );
            } else {
                copy( $s, $d );
            }
        }
        closedir( $dir );
    }

    private function rmdir_recursive( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getRealPath() );
            } else {
                unlink( $item->getRealPath() );
            }
        }
        rmdir( $dir );
    }

    /**
     * ── Notices ──
     */
    private function set_notice( $message, $type = 'success' ) {
        set_transient( 'ggs_notice', array( 'message' => $message, 'type' => $type ), 30 );
    }

    public function show_notices() {
        $notice = get_transient( 'ggs_notice' );
        if ( ! $notice ) return;
        delete_transient( 'ggs_notice' );
        $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
    }
}

// Helper: check_admin_nonce
if ( ! function_exists( 'check_admin_nonce' ) ) {
    function check_admin_nonce( $action ) {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], $action ) ) {
            wp_die( 'Security check failed.' );
        }
    }
}

new Galado_Git_Sync();
