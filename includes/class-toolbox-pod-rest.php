<?php
/**
 * REST routes Toolbox POD calls with the store's WooCommerce API keys.
 *
 * The `wc-` namespace prefix makes WooCommerce authenticate these requests with the same consumer key and
 * secret as /wc/v3, so these routes need no credential of the plugin's own. Every route requires
 * manage_woocommerce.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
final class Toolbox_POD_REST {

	const NAMESPACE_V1 = 'wc-toolbox-pod/v1';

	/**
	 * Registers the routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Route table.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/connection',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'put_connection' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'store_id'        => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^[A-Za-z0-9_-]{1,64}$',
						),
						'account_name'    => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 200,
						),
						'token'           => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^wpt_[A-Za-z0-9_-]{43}$',
						),
						'key_id'          => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
						'replaced_key_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_connection' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'delete_key' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'key_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * Only store managers (the user the API key belongs to must be one).
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Plugin and platform versions plus the store checks.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_status() {
		return rest_ensure_response(
			array(
				'plugin_version'      => TOOLBOX_POD_VERSION,
				'wordpress_version'   => get_bloginfo( 'version' ),
				'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php_version'         => PHP_VERSION,
				'home_url'            => home_url( '/' ),
				'connected'           => null !== Toolbox_POD_Plugin::connection(),
				'currency'            => get_woocommerce_currency(),
				'checkout'            => Toolbox_POD_Status::checkout_type(),
				'settings'            => Toolbox_POD_Plugin::settings(),
				// Fresh, without calling Toolbox POD back: checks that need our API use the cached overview only.
				'checks'              => Toolbox_POD_Status::checks( true ),
			)
		);
	}

	/**
	 * Toolbox POD records the connection after the owner approved the keys.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function put_connection( $request ) {
		$previous   = Toolbox_POD_Plugin::connection();
		$key_id     = (int) $request['key_id'];
		$connection = array(
			'store_id'     => (string) $request['store_id'],
			'account_name' => sanitize_text_field( (string) $request['account_name'] ),
			'token'        => (string) $request['token'],
			'key_id'       => $key_id,
			'connected_at' => gmdate( 'c' ),
			'api_version'  => 1,
		);
		update_option( Toolbox_POD_Plugin::CONNECTION_OPTION, $connection, false );
		// A reconnect: the key the previous connection used is ours to delete.
		$replaced = isset( $request['replaced_key_id'] ) ? (int) $request['replaced_key_id'] : ( $previous && ! empty( $previous['key_id'] ) ? (int) $previous['key_id'] : 0 );
		$deleted  = $replaced > 0 && $replaced !== $key_id && self::delete_our_key( $replaced );
		return rest_ensure_response(
			array(
				'connected'            => true,
				'store_id'             => $connection['store_id'],
				'account_name'         => $connection['account_name'],
				'connected_at'         => $connection['connected_at'],
				'replaced_key_deleted' => $deleted,
			)
		);
	}

	/**
	 * Toolbox POD forgets the store (disconnect on its side, or a refused connect), and with `delete_key` deletes
	 * the API key it was given: `key_id`, else the stored connection's.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_connection( $request ) {
		$connection = Toolbox_POD_Plugin::connection();
		$key_id     = isset( $request['key_id'] ) ? (int) $request['key_id'] : ( $connection && ! empty( $connection['key_id'] ) ? (int) $connection['key_id'] : 0 );
		$deleted    = $request['delete_key'] && $key_id > 0 && self::delete_our_key( $key_id );
		delete_option( Toolbox_POD_Plugin::CONNECTION_OPTION );
		return rest_ensure_response(
			array(
				'connected'   => false,
				'key_deleted' => $deleted,
			)
		);
	}

	/**
	 * Deletes one WooCommerce API key row, only when it is ours (its description starts "Toolbox POD").
	 *
	 * @param int $key_id woocommerce_api_keys.key_id.
	 * @return bool Whether a row was deleted.
	 */
	private static function delete_our_key( $key_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WooCommerce has no API to delete a key; its own admin deletes the row the same way.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d AND description LIKE %s",
				$key_id,
				$wpdb->esc_like( 'Toolbox POD' ) . '%'
			)
		);
		return $deleted > 0;
	}
}
