<?php
/**
 * GoTo Webinar API client (OAuth 2.0 + G2W REST v2).
 *
 * @package TwoTen\GoToWebinar
 */

defined( 'ABSPATH' ) || exit;

class TTS_GTW_API {

	const AUTH_BASE = 'https://authentication.logmeininc.com';
	const API_BASE  = 'https://api.getgo.com';
	const OPTION    = 'tts_gtw_auth';

	/** @var string */
	private $client_id;

	/** @var string */
	private $client_secret;

	/**
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 */
	public function __construct( $client_id, $client_secret ) {
		$this->client_id     = trim( (string) $client_id );
		$this->client_secret = trim( (string) $client_secret );
	}

	/* ── Stored connection ─────────────────────────────────────────────── */

	/**
	 * @return array
	 */
	public static function get_auth() {
		$auth = get_option( self::OPTION );
		return is_array( $auth ) ? $auth : array();
	}

	/**
	 * @param array $auth Connection data.
	 */
	public static function save_auth( array $auth ) {
		update_option( self::OPTION, $auth, false );
	}

	public static function clear_auth() {
		delete_option( self::OPTION );
	}

	/**
	 * @return bool
	 */
	public static function is_connected() {
		$auth = self::get_auth();
		return ! empty( $auth['access_token'] ) && ! empty( $auth['refresh_token'] ) && ! empty( $auth['organizer_key'] );
	}

	/**
	 * @return string
	 */
	public static function organizer_key() {
		$auth = self::get_auth();
		return isset( $auth['organizer_key'] ) ? (string) $auth['organizer_key'] : '';
	}

	/* ── OAuth ─────────────────────────────────────────────────────────── */

	/**
	 * Build the authorization URL the admin is sent to.
	 *
	 * @param string $redirect_uri Registered redirect URI.
	 * @param string $state        Anti-CSRF state token.
	 * @return string
	 */
	public function authorize_url( $redirect_uri, $state ) {
		$args = array(
			'response_type' => 'code',
			'client_id'     => $this->client_id,
			'redirect_uri'  => $redirect_uri,
			'state'         => $state,
		);

		/**
		 * Filters the OAuth scope requested. Empty by default: GoTo grants the
		 * scopes configured on the OAuth client.
		 *
		 * @param string $scope Space-separated scopes.
		 */
		$scope = (string) apply_filters( 'tts_gtw_oauth_scope', '' );
		if ( '' !== $scope ) {
			$args['scope'] = $scope;
		}

		return self::AUTH_BASE . '/oauth/authorize?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange an authorization code for tokens and store the connection.
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri Redirect URI used in the authorize request.
	 * @return array|WP_Error Stored connection data.
	 */
	public function exchange_code( $code, $redirect_uri ) {
		$token = $this->token_request(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => $redirect_uri,
			)
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$auth = $this->auth_from_token( $token, array() );

		if ( empty( $auth['organizer_key'] ) ) {
			return new WP_Error(
				'tts_gtw_no_organizer',
				__( 'GoTo did not return an organizer key. Make sure the OAuth client has access to the GoTo Webinar product and the account you signed in with is a webinar organizer.', 'twoten-goto-webinar-gravityforms' )
			);
		}

		self::save_auth( $auth );

		return $auth;
	}

	/**
	 * Refresh the access token using the stored refresh token.
	 *
	 * @return array|WP_Error Updated connection data.
	 */
	public function refresh_token() {
		$auth = self::get_auth();
		if ( empty( $auth['refresh_token'] ) ) {
			return new WP_Error( 'tts_gtw_not_connected', __( 'GoTo Webinar is not connected.', 'twoten-goto-webinar-gravityforms' ) );
		}

		$token = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $auth['refresh_token'],
			)
		);
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$auth = $this->auth_from_token( $token, $auth );
		self::save_auth( $auth );

		return $auth;
	}

	/**
	 * Merge a token response into connection data.
	 *
	 * @param array $token    Token endpoint response.
	 * @param array $existing Existing connection data.
	 * @return array
	 */
	private function auth_from_token( array $token, array $existing ) {
		$auth                 = $existing;
		$auth['access_token'] = (string) $token['access_token'];
		if ( ! empty( $token['refresh_token'] ) ) {
			$auth['refresh_token'] = (string) $token['refresh_token'];
		}
		$auth['expires_at'] = time() + ( isset( $token['expires_in'] ) ? (int) $token['expires_in'] : 3600 );

		foreach ( array( 'organizer_key', 'account_key', 'account_type', 'email', 'firstName', 'lastName', 'principal' ) as $key ) {
			if ( ! empty( $token[ $key ] ) && is_scalar( $token[ $key ] ) ) {
				$auth[ $key ] = (string) $token[ $key ];
			}
		}

		if ( empty( $auth['connected_at'] ) ) {
			$auth['connected_at'] = time();
		}

		return $auth;
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array $body Form-encoded body.
	 * @return array|WP_Error Decoded token response.
	 */
	private function token_request( array $body ) {
		if ( '' === $this->client_id || '' === $this->client_secret ) {
			return new WP_Error( 'tts_gtw_no_credentials', __( 'Enter the GoTo OAuth Client ID and Client Secret first.', 'twoten-goto-webinar-gravityforms' ) );
		}

		$response = wp_remote_post(
			self::AUTH_BASE . '/oauth/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = self::decode( wp_remote_retrieve_body( $response ) );

		if ( 200 !== $code || empty( $data['access_token'] ) ) {
			return new WP_Error(
				'tts_gtw_oauth_' . $code,
				sprintf(
					/* translators: %s: HTTP status and error detail */
					__( 'GoTo token request failed (%s).', 'twoten-goto-webinar-gravityforms' ),
					self::error_message( $code, $data )
				),
				array( 'status' => $code, 'body' => $data )
			);
		}

		return $data;
	}

	/**
	 * A valid access token, refreshing when it is about to expire.
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		$auth = self::get_auth();
		if ( empty( $auth['access_token'] ) ) {
			return new WP_Error( 'tts_gtw_not_connected', __( 'GoTo Webinar is not connected.', 'twoten-goto-webinar-gravityforms' ) );
		}

		if ( empty( $auth['expires_at'] ) || (int) $auth['expires_at'] - 120 < time() ) {
			$auth = $this->refresh_token();
			if ( is_wp_error( $auth ) ) {
				return $auth;
			}
		}

		return (string) $auth['access_token'];
	}

	/* ── REST ──────────────────────────────────────────────────────────── */

	/**
	 * Authenticated request against api.getgo.com.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with a slash.
	 * @param array|null $body   JSON body, or null.
	 * @param array      $query  Query arguments.
	 * @param bool       $retry  Refresh the token and retry once on 401.
	 * @return array|WP_Error Decoded response (empty array for empty bodies).
	 */
	public function request( $method, $path, $body = null, array $query = array(), $retry = true ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::API_BASE . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = self::decode( wp_remote_retrieve_body( $response ) );

		if ( 401 === $code && $retry ) {
			$refreshed = $this->refresh_token();
			if ( ! is_wp_error( $refreshed ) ) {
				return $this->request( $method, $path, $body, $query, false );
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'tts_gtw_http_' . $code,
				self::error_message( $code, $data ),
				array( 'status' => $code, 'body' => $data )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Upcoming webinars for the connected organizer.
	 *
	 * @return array|WP_Error List of { key, subject, start, organizerKey } sorted by start time.
	 */
	public function get_webinars() {
		$organizer = self::organizer_key();
		if ( '' === $organizer ) {
			return new WP_Error( 'tts_gtw_not_connected', __( 'GoTo Webinar is not connected.', 'twoten-goto-webinar-gravityforms' ) );
		}

		/**
		 * Filters how far ahead (in seconds) webinars are listed.
		 *
		 * @param int $seconds Default one year.
		 */
		$lookahead = (int) apply_filters( 'tts_gtw_webinar_lookahead', YEAR_IN_SECONDS );

		/**
		 * Filters how far back (in seconds) webinars are listed. Defaults to
		 * one day so a webinar that is already in progress can still take
		 * registrations.
		 *
		 * @param int $seconds Default one day.
		 */
		$lookback = (int) apply_filters( 'tts_gtw_webinar_lookback', DAY_IN_SECONDS );

		$from = gmdate( 'Y-m-d\TH:i:s\Z', time() - $lookback );
		$to   = gmdate( 'Y-m-d\TH:i:s\Z', time() + $lookahead );

		$webinars = array();
		$page     = 0;

		do {
			$data = $this->request(
				'GET',
				'/G2W/rest/v2/organizers/' . rawurlencode( $organizer ) . '/webinars',
				null,
				array(
					'fromTime' => $from,
					'toTime'   => $to,
					'page'     => $page,
					'size'     => 100,
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$items = isset( $data['_embedded']['webinars'] ) && is_array( $data['_embedded']['webinars'] ) ? $data['_embedded']['webinars'] : array();
			foreach ( $items as $webinar ) {
				if ( empty( $webinar['webinarKey'] ) ) {
					continue;
				}
				$webinars[] = array(
					'key'          => (string) $webinar['webinarKey'],
					'subject'      => isset( $webinar['subject'] ) ? (string) $webinar['subject'] : '',
					'start'        => isset( $webinar['times'][0]['startTime'] ) ? (string) $webinar['times'][0]['startTime'] : '',
					'organizerKey' => isset( $webinar['organizerKey'] ) ? (string) $webinar['organizerKey'] : $organizer,
				);
			}

			$total_pages = isset( $data['page']['totalPages'] ) ? (int) $data['page']['totalPages'] : 1;
			$page++;
		} while ( $page < $total_pages && $page < 20 );

		usort(
			$webinars,
			static function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		return $webinars;
	}

	/**
	 * Registration fields and custom questions enabled for a webinar.
	 *
	 * @param string $webinar_key Webinar key.
	 * @return array|WP_Error { fields: [ { field, maxSize, required } ], questions: [ { questionKey, question, type, required, answers } ] }
	 */
	public function get_registration_fields( $webinar_key ) {
		$organizer = self::organizer_key();
		$data      = $this->request(
			'GET',
			'/G2W/rest/v2/organizers/' . rawurlencode( $organizer ) . '/webinars/' . rawurlencode( (string) $webinar_key ) . '/registrants/fields'
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'fields'    => isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array(),
			'questions' => isset( $data['questions'] ) && is_array( $data['questions'] ) ? $data['questions'] : array(),
		);
	}

	/**
	 * Register someone for a webinar.
	 *
	 * @param string $webinar_key         Webinar key.
	 * @param array  $registrant          Registrant data (firstName, lastName, email, ... , responses).
	 * @param bool   $resend_confirmation Re-send the confirmation email if already registered.
	 * @return array|WP_Error { registrantKey, joinUrl, status, asset }
	 */
	public function create_registrant( $webinar_key, array $registrant, $resend_confirmation = false ) {
		$organizer = self::organizer_key();

		return $this->request(
			'POST',
			'/G2W/rest/v2/organizers/' . rawurlencode( $organizer ) . '/webinars/' . rawurlencode( (string) $webinar_key ) . '/registrants',
			$registrant,
			array( 'resendConfirmation' => $resend_confirmation ? 'true' : 'false' )
		);
	}

	/* ── Helpers ───────────────────────────────────────────────────────── */

	/**
	 * Decode JSON keeping GoTo's 64-bit keys as strings.
	 *
	 * @param string $body Raw body.
	 * @return array|null
	 */
	public static function decode( $body ) {
		if ( '' === trim( (string) $body ) ) {
			return null;
		}
		$data = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Human-readable summary of an error response.
	 *
	 * @param int        $code HTTP status.
	 * @param array|null $data Decoded body.
	 * @return string
	 */
	public static function error_message( $code, $data ) {
		$parts = array();
		if ( is_array( $data ) ) {
			foreach ( array( 'errorCode', 'error', 'description', 'error_description', 'message' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					$parts[] = $data[ $key ];
				}
			}
			if ( ! empty( $data['field'] ) && is_string( $data['field'] ) ) {
				$parts[] = 'field: ' . $data['field'];
			}
		}
		$parts = array_unique( $parts );

		return sprintf( 'HTTP %d%s', (int) $code, $parts ? ': ' . implode( ' - ', $parts ) : '' );
	}
}
