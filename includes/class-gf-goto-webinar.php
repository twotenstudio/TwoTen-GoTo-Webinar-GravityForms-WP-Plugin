<?php
/**
 * Gravity Forms feed add-on: register submissions with GoTo Webinar.
 *
 * @package TwoTen\GoToWebinar
 */

defined( 'ABSPATH' ) || exit;

class GF_GoTo_Webinar extends GFFeedAddOn {

	protected $_version                    = TTS_GTW_VERSION;
	protected $_min_gravityforms_version   = TTS_GTW_MIN_GF_VERSION;
	protected $_slug                       = 'twoten-goto-webinar';
	protected $_path                       = 'twoten-goto-webinar-gravityforms/twoten-goto-webinar-gravityforms.php';
	protected $_full_path                  = TTS_GTW_FILE;
	protected $_url                        = 'https://github.com/twotenstudio/TwoTen-GoTo-Webinar-GravityForms-WP-Plugin';
	protected $_title                      = 'GoTo Webinar for Gravity Forms';
	protected $_short_title                = 'GoTo Webinar';
	protected $_multiple_feeds             = true;
	protected $_supports_feed_ordering     = true;
	protected $_enable_rg_autoupgrade      = false;
	protected $_capabilities               = array( 'gravityforms_gotowebinar', 'gravityforms_gotowebinar_uninstall' );
	protected $_capabilities_settings_page = 'gravityforms_gotowebinar';
	protected $_capabilities_form_settings = 'gravityforms_gotowebinar';
	protected $_capabilities_uninstall     = 'gravityforms_gotowebinar_uninstall';

	const WEBINARS_CACHE = 'tts_gtw_webinars';
	const FIELDS_CACHE   = 'tts_gtw_fields';
	const CACHE_TTL      = 10 * MINUTE_IN_SECONDS;
	const STATE_TTL      = 15 * MINUTE_IN_SECONDS;

	/** @var GF_GoTo_Webinar|null */
	private static $_instance = null;

	/**
	 * @return GF_GoTo_Webinar
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		$this->_path = plugin_basename( TTS_GTW_FILE );
		parent::__construct();
	}

	/* ── Lifecycle ─────────────────────────────────────────────────────── */

	public function init() {
		parent::init();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'gform_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 7 );
	}

	public function init_admin() {
		parent::init_admin();

		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_filter( 'gform_custom_merge_tags', array( $this, 'custom_merge_tags' ), 10, 4 );
	}

	public function get_menu_icon() {
		return 'dashicons-video-alt3';
	}

	public function uninstall() {
		parent::uninstall();
		TTS_GTW_API::clear_auth();
		$this->clear_caches();
	}

	/* ── API access ────────────────────────────────────────────────────── */

	/**
	 * API client built from the saved OAuth client credentials.
	 *
	 * @param bool $require_connected Return null unless an organizer is connected.
	 * @return TTS_GTW_API|null
	 */
	public function get_api( $require_connected = true ) {
		$settings = $this->get_plugin_settings();
		$id       = trim( (string) rgar( $settings, 'client_id' ) );
		$secret   = trim( (string) rgar( $settings, 'client_secret' ) );

		if ( '' === $id || '' === $secret ) {
			return null;
		}
		if ( $require_connected && ! TTS_GTW_API::is_connected() ) {
			return null;
		}

		return new TTS_GTW_API( $id, $secret );
	}

	/**
	 * Upcoming webinars, cached for ten minutes.
	 *
	 * @return array|WP_Error
	 */
	public function get_webinars() {
		$cached = get_transient( self::WEBINARS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api = $this->get_api();
		if ( ! $api ) {
			return new WP_Error( 'tts_gtw_not_connected', __( 'GoTo Webinar is not connected.', 'twoten-goto-webinar-gravityforms' ) );
		}

		$webinars = $api->get_webinars();
		if ( is_wp_error( $webinars ) ) {
			$this->log_error( __METHOD__ . '(): ' . $webinars->get_error_message() );
			return $webinars;
		}

		set_transient( self::WEBINARS_CACHE, $webinars, self::CACHE_TTL );

		return $webinars;
	}

	/**
	 * Registration fields and questions for a webinar, cached for ten minutes.
	 *
	 * @param string $webinar_key Webinar key.
	 * @return array|WP_Error
	 */
	public function get_registration_fields( $webinar_key ) {
		$webinar_key = (string) $webinar_key;
		$all         = get_transient( self::FIELDS_CACHE );
		if ( is_array( $all ) && isset( $all[ $webinar_key ] ) && is_array( $all[ $webinar_key ] ) ) {
			return $all[ $webinar_key ];
		}

		$api = $this->get_api();
		if ( ! $api ) {
			return new WP_Error( 'tts_gtw_not_connected', __( 'GoTo Webinar is not connected.', 'twoten-goto-webinar-gravityforms' ) );
		}

		$fields = $api->get_registration_fields( $webinar_key );
		if ( is_wp_error( $fields ) ) {
			$this->log_error( __METHOD__ . '(): ' . $fields->get_error_message() );
			return $fields;
		}

		$all                 = is_array( $all ) ? $all : array();
		$all[ $webinar_key ] = $fields;
		set_transient( self::FIELDS_CACHE, $all, self::CACHE_TTL );

		return $fields;
	}

	public function clear_caches() {
		delete_transient( self::WEBINARS_CACHE );
		delete_transient( self::FIELDS_CACHE );
	}

	/* ── Plugin settings (Forms > Settings > GoTo Webinar) ─────────────── */

	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => esc_html__( 'GoTo Webinar Connection', 'twoten-goto-webinar-gravityforms' ),
				'description' => '<p>' . sprintf(
					/* translators: %s: URL of the GoTo developer portal */
					esc_html__( 'Create an OAuth client at %s with access to GoTo Webinar, add the Redirect URI shown below to it, then enter its Client ID and Client Secret here, save, and click Connect.', 'twoten-goto-webinar-gravityforms' ),
					'<a href="https://developer.goto.com/" target="_blank" rel="noopener">developer.goto.com</a>'
				) . '</p>',
				'fields'      => array(
					array(
						'name'    => 'client_id',
						'label'   => esc_html__( 'Client ID', 'twoten-goto-webinar-gravityforms' ),
						'type'    => 'text',
						'class'   => 'medium',
					),
					array(
						'name'       => 'client_secret',
						'label'      => esc_html__( 'Client Secret', 'twoten-goto-webinar-gravityforms' ),
						'type'       => 'text',
						'input_type' => 'password',
						'class'      => 'medium',
					),
					array(
						'name'  => 'redirect_uri',
						'label' => esc_html__( 'Redirect URI', 'twoten-goto-webinar-gravityforms' ),
						'type'  => 'html',
						'html'  => array( $this, 'render_redirect_uri' ),
					),
					array(
						'name'  => 'connection',
						'label' => esc_html__( 'Connection', 'twoten-goto-webinar-gravityforms' ),
						'type'  => 'html',
						'html'  => array( $this, 'render_connection_status' ),
					),
				),
			),
			array(
				'title'  => esc_html__( 'Plugin Updates', 'twoten-goto-webinar-gravityforms' ),
				'fields' => array(
					array(
						'name'  => 'updates',
						'label' => esc_html__( 'Version', 'twoten-goto-webinar-gravityforms' ),
						'type'  => 'html',
						'html'  => array( $this, 'render_update_status' ),
					),
				),
			),
		);
	}

	public function render_redirect_uri() {
		return '<code>' . esc_html( $this->get_redirect_uri() ) . '</code>'
			. '<p class="description">' . esc_html__( 'Add this exact URL as a Redirect URI on your GoTo OAuth client.', 'twoten-goto-webinar-gravityforms' ) . '</p>';
	}

	public function render_connection_status() {
		if ( TTS_GTW_API::is_connected() ) {
			$auth = TTS_GTW_API::get_auth();
			$who  = trim( rgar( $auth, 'firstName' ) . ' ' . rgar( $auth, 'lastName' ) );
			$who  = $who ? $who . ' &lt;' . esc_html( rgar( $auth, 'email' ) ) . '&gt;' : esc_html( rgar( $auth, 'email', __( 'unknown user', 'twoten-goto-webinar-gravityforms' ) ) );

			$html  = '<p><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> ';
			$html .= sprintf(
				/* translators: 1: user name/email, 2: organizer key */
				esc_html__( 'Connected as %1$s (organizer key %2$s).', 'twoten-goto-webinar-gravityforms' ),
				'<strong>' . $who . '</strong>',
				'<code>' . esc_html( rgar( $auth, 'organizer_key' ) ) . '</code>'
			);
			if ( ! empty( $auth['connected_at'] ) ) {
				$html .= ' ' . sprintf(
					/* translators: %s: date */
					esc_html__( 'Connected on %s.', 'twoten-goto-webinar-gravityforms' ),
					esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $auth['connected_at'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) )
				);
			}
			$html .= '</p>';
			$html .= '<p><a class="button" href="' . esc_url( $this->action_url( 'disconnect' ) ) . '">' . esc_html__( 'Disconnect', 'twoten-goto-webinar-gravityforms' ) . '</a> ';
			$html .= '<a class="button" href="' . esc_url( $this->action_url( 'connect' ) ) . '">' . esc_html__( 'Reconnect', 'twoten-goto-webinar-gravityforms' ) . '</a></p>';

			return $html;
		}

		if ( $this->get_api( false ) ) {
			return '<p><a class="button button-primary" href="' . esc_url( $this->action_url( 'connect' ) ) . '">' . esc_html__( 'Connect to GoTo Webinar', 'twoten-goto-webinar-gravityforms' ) . '</a></p>'
				. '<p class="description">' . esc_html__( 'You will be sent to GoTo to sign in as the webinar organizer and approve access.', 'twoten-goto-webinar-gravityforms' ) . '</p>';
		}

		return '<p><span class="dashicons dashicons-warning" style="color:#dba617"></span> ' . esc_html__( 'Not connected. Save your Client ID and Client Secret first, then the Connect button will appear here.', 'twoten-goto-webinar-gravityforms' ) . '</p>';
	}

	public function render_update_status() {
		$html = '<p>' . sprintf(
			/* translators: %s: version number */
			esc_html__( 'Installed version: %s', 'twoten-goto-webinar-gravityforms' ),
			'<strong>' . esc_html( TTS_GTW_VERSION ) . '</strong>'
		);

		$release = TTS_GTW_Updater::release();
		if ( ! empty( $release['version'] ) ) {
			$html .= ' &middot; ' . sprintf(
				/* translators: %s: version number */
				esc_html__( 'Latest release on GitHub: %s', 'twoten-goto-webinar-gravityforms' ),
				'<a href="' . esc_url( $release['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $release['version'] ) . '</a>'
			);
			if ( version_compare( TTS_GTW_VERSION, $release['version'], '<' ) ) {
				$html .= ' <strong>' . esc_html__( '(update available)', 'twoten-goto-webinar-gravityforms' ) . '</strong>';
			}
		}
		$html .= '</p>';

		if ( current_user_can( 'update_plugins' ) ) {
			$html .= '<p><a class="button" href="' . esc_url( TTS_GTW_Updater::check_url( 'settings' ) ) . '">' . esc_html__( 'Check for updates', 'twoten-goto-webinar-gravityforms' ) . '</a></p>';
		}

		$html .= '<p class="description">' . sprintf(
			/* translators: %s: repository URL */
			esc_html__( 'Updates are published as releases on %s and install from the Plugins screen like any other update.', 'twoten-goto-webinar-gravityforms' ),
			'<a href="https://github.com/' . esc_attr( TTS_GTW_GITHUB_REPO ) . '/releases" target="_blank" rel="noopener">GitHub</a>'
		) . '</p>';

		return $html;
	}

	/**
	 * Nonce-protected URL for a settings-page action.
	 *
	 * @param string $action connect|disconnect|refresh.
	 * @param string $base   Base URL; defaults to the plugin settings page.
	 * @return string
	 */
	private function action_url( $action, $base = '' ) {
		$base = $base ? $base : $this->get_plugin_settings_url();
		return wp_nonce_url( add_query_arg( 'tts_gtw_action', $action, $base ), 'tts_gtw_' . $action );
	}

	/**
	 * Redirect URI registered with GoTo.
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		/**
		 * Filters the OAuth redirect URI. Must match the URI registered on the GoTo OAuth client.
		 *
		 * @param string $uri Default: the plugin's REST callback route.
		 */
		return (string) apply_filters( 'tts_gtw_redirect_uri', rest_url( 'twoten-gtw/v1/oauth' ) );
	}

	/* ── Connect / disconnect / refresh actions ────────────────────────── */

	public function maybe_handle_action() {
		$action = rgget( 'tts_gtw_action' );
		if ( ! $action ) {
			return;
		}
		if ( ! GFCommon::current_user_can_any( array( $this->_capabilities_settings_page, 'gravityforms_edit_settings' ) ) ) {
			return;
		}

		switch ( $action ) {
			case 'connect':
				check_admin_referer( 'tts_gtw_connect' );
				$api = $this->get_api( false );
				if ( ! $api ) {
					$this->redirect_to_settings( 'error', __( 'Save the Client ID and Client Secret before connecting.', 'twoten-goto-webinar-gravityforms' ) );
				}
				$state = wp_generate_password( 32, false, false );
				set_transient( 'tts_gtw_oauth_state_' . $state, get_current_user_id(), self::STATE_TTL );
				$this->log_debug( __METHOD__ . '(): Redirecting to GoTo authorization.' );
				wp_redirect( $api->authorize_url( $this->get_redirect_uri(), $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;

			case 'disconnect':
				check_admin_referer( 'tts_gtw_disconnect' );
				TTS_GTW_API::clear_auth();
				$this->clear_caches();
				$this->log_debug( __METHOD__ . '(): Disconnected from GoTo Webinar.' );
				$this->redirect_to_settings( 'disconnected' );
				break;

			case 'refresh':
				check_admin_referer( 'tts_gtw_refresh' );
				$this->clear_caches();
				wp_safe_redirect( remove_query_arg( array( 'tts_gtw_action', '_wpnonce' ) ) );
				exit;
		}
	}

	/**
	 * @param string $notice  connected|disconnected|error.
	 * @param string $message Optional detail.
	 */
	private function redirect_to_settings( $notice, $message = '' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'tts_gtw_notice'  => $notice,
					'tts_gtw_message' => rawurlencode( $message ),
				),
				$this->get_plugin_settings_url()
			)
		);
		exit;
	}

	public function admin_notices() {
		$notice = rgget( 'tts_gtw_notice' );
		if ( ! $notice || ! GFCommon::current_user_can_any( array( $this->_capabilities_settings_page, 'gravityforms_edit_settings' ) ) ) {
			return;
		}

		$message = sanitize_text_field( rawurldecode( (string) rgget( 'tts_gtw_message' ) ) );

		switch ( $notice ) {
			case 'connected':
				$class = 'notice-success';
				$text  = __( 'Connected to GoTo Webinar. You can now add GoTo Webinar feeds to your forms.', 'twoten-goto-webinar-gravityforms' );
				break;
			case 'disconnected':
				$class = 'notice-info';
				$text  = __( 'Disconnected from GoTo Webinar. Existing feeds will not run until you reconnect.', 'twoten-goto-webinar-gravityforms' );
				break;
			default:
				$class = 'notice-error';
				$text  = __( 'GoTo Webinar connection failed.', 'twoten-goto-webinar-gravityforms' );
				if ( $message ) {
					$text .= ' ' . $message;
				}
		}

		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $text ) );
	}

	/* ── OAuth callback (REST) ─────────────────────────────────────────── */

	public function register_rest_routes() {
		register_rest_route(
			'twoten-gtw/v1',
			'/oauth',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'oauth_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GoTo redirects here with ?code=&state=. The state token proves the flow
	 * was started by an administrator from the settings page.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function oauth_callback( WP_REST_Request $request ) {
		$state = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'state' ) );
		$key   = 'tts_gtw_oauth_state_' . $state;

		if ( '' === $state || false === get_transient( $key ) ) {
			$this->log_error( __METHOD__ . '(): Invalid or expired OAuth state.' );
			$this->redirect_to_settings( 'error', __( 'The authorization link was invalid or expired. Please click Connect again.', 'twoten-goto-webinar-gravityforms' ) );
		}
		delete_transient( $key );

		$error = (string) $request->get_param( 'error' );
		if ( '' !== $error ) {
			$detail = (string) $request->get_param( 'error_description' );
			$this->log_error( __METHOD__ . '(): GoTo returned error ' . $error . ' ' . $detail );
			$this->redirect_to_settings( 'error', trim( $error . ' ' . $detail ) );
		}

		$code = (string) $request->get_param( 'code' );
		if ( '' === $code ) {
			$this->redirect_to_settings( 'error', __( 'GoTo did not return an authorization code.', 'twoten-goto-webinar-gravityforms' ) );
		}

		$api = $this->get_api( false );
		if ( ! $api ) {
			$this->redirect_to_settings( 'error', __( 'Client ID and Client Secret are missing.', 'twoten-goto-webinar-gravityforms' ) );
		}

		$result = $api->exchange_code( $code, $this->get_redirect_uri() );
		if ( is_wp_error( $result ) ) {
			$this->log_error( __METHOD__ . '(): ' . $result->get_error_message() );
			$this->redirect_to_settings( 'error', $result->get_error_message() );
		}

		$this->clear_caches();
		$this->log_debug( __METHOD__ . '(): Connected as organizer ' . rgar( $result, 'organizer_key' ) );
		$this->redirect_to_settings( 'connected' );
	}

	/* ── Feed settings ─────────────────────────────────────────────────── */

	public function can_create_feed() {
		return TTS_GTW_API::is_connected();
	}

	public function configure_addon_message() {
		return sprintf(
			/* translators: %s: settings page URL */
			esc_html__( 'To get started, connect to GoTo Webinar on the %s page.', 'twoten-goto-webinar-gravityforms' ),
			'<a href="' . esc_url( $this->get_plugin_settings_url() ) . '">' . esc_html__( 'GoTo Webinar settings', 'twoten-goto-webinar-gravityforms' ) . '</a>'
		);
	}

	public function feed_settings_fields() {
		$refresh_url = $this->action_url( 'refresh', remove_query_arg( array( 'tts_gtw_action', '_wpnonce' ) ) );

		$sections = array(
			array(
				'title'  => esc_html__( 'GoTo Webinar Feed Settings', 'twoten-goto-webinar-gravityforms' ),
				'fields' => array(
					array(
						'name'     => 'feedName',
						'label'    => esc_html__( 'Name', 'twoten-goto-webinar-gravityforms' ),
						'type'     => 'text',
						'required' => true,
						'class'    => 'medium',
						'tooltip'  => '<h6>' . esc_html__( 'Name', 'twoten-goto-webinar-gravityforms' ) . '</h6>' . esc_html__( 'Enter a feed name to uniquely identify this setup.', 'twoten-goto-webinar-gravityforms' ),
					),
					array(
						'name'        => 'webinar',
						'label'       => esc_html__( 'Webinar', 'twoten-goto-webinar-gravityforms' ),
						'type'        => 'select',
						'required'    => true,
						'choices'     => $this->get_webinar_choices(),
						'onchange'    => "jQuery(this).parents('form').submit();",
						'description' => '<a href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Refresh webinar list', 'twoten-goto-webinar-gravityforms' ) . '</a>',
						'tooltip'     => '<h6>' . esc_html__( 'Webinar', 'twoten-goto-webinar-gravityforms' ) . '</h6>' . esc_html__( 'Upcoming webinars for the connected organizer. Choosing one loads its registration fields below.', 'twoten-goto-webinar-gravityforms' ),
					),
				),
			),
		);

		$webinar_key = (string) $this->get_setting( 'webinar' );
		if ( '' === $webinar_key ) {
			return $sections;
		}

		$fields    = $this->get_registration_fields( $webinar_key );
		$error     = is_wp_error( $fields ) ? $fields->get_error_message() : '';
		$questions = is_wp_error( $fields ) ? array() : $fields['questions'];

		$map_fields = array(
			array(
				'name'      => 'registrantFields',
				'label'     => esc_html__( 'Registrant Fields', 'twoten-goto-webinar-gravityforms' ),
				'type'      => 'field_map',
				'field_map' => $this->get_registrant_field_map( is_wp_error( $fields ) ? array() : $fields['fields'] ),
				'tooltip'   => '<h6>' . esc_html__( 'Registrant Fields', 'twoten-goto-webinar-gravityforms' ) . '</h6>' . esc_html__( 'Map the registration fields enabled on this webinar to your form fields. First name, last name and email are always required by GoTo.', 'twoten-goto-webinar-gravityforms' ),
			),
		);

		if ( $questions ) {
			$map_fields[] = array(
				'name'      => 'customQuestions',
				'label'     => esc_html__( 'Custom Questions', 'twoten-goto-webinar-gravityforms' ),
				'type'      => 'field_map',
				'field_map' => $this->get_question_field_map( $questions ),
				'tooltip'   => '<h6>' . esc_html__( 'Custom Questions', 'twoten-goto-webinar-gravityforms' ) . '</h6>' . esc_html__( 'Custom registration questions defined on this webinar. For multiple-choice questions the submitted value must match one of the answer options exactly.', 'twoten-goto-webinar-gravityforms' ),
			);
		}

		$sections[] = array(
			'title'       => esc_html__( 'Map Fields', 'twoten-goto-webinar-gravityforms' ),
			'description' => $error
				? '<div class="gform-alert gform-alert--error" role="alert"><span class="gform-alert__icon gform-icon gform-icon--circle-error-fine" aria-hidden="true"></span><div class="gform-alert__message-wrap"><p class="gform-alert__message">' . esc_html( sprintf( /* translators: %s: error message */ __( 'Could not load this webinar\'s registration fields (%s). The standard fields are shown instead.', 'twoten-goto-webinar-gravityforms' ), $error ) ) . '</p></div></div>'
				: '',
			'fields'      => $map_fields,
		);

		$sections[] = array(
			'title'  => esc_html__( 'Options', 'twoten-goto-webinar-gravityforms' ),
			'fields' => array(
				array(
					'name'    => 'options',
					'label'   => esc_html__( 'Confirmation Email', 'twoten-goto-webinar-gravityforms' ),
					'type'    => 'checkbox',
					'choices' => array(
						array(
							'name'  => 'resendConfirmation',
							'label' => esc_html__( 'Re-send the GoTo confirmation email if this person is already registered', 'twoten-goto-webinar-gravityforms' ),
						),
					),
				),
				array(
					'name'    => 'feedCondition',
					'label'   => esc_html__( 'Conditional Logic', 'twoten-goto-webinar-gravityforms' ),
					'type'    => 'feed_condition',
					'tooltip' => '<h6>' . esc_html__( 'Conditional Logic', 'twoten-goto-webinar-gravityforms' ) . '</h6>' . esc_html__( 'When enabled, the registration is only sent to GoTo Webinar when the condition is met.', 'twoten-goto-webinar-gravityforms' ),
				),
			),
		);

		return $sections;
	}

	/**
	 * Select choices for the webinar dropdown.
	 *
	 * @return array
	 */
	public function get_webinar_choices() {
		$choices = array(
			array(
				'label' => esc_html__( 'Select a webinar', 'twoten-goto-webinar-gravityforms' ),
				'value' => '',
			),
		);

		$webinars = $this->get_webinars();
		if ( is_wp_error( $webinars ) ) {
			$choices[0]['label'] = sprintf(
				/* translators: %s: error message */
				esc_html__( 'Unable to load webinars: %s', 'twoten-goto-webinar-gravityforms' ),
				$webinars->get_error_message()
			);
			$webinars = array();
		}

		$current = (string) $this->get_setting( 'webinar' );
		$found   = false;

		foreach ( $webinars as $webinar ) {
			$label = $webinar['subject'];
			if ( $webinar['start'] ) {
				$label .= ' — ' . $this->format_datetime( $webinar['start'] );
			}
			$choices[] = array(
				'label' => $label,
				'value' => $webinar['key'],
			);
			if ( $current === $webinar['key'] ) {
				$found = true;
			}
		}

		if ( '' !== $current && ! $found ) {
			$choices[] = array(
				'label' => sprintf(
					/* translators: %s: webinar key */
					esc_html__( 'Webinar %s (no longer listed)', 'twoten-goto-webinar-gravityforms' ),
					$current
				),
				'value' => $current,
			);
		}

		return $choices;
	}

	/**
	 * Field map choices for the standard registrant fields.
	 *
	 * @param array $fields `fields` from the registration fields endpoint.
	 * @return array
	 */
	private function get_registrant_field_map( array $fields ) {
		$labels   = $this->registrant_field_labels();
		$always   = array( 'firstName', 'lastName', 'email' );
		$enabled  = array();

		foreach ( $fields as $field ) {
			if ( empty( $field['field'] ) || ! isset( $labels[ $field['field'] ] ) ) {
				continue;
			}
			$enabled[ $field['field'] ] = ! empty( $field['required'] );
		}

		// GoTo always needs these three, whatever the fields endpoint says.
		foreach ( $always as $name ) {
			$enabled[ $name ] = true;
		}

		// If the endpoint returned nothing useful, offer every known field.
		if ( count( $enabled ) === count( $always ) && empty( $fields ) ) {
			foreach ( array_keys( $labels ) as $name ) {
				if ( ! isset( $enabled[ $name ] ) ) {
					$enabled[ $name ] = false;
				}
			}
		}

		$ordered = array_merge( $always, array_diff( array_keys( $labels ), $always ) );
		$map     = array();

		foreach ( $ordered as $name ) {
			if ( ! isset( $enabled[ $name ] ) ) {
				continue;
			}
			$choice = array(
				'name'     => $name,
				'label'    => $labels[ $name ],
				'required' => $enabled[ $name ],
			);
			if ( 'email' === $name ) {
				$choice['field_type'] = array( 'email', 'hidden', 'text' );
			}
			$map[] = $choice;
		}

		return $map;
	}

	/**
	 * Field map choices for a webinar's custom questions.
	 *
	 * @param array $questions `questions` from the registration fields endpoint.
	 * @return array
	 */
	private function get_question_field_map( array $questions ) {
		$map = array();

		foreach ( $questions as $question ) {
			if ( empty( $question['questionKey'] ) ) {
				continue;
			}
			$label = isset( $question['question'] ) ? (string) $question['question'] : (string) $question['questionKey'];
			if ( 'multipleChoice' === rgar( $question, 'type' ) && ! empty( $question['answers'] ) ) {
				$answers = array();
				foreach ( $question['answers'] as $answer ) {
					if ( isset( $answer['answer'] ) ) {
						$answers[] = (string) $answer['answer'];
					}
				}
				if ( $answers ) {
					$label .= ' [' . implode( ' | ', $answers ) . ']';
				}
			}
			$map[] = array(
				'name'     => 'q_' . $question['questionKey'],
				'label'    => $label,
				'required' => ! empty( $question['required'] ),
			);
		}

		return $map;
	}

	/**
	 * @return array field name => label
	 */
	private function registrant_field_labels() {
		return array(
			'firstName'            => esc_html__( 'First Name', 'twoten-goto-webinar-gravityforms' ),
			'lastName'             => esc_html__( 'Last Name', 'twoten-goto-webinar-gravityforms' ),
			'email'                => esc_html__( 'Email', 'twoten-goto-webinar-gravityforms' ),
			'address'              => esc_html__( 'Address', 'twoten-goto-webinar-gravityforms' ),
			'city'                 => esc_html__( 'City', 'twoten-goto-webinar-gravityforms' ),
			'state'                => esc_html__( 'State / Province', 'twoten-goto-webinar-gravityforms' ),
			'zipCode'              => esc_html__( 'ZIP / Postal Code', 'twoten-goto-webinar-gravityforms' ),
			'country'              => esc_html__( 'Country', 'twoten-goto-webinar-gravityforms' ),
			'phone'                => esc_html__( 'Phone', 'twoten-goto-webinar-gravityforms' ),
			'organization'         => esc_html__( 'Organization', 'twoten-goto-webinar-gravityforms' ),
			'jobTitle'             => esc_html__( 'Job Title', 'twoten-goto-webinar-gravityforms' ),
			'questionsAndComments' => esc_html__( 'Questions and Comments', 'twoten-goto-webinar-gravityforms' ),
			'industry'             => esc_html__( 'Industry', 'twoten-goto-webinar-gravityforms' ),
			'numberOfEmployees'    => esc_html__( 'Number of Employees', 'twoten-goto-webinar-gravityforms' ),
			'purchasingTimeFrame'  => esc_html__( 'Purchasing Time Frame', 'twoten-goto-webinar-gravityforms' ),
			'purchasingRole'       => esc_html__( 'Purchasing Role', 'twoten-goto-webinar-gravityforms' ),
		);
	}

	/* ── Feed list ─────────────────────────────────────────────────────── */

	public function feed_list_columns() {
		return array(
			'feedName' => esc_html__( 'Name', 'twoten-goto-webinar-gravityforms' ),
			'webinar'  => esc_html__( 'Webinar', 'twoten-goto-webinar-gravityforms' ),
		);
	}

	/**
	 * @param array $feed Feed.
	 * @return string
	 */
	public function get_column_value_webinar( $feed ) {
		$key = (string) rgars( $feed, 'meta/webinar' );
		if ( '' === $key ) {
			return '—';
		}

		$webinars = $this->get_webinars();
		if ( ! is_wp_error( $webinars ) ) {
			foreach ( $webinars as $webinar ) {
				if ( $webinar['key'] === $key ) {
					$label = esc_html( $webinar['subject'] );
					if ( $webinar['start'] ) {
						$label .= ' <span class="description">(' . esc_html( $this->format_datetime( $webinar['start'] ) ) . ')</span>';
					}
					return $label;
				}
			}
		}

		return esc_html( $key );
	}

	/* ── Feed processing ───────────────────────────────────────────────── */

	/**
	 * Register the entry with GoTo Webinar.
	 *
	 * @param array $feed  Feed.
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return array Entry.
	 */
	public function process_feed( $feed, $entry, $form ) {
		$this->log_debug( __METHOD__ . '(): Processing feed #' . rgar( $feed, 'id' ) . ' for entry #' . rgar( $entry, 'id' ) );

		$api = $this->get_api();
		if ( ! $api ) {
			$this->add_feed_error( esc_html__( 'GoTo Webinar is not connected.', 'twoten-goto-webinar-gravityforms' ), $feed, $entry, $form );
			return $entry;
		}

		$webinar_key = (string) rgars( $feed, 'meta/webinar' );
		if ( '' === $webinar_key ) {
			$this->add_feed_error( esc_html__( 'No webinar is selected on this feed.', 'twoten-goto-webinar-gravityforms' ), $feed, $entry, $form );
			return $entry;
		}

		$fields_info = $this->get_registration_fields( $webinar_key );
		$max_sizes   = array();
		$questions   = array();
		if ( ! is_wp_error( $fields_info ) ) {
			foreach ( $fields_info['fields'] as $field ) {
				if ( ! empty( $field['field'] ) && ! empty( $field['maxSize'] ) ) {
					$max_sizes[ $field['field'] ] = (int) $field['maxSize'];
				}
			}
			foreach ( $fields_info['questions'] as $question ) {
				if ( ! empty( $question['questionKey'] ) ) {
					$questions[ (string) $question['questionKey'] ] = $question;
				}
			}
		}

		// Standard fields.
		$registrant = array();
		foreach ( $this->get_field_map_fields( $feed, 'registrantFields' ) as $name => $field_id ) {
			if ( rgblank( $field_id ) ) {
				continue;
			}
			$value = $this->get_field_value( $form, $entry, $field_id );
			if ( rgblank( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( ! empty( $max_sizes[ $name ] ) ) {
				$value = mb_substr( $value, 0, $max_sizes[ $name ] );
			}
			$registrant[ $name ] = $value;
		}

		foreach ( array( 'firstName', 'lastName', 'email' ) as $required ) {
			if ( empty( $registrant[ $required ] ) ) {
				$this->add_feed_error(
					sprintf(
						/* translators: %s: field name */
						esc_html__( 'Registration not sent: the required field "%s" is empty or not mapped.', 'twoten-goto-webinar-gravityforms' ),
						$required
					),
					$feed,
					$entry,
					$form
				);
				return $entry;
			}
		}

		if ( ! is_email( $registrant['email'] ) ) {
			$this->add_feed_error( esc_html__( 'Registration not sent: the email address is not valid.', 'twoten-goto-webinar-gravityforms' ), $feed, $entry, $form );
			return $entry;
		}

		// Custom questions.
		$responses = array();
		foreach ( $this->get_field_map_fields( $feed, 'customQuestions' ) as $name => $field_id ) {
			if ( rgblank( $field_id ) || 0 !== strpos( $name, 'q_' ) ) {
				continue;
			}
			$question_key = substr( $name, 2 );
			$value        = $this->get_field_value( $form, $entry, $field_id );
			if ( rgblank( $value ) ) {
				continue;
			}
			$value    = trim( (string) $value );
			$question = rgar( $questions, $question_key );

			if ( $question && 'multipleChoice' === rgar( $question, 'type' ) ) {
				$answer_key = $this->match_answer_key( $question, $value );
				if ( null === $answer_key ) {
					$this->log_debug( __METHOD__ . "(): No answer option matches '{$value}' for question {$question_key}; skipping." );
					continue;
				}
				$responses[] = array(
					'questionKey' => $this->numeric_key( $question_key ),
					'answerKey'   => $this->numeric_key( $answer_key ),
				);
			} else {
				if ( $question && ! empty( $question['maxSize'] ) ) {
					$value = mb_substr( $value, 0, (int) $question['maxSize'] );
				}
				$responses[] = array(
					'questionKey'  => $this->numeric_key( $question_key ),
					'responseText' => $value,
				);
			}
		}
		if ( $responses ) {
			$registrant['responses'] = $responses;
		}

		/**
		 * Filters the registrant payload before it is sent to GoTo Webinar.
		 *
		 * @param array $registrant Payload.
		 * @param array $feed       Feed.
		 * @param array $entry      Entry.
		 * @param array $form       Form.
		 */
		$registrant = apply_filters( 'tts_gtw_registrant_data', $registrant, $feed, $entry, $form );

		$resend = ! empty( $feed['meta']['resendConfirmation'] );

		$this->log_debug( __METHOD__ . '(): Registering ' . $registrant['email'] . ' for webinar ' . $webinar_key . '. Payload: ' . wp_json_encode( $registrant ) );

		$result = $api->create_registrant( $webinar_key, $registrant, $resend );

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? (int) rgar( $data, 'status' ) : 0;

			if ( 409 === $status ) {
				$this->log_debug( __METHOD__ . '(): Already registered. ' . $result->get_error_message() );
				gform_update_meta( $entry['id'], 'tts_gtw_webinar_key', $webinar_key );
				gform_update_meta( $entry['id'], 'tts_gtw_status', 'ALREADY_REGISTERED' );
				$this->add_note(
					$entry['id'],
					sprintf(
						/* translators: 1: email address, 2: webinar key */
						esc_html__( 'GoTo Webinar: %1$s is already registered for webinar %2$s.', 'twoten-goto-webinar-gravityforms' ),
						$registrant['email'],
						$webinar_key
					),
					'success'
				);
				return $entry;
			}

			$this->add_feed_error(
				sprintf(
					/* translators: %s: error message */
					esc_html__( 'GoTo Webinar registration failed: %s', 'twoten-goto-webinar-gravityforms' ),
					$result->get_error_message()
				),
				$feed,
				$entry,
				$form
			);
			return $entry;
		}

		$registrant_key = (string) rgar( $result, 'registrantKey' );
		$join_url       = (string) rgar( $result, 'joinUrl' );
		$status         = (string) rgar( $result, 'status', 'APPROVED' );

		gform_update_meta( $entry['id'], 'tts_gtw_webinar_key', $webinar_key );
		gform_update_meta( $entry['id'], 'tts_gtw_registrant_key', $registrant_key );
		gform_update_meta( $entry['id'], 'tts_gtw_join_url', $join_url );
		gform_update_meta( $entry['id'], 'tts_gtw_status', $status );

		$this->log_debug( __METHOD__ . '(): Registered. Key ' . $registrant_key . ', status ' . $status );

		$note = sprintf(
			/* translators: 1: email address, 2: webinar key, 3: status */
			esc_html__( 'GoTo Webinar: registered %1$s for webinar %2$s (status: %3$s).', 'twoten-goto-webinar-gravityforms' ),
			$registrant['email'],
			$webinar_key,
			$status
		);
		if ( $join_url ) {
			$note .= ' ' . esc_html__( 'Join URL:', 'twoten-goto-webinar-gravityforms' ) . ' ' . $join_url;
		}
		$this->add_note( $entry['id'], $note, 'success' );

		/**
		 * Fires after a registrant has been created in GoTo Webinar.
		 *
		 * @param array $result     API response (registrantKey, joinUrl, status, asset).
		 * @param array $registrant Payload that was sent.
		 * @param array $feed       Feed.
		 * @param array $entry      Entry.
		 * @param array $form       Form.
		 */
		do_action( 'tts_gtw_after_registration', $result, $registrant, $feed, $entry, $form );

		return $entry;
	}

	/**
	 * Find the answerKey whose text matches the submitted value.
	 *
	 * @param array  $question Question definition.
	 * @param string $value    Submitted value.
	 * @return string|null
	 */
	private function match_answer_key( array $question, $value ) {
		$needle = mb_strtolower( trim( (string) $value ) );
		foreach ( (array) rgar( $question, 'answers' ) as $answer ) {
			if ( ! isset( $answer['answerKey'] ) ) {
				continue;
			}
			if ( (string) $answer['answerKey'] === (string) $value ) {
				return (string) $answer['answerKey'];
			}
			if ( isset( $answer['answer'] ) && mb_strtolower( trim( (string) $answer['answer'] ) ) === $needle ) {
				return (string) $answer['answerKey'];
			}
		}
		return null;
	}

	/**
	 * GoTo keys are 64-bit integers; send them as numbers when PHP can hold them.
	 *
	 * @param string $key Key.
	 * @return int|string
	 */
	private function numeric_key( $key ) {
		$key = (string) $key;
		if ( ctype_digit( $key ) && strlen( $key ) < 19 ) {
			return (int) $key;
		}
		return $key;
	}

	/**
	 * Format an ISO-8601 UTC time in the site's timezone.
	 *
	 * @param string $iso ISO-8601 time.
	 * @return string
	 */
	private function format_datetime( $iso ) {
		$timestamp = strtotime( $iso );
		if ( ! $timestamp ) {
			return $iso;
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/* ── Entry meta & merge tags ───────────────────────────────────────── */

	public function get_entry_meta( $entry_meta, $form_id ) {
		if ( ! $this->has_feed( $form_id ) ) {
			return $entry_meta;
		}

		$labels = array(
			'tts_gtw_join_url'       => esc_html__( 'GoTo Webinar Join URL', 'twoten-goto-webinar-gravityforms' ),
			'tts_gtw_registrant_key' => esc_html__( 'GoTo Webinar Registrant Key', 'twoten-goto-webinar-gravityforms' ),
			'tts_gtw_status'         => esc_html__( 'GoTo Webinar Status', 'twoten-goto-webinar-gravityforms' ),
			'tts_gtw_webinar_key'    => esc_html__( 'GoTo Webinar Key', 'twoten-goto-webinar-gravityforms' ),
		);

		foreach ( $labels as $key => $label ) {
			$entry_meta[ $key ] = array(
				'label'                      => $label,
				'is_numeric'                 => false,
				'is_default_column'          => false,
				'update_entry_meta_callback' => array( $this, 'update_entry_meta' ),
				'filter'                     => array( 'operators' => array( 'is', 'isnot', 'contains' ) ),
			);
		}

		return $entry_meta;
	}

	/**
	 * Keep whatever value is already stored; process_feed() sets the real values.
	 *
	 * @param string $key   Meta key.
	 * @param array  $entry Entry.
	 * @param array  $form  Form.
	 * @return string
	 */
	public function update_entry_meta( $key, $entry, $form ) {
		return (string) rgar( $entry, $key );
	}

	public function custom_merge_tags( $merge_tags, $form_id, $fields, $element_id ) {
		if ( ! $form_id || ! $this->has_feed( $form_id ) ) {
			return $merge_tags;
		}

		$merge_tags[] = array( 'label' => esc_html__( 'GoTo Webinar: Join URL', 'twoten-goto-webinar-gravityforms' ), 'tag' => '{goto_webinar:join_url}' );
		$merge_tags[] = array( 'label' => esc_html__( 'GoTo Webinar: Registrant Key', 'twoten-goto-webinar-gravityforms' ), 'tag' => '{goto_webinar:registrant_key}' );
		$merge_tags[] = array( 'label' => esc_html__( 'GoTo Webinar: Status', 'twoten-goto-webinar-gravityforms' ), 'tag' => '{goto_webinar:status}' );

		return $merge_tags;
	}

	public function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
		if ( ! is_string( $text ) || false === strpos( $text, '{goto_webinar:' ) || empty( $entry['id'] ) ) {
			return $text;
		}

		$map = array(
			'join_url'       => 'tts_gtw_join_url',
			'registrant_key' => 'tts_gtw_registrant_key',
			'status'         => 'tts_gtw_status',
			'webinar_key'    => 'tts_gtw_webinar_key',
		);

		foreach ( $map as $tag => $meta_key ) {
			$tag = '{goto_webinar:' . $tag . '}';
			if ( false === strpos( $text, $tag ) ) {
				continue;
			}
			$value = (string) gform_get_meta( $entry['id'], $meta_key );
			if ( $url_encode ) {
				$value = urlencode( $value );
			} elseif ( $esc_html ) {
				$value = esc_html( $value );
			}
			$text = str_replace( $tag, $value, $text );
		}

		return $text;
	}
}
