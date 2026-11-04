<?php
/**
 * Client for Toolbox POD's plugin API (`/api/woocommerce/plugin/v1`), authenticated with the store token
 * Toolbox POD issued at connect.
 *
 * Errors come back as WP_Error with one of these codes: not_connected, reconnect (401: the token was revoked;
 * the "Reconnect" notice shows), rate_limited (429), http_error, bad_response. Every answer carries
 * min_plugin_version; an older plugin shows "Update the plugin" and keeps working.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin API client.
 */
final class Toolbox_POD_API {

	const BASE_PATH = '/api/woocommerce/plugin/v1/';

	/** Seconds an admin call waits for Toolbox POD. */
	const TIMEOUT = 10;

	/** Seconds the shipping call waits during a buyer's cart or checkout request. */
	const CHECKOUT_TIMEOUT = 3;

	/** Option (autoload off): when our API last refused the token. */
	const NEEDS_RECONNECT_OPTION = 'toolbox_pod_needs_reconnect';

	/** Transient: the minimum plugin version the server asked for, when this one is older. */
	const MIN_VERSION_TRANSIENT = 'toolbox_pod_min_version';

	/** Transient: the overview answer (5 min). */
	const OVERVIEW_TRANSIENT = 'toolbox_pod_dashboard';

	/** Transient: the shipping answer (1 h). */
	const SHIPPING_TRANSIENT = 'toolbox_pod_shipping';

	/**
	 * GET a path.
	 *
	 * @param string $path Path under the API base, e.g. "overview".
	 * @return array|WP_Error
	 */
	public static function get( $path ) {
		return self::request( 'GET', $path );
	}

	/**
	 * POST to a path.
	 *
	 * @param string     $path Path under the API base.
	 * @param array|null $body JSON body.
	 * @return array|WP_Error
	 */
	public static function post( $path, $body = null ) {
		return self::request( 'POST', $path, $body );
	}

	/**
	 * PUT to a path.
	 *
	 * @param string $path Path under the API base.
	 * @param array  $body JSON body.
	 * @return array|WP_Error
	 */
	public static function put( $path, $body ) {
		return self::request( 'PUT', $path, $body );
	}

	/**
	 * The overview (account, store, stats, webhooks, links), cached for 5 minutes.
	 *
	 * @param bool $cached_only Never call the API; null when nothing is cached.
	 * @return array|WP_Error|null
	 */
	public static function overview( $cached_only = false ) {
		$cached = get_transient( self::OVERVIEW_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( $cached_only ) {
			return null;
		}
		$overview = self::get( 'overview' );
		if ( ! is_wp_error( $overview ) ) {
			set_transient( self::OVERVIEW_TRANSIENT, $overview, 5 * MINUTE_IN_SECONDS );
		}
		return $overview;
	}

	/**
	 * Toolbox POD shipping (price, countries, label), cached for an hour.
	 *
	 * @param int $timeout Seconds to wait for Toolbox POD; a buyer's cart or checkout passes CHECKOUT_TIMEOUT.
	 * @return array|WP_Error
	 */
	public static function shipping( $timeout = self::TIMEOUT ) {
		$cached = get_transient( self::SHIPPING_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$answer = self::request( 'GET', 'shipping', null, $timeout );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		if ( ! isset( $answer['shipping'] ) || ! is_array( $answer['shipping'] ) ) {
			return new WP_Error( 'bad_response', __( 'Toolbox POD sent an answer the plugin could not read.', 'toolbox-pod-for-woocommerce' ) );
		}
		set_transient( self::SHIPPING_TRANSIENT, $answer['shipping'], HOUR_IN_SECONDS );
		return $answer['shipping'];
	}

	/**
	 * One call to the plugin API.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path under the API base.
	 * @param array|null $body    JSON body.
	 * @param int        $timeout Seconds to wait for the answer.
	 * @return array|WP_Error Decoded answer.
	 */
	public static function request( $method, $path, $body = null, $timeout = self::TIMEOUT ) {
		$connection = Toolbox_POD_Plugin::connection();
		if ( ! $connection || empty( $connection['token'] ) ) {
			return new WP_Error( 'not_connected', __( 'This store is not connected to Toolbox POD.', 'toolbox-pod-for-woocommerce' ) );
		}

		$path    = ltrim( (string) $path, '/' );
		$headers = array(
			'Authorization'        => 'Bearer ' . $connection['token'],
			'Accept'               => 'application/json',
			'X-Toolbox-POD-Plugin' => TOOLBOX_POD_VERSION,
		);
		$args    = array(
			'method'     => $method,
			'timeout'    => max( 1, (int) $timeout ),
			'user-agent' => 'Toolbox POD for WooCommerce/' . TOOLBOX_POD_VERSION . '; ' . home_url( '/' ),
			'headers'    => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( empty( $body ) ? new stdClass() : $body );
		}

		$response = wp_safe_remote_request( Toolbox_POD_Plugin::app_url() . self::BASE_PATH . $path, $args );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		Toolbox_POD_Log::add( 'outgoing', $method, self::BASE_PATH . $path, $status );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'http_error', __( 'Your store could not reach Toolbox POD. Try again in a minute.', 'toolbox-pod-for-woocommerce' ), array( 'status' => 0 ) );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $data ) && isset( $data['min_plugin_version'] ) && is_string( $data['min_plugin_version'] ) ) {
			self::note_min_version( $data['min_plugin_version'] );
		}
		$reason = is_array( $data ) && isset( $data['reason'] ) && is_string( $data['reason'] ) ? $data['reason'] : '';
		$extra  = array(
			'status' => $status,
			'reason' => $reason,
		);

		if ( 401 === $status ) {
			update_option( self::NEEDS_RECONNECT_OPTION, time(), false );
			delete_transient( self::OVERVIEW_TRANSIENT );
			return new WP_Error( 'reconnect', __( 'Toolbox POD no longer accepts this store\'s connection. Reconnect to fix it.', 'toolbox-pod-for-woocommerce' ), $extra );
		}
		if ( 429 === $status ) {
			$extra['retry_after'] = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return new WP_Error( 'rate_limited', __( 'Too many requests to Toolbox POD. Wait a minute and try again.', 'toolbox-pod-for-woocommerce' ), $extra );
		}
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'http_error', self::error_message( $status, $reason ), $extra );
		}

		delete_option( self::NEEDS_RECONNECT_OPTION );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bad_response', __( 'Toolbox POD sent an answer the plugin could not read.', 'toolbox-pod-for-woocommerce' ), $extra );
		}
		return $data;
	}

	/**
	 * Whether the server last refused the token since this connection was made.
	 *
	 * @return bool
	 */
	public static function needs_reconnect() {
		$connection = Toolbox_POD_Plugin::connection();
		$refused_at = (int) get_option( self::NEEDS_RECONNECT_OPTION, 0 );
		if ( ! $connection || $refused_at <= 0 ) {
			return false;
		}
		// A reconnect writes a new connection; a refusal from before it is stale.
		$connected_at = isset( $connection['connected_at'] ) ? (int) strtotime( (string) $connection['connected_at'] ) : 0;
		return $refused_at >= $connected_at;
	}

	/**
	 * Remembers the server's minimum plugin version when this plugin is older, and forgets it otherwise.
	 *
	 * @param string $min Minimum version.
	 */
	private static function note_min_version( $min ) {
		if ( '' !== $min && version_compare( TOOLBOX_POD_VERSION, $min, '<' ) ) {
			set_transient( self::MIN_VERSION_TRANSIENT, $min, DAY_IN_SECONDS );
		} elseif ( false !== get_transient( self::MIN_VERSION_TRANSIENT ) ) {
			delete_transient( self::MIN_VERSION_TRANSIENT );
		}
	}

	/**
	 * A short message for an error answer.
	 *
	 * @param int    $status HTTP status.
	 * @param string $reason The server's reason code.
	 * @return string
	 */
	private static function error_message( $status, $reason ) {
		if ( 'store_unreachable' === $reason ) {
			return __( 'Toolbox POD could not reach your store. Check the Status tab.', 'toolbox-pod-for-woocommerce' );
		}
		if ( 'not_active' === $reason ) {
			return __( 'This store\'s connection is not active in Toolbox POD.', 'toolbox-pod-for-woocommerce' );
		}
		if ( $status >= 500 ) {
			return __( 'Toolbox POD had a problem. Try again in a few minutes.', 'toolbox-pod-for-woocommerce' );
		}
		/* translators: 1: HTTP status code, 2: reason code from Toolbox POD. */
		return sprintf( __( 'Toolbox POD refused the request (%1$d %2$s).', 'toolbox-pod-for-woocommerce' ), $status, $reason );
	}
}
