<?php
/**
 * Removes what Toolbox POD set up on this store through the REST API: the WooCommerce webhooks that deliver to
 * Toolbox POD and the API key the store owner approved for it.
 *
 * Webhooks and API keys are WooCommerce objects, so they outlive the plugin and the connection. Used by the
 * Disconnect button when Toolbox POD could not be told, and by uninstall.php (which loads this file on its own,
 * without the rest of the plugin).
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local cleanup of webhooks and API keys.
 */
final class Toolbox_POD_Cleanup {

	/** Path under the app origin that every Toolbox POD webhook delivers to. */
	const WEBHOOK_PATH = '/api/woocommerce/webhooks/';

	/** The app name the connect flow sends to WooCommerce's key approval screen. */
	const KEY_APP_NAME = 'Toolbox POD';

	/**
	 * Deletes this site's Toolbox POD webhooks and API keys.
	 *
	 * @param string $app_url Toolbox POD origin, without a trailing slash.
	 * @param int    $key_id  The key id stored at connect, 0 when unknown.
	 */
	public static function run( $app_url, $key_id = 0 ) {
		self::delete_webhooks( $app_url );
		self::delete_keys( $key_id );
	}

	/**
	 * Deletes the webhooks whose delivery URL starts with `<app url>/api/woocommerce/webhooks/`.
	 *
	 * @param string $app_url Toolbox POD origin, without a trailing slash.
	 * @return int Webhooks deleted.
	 */
	public static function delete_webhooks( $app_url ) {
		global $wpdb;
		$prefix = $wpdb->esc_like( untrailingslashit( (string) $app_url ) . self::WEBHOOK_PATH ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- WooCommerce's webhook search has no delivery URL filter; the ids then go through its own API.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT webhook_id FROM {$wpdb->prefix}wc_webhooks WHERE delivery_url LIKE %s", $prefix ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		if ( function_exists( 'wc_get_webhook' ) ) {
			$deleted = 0;
			foreach ( $ids as $id ) {
				$webhook = wc_get_webhook( $id );
				if ( $webhook ) {
					$webhook->delete( true );
					++$deleted;
				}
			}
			return $deleted;
		}
		// WooCommerce is not loaded: delete the rows and its cached webhook id lists the way its data store does.
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_webhooks WHERE delivery_url LIKE %s", $prefix ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		foreach ( array( '', '_status_active', '_status_paused', '_status_disabled' ) as $suffix ) {
			delete_transient( 'woocommerce_webhook_ids' . $suffix );
		}
		return $deleted;
	}

	/**
	 * Deletes the API key stored at connect and any key whose description is exactly what WooCommerce writes for a
	 * key approved for our app: "Toolbox POD - API (YYYY-MM-DD HH:MM:SS)". A key the merchant named themselves stays.
	 *
	 * @param int $key_id The key id stored at connect, 0 when unknown.
	 * @return int Keys deleted.
	 */
	public static function delete_keys( $key_id = 0 ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- WooCommerce has no API to delete a key; its own admin deletes the row the same way.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT key_id, description FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE %s", $wpdb->esc_like( self::KEY_APP_NAME . ' - API (' ) . '%' ) );
		$ids  = array();
		foreach ( $rows as $row ) {
			if ( preg_match( '/^' . preg_quote( self::KEY_APP_NAME, '/' ) . ' - API \(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\)$/', (string) $row->description ) ) {
				$ids[] = (int) $row->key_id;
			}
		}
		if ( (int) $key_id > 0 ) {
			$ids[] = (int) $key_id;
		}
		$deleted = 0;
		foreach ( array_unique( $ids ) as $id ) {
			$deleted += (int) $wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $id ), array( '%d' ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return $deleted;
	}
}
