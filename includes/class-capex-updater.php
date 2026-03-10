<?php
/**
 * Hooks into the WordPress update system to check GitHub Releases for plugin updates.
 *
 * Checks https://api.github.com/repos/{owner}/{repo}/releases/latest
 * and injects update data when a newer tag is found. Response is cached
 * for 12 hours via a WP transient to stay well within GitHub's rate limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capex_Updater {

    /** @var string Absolute path to main plugin file. */
    private $plugin_file;

    /** @var string WordPress plugin identifier: "capex-creditinfo-sso/capex-creditinfo-sso.php". */
    private $plugin_slug;

    /** @var string GitHub repository owner. */
    private $github_owner;

    /** @var string GitHub repository name. */
    private $github_repo;

    /** @var string Currently installed version. */
    private $current_version;

    /** @var string WP transient key for caching the API response. */
    private $transient_key = 'capex_updater_response';

    /** @var int Cache lifetime in seconds (12 hours). */
    private $cache_ttl = 43200;

    /**
     * @param string $plugin_file     __FILE__ from main plugin file.
     * @param string $github_owner    GitHub username / org.
     * @param string $github_repo     GitHub repository name.
     * @param string $current_version CAPEX_VERSION constant.
     */
    public function __construct( $plugin_file, $github_owner, $github_repo, $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->github_owner    = $github_owner;
        $this->github_repo     = $github_repo;
        $this->current_version = $current_version;

        $this->register_hooks();
    }

    private function register_hooks() {
        // Inject update data into WordPress update check.
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        // Provide plugin info for "View version details" modal.
        add_filter( 'plugins_api',                          [ $this, 'plugin_info'       ], 10, 3 );
        // Purge cache after plugin upgrade completes.
        add_action( 'upgrader_process_complete',            [ $this, 'purge_transient'   ], 10, 2 );
        // Purge cache when "Check Again" is clicked (deletes update_plugins site transient).
        add_action( 'delete_site_transient_update_plugins', [ $this, 'purge_github_cache' ] );
    }

    /**
     * Fetch the latest release from GitHub, with caching.
     *
     * @return array|false
     */
    private function get_latest_release() {
        $cached = get_transient( $this->transient_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode( $this->github_owner ),
            rawurlencode( $this->github_repo )
        );

        $response = wp_remote_get( $api_url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            set_transient( $this->transient_key, false, 300 );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
            set_transient( $this->transient_key, false, 300 );
            return false;
        }

        $package_url = '';
        if ( ! empty( $data['assets'] ) ) {
            foreach ( $data['assets'] as $asset ) {
                if ( ! empty( $asset['browser_download_url'] )
                    && substr( $asset['browser_download_url'], -4 ) === '.zip' ) {
                    $package_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $release = [
            'version'     => ltrim( $data['tag_name'], 'v' ),
            'package_url' => $package_url,
            'body'        => $data['body']         ?? '',
            'published'   => $data['published_at'] ?? '',
        ];

        set_transient( $this->transient_key, $release, $this->cache_ttl );

        return $release;
    }

    /**
     * Inject update info when a newer version is available on GitHub.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        if ( version_compare( $release['version'], $this->current_version, '>' ) ) {
            $update              = new stdClass();
            $update->id          = $this->github_repo;
            $update->slug        = dirname( $this->plugin_slug );
            $update->plugin      = $this->plugin_slug;
            $update->new_version = $release['version'];
            $update->url         = 'https://github.com/' . $this->github_owner . '/' . $this->github_repo;
            $update->package     = $release['package_url'];
            $update->icons       = [];
            $update->banners     = [];
            $update->tested      = get_bloginfo( 'version' );
            $update->requires_php = '7.4';
            $update->compatibility = new stdClass();

            $transient->response[ $this->plugin_slug ] = $update;
        } else {
            unset( $transient->response[ $this->plugin_slug ] );
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View version details" modal in WP Admin.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( empty( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release     = $this->get_latest_release();
        $plugin_data = get_plugin_data( $this->plugin_file );

        $info                = new stdClass();
        $info->name          = $plugin_data['Name'];
        $info->slug          = dirname( $this->plugin_slug );
        $info->version       = $release ? $release['version'] : $this->current_version;
        $info->author        = $plugin_data['Author'];
        $info->homepage      = 'https://github.com/' . $this->github_owner . '/' . $this->github_repo;
        $info->requires      = '5.8';
        $info->requires_php  = '7.4';
        $info->tested        = get_bloginfo( 'version' );
        $info->last_updated  = $release ? $release['published'] : '';
        $info->download_link = $release ? $release['package_url'] : '';
        $info->sections      = [
            'description' => $plugin_data['Description'],
            'changelog'   => ( $release && ! empty( $release['body'] ) )
                ? nl2br( esc_html( $release['body'] ) )
                : 'See <a href="https://github.com/' . esc_attr( $this->github_owner ) . '/' . esc_attr( $this->github_repo ) . '/releases" target="_blank">GitHub Releases</a> for the full changelog.',
        ];

        return $info;
    }

    /**
     * Clear the cached release after plugin update completes.
     */
    public function purge_transient( $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
            return;
        }

        $updated = [];
        if ( ! empty( $hook_extra['plugins'] ) ) {
            $updated = (array) $hook_extra['plugins'];
        } elseif ( ! empty( $hook_extra['plugin'] ) ) {
            $updated = [ $hook_extra['plugin'] ];
        }

        if ( in_array( $this->plugin_slug, $updated, true ) ) {
            delete_transient( $this->transient_key );
        }
    }

    /**
     * `delete_site_transient_update_plugins` callback.
     * Fires when WordPress clears its update check cache (e.g. "Check Again" button).
     * Purges our GitHub API cache so the next check fetches fresh data.
     */
    public function purge_github_cache() {
        delete_transient( $this->transient_key );
    }
}
