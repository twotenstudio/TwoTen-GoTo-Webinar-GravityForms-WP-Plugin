<?php
/**
 * GitHub release updater.
 *
 * Uses the core `Update URI` plugin header (WordPress 5.8+). Core calls the
 * `update_plugins_github.com` filter for this plugin and we answer with the
 * latest published GitHub release, so updates appear on the Plugins screen
 * and on Dashboard > Updates and install like any other plugin update.
 *
 * Private repositories: define `TTS_GTW_GITHUB_TOKEN` in wp-config.php with a
 * fine-grained personal access token that has read access to the repository
 * contents. The token is sent to api.github.com and codeload.github.com only.
 *
 * Disable entirely with `add_filter( 'tts_gtw_github_updates', '__return_false' );`
 *
 * @package TwoTen\GoToWebinar
 */

defined( 'ABSPATH' ) || exit;

class TTS_GTW_Updater {

	const CACHE_KEY = 'tts_gtw_github_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const FAIL_TTL  = 15 * MINUTE_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'update_check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_selection' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'inject_auth_header' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( TTS_GTW_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Whether GitHub-based updates are enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filters whether the plugin checks GitHub releases for updates.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'tts_gtw_github_updates', true );
	}

	/**
	 * Personal access token for private repositories, if configured.
	 *
	 * @return string
	 */
	public static function token() {
		$token = defined( 'TTS_GTW_GITHUB_TOKEN' ) ? (string) TTS_GTW_GITHUB_TOKEN : '';

		/**
		 * Filters the GitHub token used to read releases from a private repository.
		 *
		 * @param string $token Token, or empty string for a public repository.
		 */
		return (string) apply_filters( 'tts_gtw_github_token', $token );
	}

	/**
	 * Fetch the latest GitHub release, cached for six hours.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array { version, package, url, notes, published } or { error } on failure.
	 */
	public static function release( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
		);
		$token   = self::token();
		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . TTS_GTW_GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::remember_failure( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['tag_name'] ) ) {
			$detail = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : '';
			return self::remember_failure( sprintf( 'HTTP %d%s', $code, $detail ? ': ' . $detail : '' ) );
		}

		$tag     = (string) $body['tag_name'];
		$version = ltrim( $tag, 'vV' );

		if ( $token ) {
			// Private repo: the API zipball is the only archive that accepts a
			// token. It unpacks as "owner-repo-hash" and is renamed on install.
			$package = ! empty( $body['zipball_url'] )
				? (string) $body['zipball_url']
				: 'https://api.github.com/repos/' . TTS_GTW_GITHUB_REPO . '/zipball/' . rawurlencode( $tag );
		} else {
			// Public repo: prefer the purpose-built ZIP attached to the release,
			// fall back to the tag archive (renamed on install).
			$package = 'https://github.com/' . TTS_GTW_GITHUB_REPO . '/archive/refs/tags/' . rawurlencode( $tag ) . '.zip';
			if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
				foreach ( $body['assets'] as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && TTS_GTW_SLUG . '.zip' === $asset['name'] ) {
						$package = (string) $asset['browser_download_url'];
						break;
					}
				}
			}
		}

		$release = array(
			'version'   => $version,
			'package'   => $package,
			'url'       => ! empty( $body['html_url'] ) ? (string) $body['html_url'] : 'https://github.com/' . TTS_GTW_GITHUB_REPO . '/releases',
			'notes'     => ! empty( $body['body'] ) ? (string) $body['body'] : '',
			'published' => ! empty( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Cache a failed lookup briefly so an outage or rate limit doesn't turn
	 * every admin page load into an API call.
	 *
	 * @param string $error Human-readable reason.
	 * @return array
	 */
	private static function remember_failure( $error ) {
		$release = array( 'error' => (string) $error );
		set_site_transient( self::CACHE_KEY, $release, self::FAIL_TTL );
		return $release;
	}

	/**
	 * Answer core's update check with the latest GitHub release. Core compares
	 * `version` against the installed version itself.
	 *
	 * @param array|false $update      Existing update data.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function update_check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( TTS_GTW_FILE ) !== $plugin_file || ! self::enabled() ) {
			return $update;
		}

		$release = self::release();
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return $update;
		}

		return array(
			'id'           => 'https://github.com/' . TTS_GTW_GITHUB_REPO,
			'slug'         => TTS_GTW_SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => ! empty( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '6.0',
			'requires_php' => ! empty( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '7.4',
		);
	}

	/**
	 * Serve the "View version details" modal from GitHub instead of WordPress.org.
	 *
	 * @param false|object|array $result Existing result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || TTS_GTW_SLUG !== $args->slug || ! self::enabled() ) {
			return $result;
		}

		$release = self::release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}

		$plugin = self::plugin_data();

		$changelog = $release['notes']
			? wpautop( esc_html( $release['notes'] ) )
			: '<p><a href="' . esc_url( $release['url'] ) . '">' . esc_html__( 'See the release on GitHub.', 'twoten-goto-webinar-gravityforms' ) . '</a></p>';

		return (object) array(
			'name'          => $plugin['Name'],
			'slug'          => TTS_GTW_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://twotenstudio.co.uk">TwoTen Studio</a>',
			'homepage'      => 'https://github.com/' . TTS_GTW_GITHUB_REPO,
			'download_link' => $release['package'],
			'requires'      => $plugin['RequiresWP'],
			'requires_php'  => $plugin['RequiresPHP'],
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => wp_kses_post( $plugin['Description'] ),
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * Make sure the update installs into the plugin's own folder.
	 *
	 * GitHub archives unpack as `<repo>-<tag>/` or `<owner>-<repo>-<hash>/`;
	 * renaming to the plugin slug keeps the plugin path (and its active
	 * state) stable across updates.
	 *
	 * @param string      $source        Unpacked source folder.
	 * @param string      $remote_source Parent temp folder.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra data; `plugin` is the basename being updated.
	 * @return string|WP_Error
	 */
	public static function source_selection( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( TTS_GTW_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . TTS_GTW_SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}

		return new WP_Error(
			'tts_gtw_rename_failed',
			__( 'Could not rename the downloaded update folder.', 'twoten-goto-webinar-gravityforms' )
		);
	}

	/**
	 * Send the token with GitHub download requests so WordPress can fetch the
	 * package from a private repository.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public static function inject_auth_header( $args, $url ) {
		$token = self::token();
		if ( ! $token || ! self::enabled() ) {
			return $args;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'api.github.com', 'codeload.github.com' ), true ) ) {
			return $args;
		}

		if ( false === strpos( $url, TTS_GTW_GITHUB_REPO ) && false === strpos( $url, str_replace( '/', '-', TTS_GTW_GITHUB_REPO ) ) ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		$args['headers']['Authorization'] = 'Bearer ' . $token;

		return $args;
	}

	/**
	 * URL for a manual update check.
	 *
	 * @param string $return Where to land afterwards: 'plugins' (default) or 'settings'.
	 * @return string
	 */
	public static function check_url( $return = 'plugins' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'tts_gtw_check_updates' => '1',
					'tts_gtw_return'        => 'settings' === $return ? 'settings' : 'plugins',
				),
				self_admin_url( 'plugins.php' )
			),
			'tts_gtw_check_updates'
		);
	}

	/**
	 * Add a "Check for updates" link to the plugin row.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		if ( class_exists( 'GFForms' ) && class_exists( 'GF_GoTo_Webinar' ) ) {
			array_unshift(
				$links,
				'<a href="' . esc_url( admin_url( 'admin.php?page=gf_settings&subview=twoten-goto-webinar' ) ) . '">' . esc_html__( 'Settings', 'twoten-goto-webinar-gravityforms' ) . '</a>'
			);
		}

		if ( self::enabled() && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( self::check_url( 'plugins' ) ) . '">' . esc_html__( 'Check for updates', 'twoten-goto-webinar-gravityforms' ) . '</a>';
		}

		return $links;
	}

	/**
	 * Handle the "Check for updates" action: refresh the release cache and
	 * core's plugin update data, then redirect back with a status notice.
	 */
	public static function handle_check() {
		if ( empty( $_GET['tts_gtw_check_updates'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) || ! self::enabled() ) {
			return;
		}
		check_admin_referer( 'tts_gtw_check_updates' );

		delete_site_transient( self::CACHE_KEY );
		$release = self::release( true );

		// Clear core's cache so the next check runs immediately and picks up
		// the fresh release data.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$plugin = self::plugin_data();

		if ( empty( $release['version'] ) ) {
			$status = 'error';
		} elseif ( version_compare( $plugin['Version'], $release['version'], '<' ) ) {
			$status = 'available';
		} else {
			$status = 'current';
		}

		$return = isset( $_GET['tts_gtw_return'] ) && 'settings' === $_GET['tts_gtw_return']
			? admin_url( 'admin.php?page=gf_settings&subview=twoten-goto-webinar' )
			: self_admin_url( 'plugins.php' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'tts_gtw_checked' => $status,
					'tts_gtw_latest'  => ! empty( $release['version'] ) ? $release['version'] : '',
					'tts_gtw_error'   => ! empty( $release['error'] ) ? rawurlencode( $release['error'] ) : '',
				),
				$return
			)
		);
		exit;
	}

	/**
	 * Show the result of a manual update check.
	 */
	public static function notices() {
		if ( empty( $_GET['tts_gtw_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['tts_gtw_checked'] ) );
		$latest = isset( $_GET['tts_gtw_latest'] ) ? sanitize_text_field( wp_unslash( $_GET['tts_gtw_latest'] ) ) : '';
		$error  = isset( $_GET['tts_gtw_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['tts_gtw_error'] ) ) ) : '';

		switch ( $status ) {
			case 'available':
				$class   = 'notice-warning';
				$message = sprintf(
					/* translators: 1: version number, 2: URL of the plugins screen */
					__( 'TwoTen GoTo Webinar for Gravity Forms: version %1$s is available on GitHub. Install it from the <a href="%2$s">Plugins</a> screen.', 'twoten-goto-webinar-gravityforms' ),
					esc_html( $latest ),
					esc_url( self_admin_url( 'plugins.php' ) )
				);
				break;
			case 'current':
				$class   = 'notice-success';
				$message = sprintf(
					/* translators: %s: version number */
					esc_html__( 'TwoTen GoTo Webinar for Gravity Forms is up to date (latest release: %s).', 'twoten-goto-webinar-gravityforms' ),
					esc_html( $latest )
				);
				break;
			default:
				$class   = 'notice-error';
				$message = esc_html__( 'TwoTen GoTo Webinar for Gravity Forms could not fetch the latest release from GitHub. Check that a release has been published and, for a private repository, that TTS_GTW_GITHUB_TOKEN is set in wp-config.php.', 'twoten-goto-webinar-gravityforms' );
				if ( $error ) {
					$message .= ' <code>' . esc_html( $error ) . '</code>';
				}
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			wp_kses( $message, array( 'a' => array( 'href' => array() ), 'code' => array() ) )
		);
	}

	/**
	 * Plugin header data.
	 *
	 * @return array
	 */
	private static function plugin_data() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugin_data( TTS_GTW_FILE, false, false );
	}
}
