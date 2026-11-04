<?php
/**
 * Request log: the last calls Toolbox POD made to this store and the last calls this store made to Toolbox POD.
 *
 * Entries keep only the time, method, path (no query string) and HTTP status: never bodies, tokens or keys.
 * Shown on the Status tab and in the support report. Failures also go to the WooCommerce log (source
 * `toolbox-pod`, WooCommerce → Status → Logs).
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request log.
 */
final class Toolbox_POD_Log {

	/** Option (autoload off): {incoming: [], outgoing: []}. */
	const OPTION = 'toolbox_pod_log';

	/** Entries kept per direction. */
	const KEEP = 20;

	/** Longest path kept, in bytes. */
	const MAX_PATH = 200;

	/**
	 * Logs requests to our REST routes.
	 */
	public static function init() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'log_incoming' ), 10, 3 );
	}

	/**
	 * Records one request to a `wc-toolbox-pod/v1` route made by a store manager (Toolbox POD's servers call with
	 * the approved key, whose user is one). Anonymous probes are not recorded, so they cannot flood the log.
	 *
	 * @param WP_HTTP_Response $result  Response.
	 * @param WP_REST_Server   $server  Server.
	 * @param WP_REST_Request  $request Request.
	 * @return WP_HTTP_Response Unchanged.
	 */
	public static function log_incoming( $result, $server, $request ) {
		$route = (string) $request->get_route();
		if ( 0 === strpos( $route, '/' . Toolbox_POD_REST::NAMESPACE_V1 ) && current_user_can( 'manage_woocommerce' ) ) {
			$status = $result instanceof WP_HTTP_Response ? (int) $result->get_status() : 0;
			self::add( 'incoming', $request->get_method(), $route, $status );
		}
		return $result;
	}

	/**
	 * Adds an entry.
	 *
	 * @param string $direction incoming or outgoing.
	 * @param string $method    HTTP method.
	 * @param string $path      Path; any query string is dropped.
	 * @param int    $status    HTTP status, 0 when there was no response.
	 */
	public static function add( $direction, $method, $path, $status ) {
		$path  = strtok( (string) $path, '?' );
		$log   = self::entries();
		$entry = array(
			'at'     => gmdate( 'c' ),
			'method' => strtoupper( (string) $method ),
			'path'   => false === $path ? '' : substr( $path, 0, self::MAX_PATH ),
			'status' => (int) $status,
		);
		array_unshift( $log[ $direction ], $entry );
		$log[ $direction ] = array_slice( $log[ $direction ], 0, self::KEEP );
		update_option( self::OPTION, $log, false );

		if ( 0 === $entry['status'] || $entry['status'] >= 400 ) {
			wc_get_logger()->warning(
				sprintf( '%s %s %s → %d', $direction, $entry['method'], $entry['path'], $entry['status'] ),
				array( 'source' => 'toolbox-pod' )
			);
		}
	}

	/**
	 * Both lists, newest first.
	 *
	 * @return array{incoming: array[], outgoing: array[]}
	 */
	public static function entries() {
		$log = get_option( self::OPTION );
		return array(
			'incoming' => isset( $log['incoming'] ) && is_array( $log['incoming'] ) ? $log['incoming'] : array(),
			'outgoing' => isset( $log['outgoing'] ) && is_array( $log['outgoing'] ) ? $log['outgoing'] : array(),
		);
	}
}
