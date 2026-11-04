<?php
/**
 * Deleting the plugin disconnects the store and removes what the plugin stored, on every site of a network:
 *   1. when still connected, tell Toolbox POD (best effort, one short call): it sets the products it published to
 *      draft, the same default as the Disconnect button, and stops taking this store's orders;
 *   2. delete the WooCommerce webhooks that deliver to Toolbox POD, and the API key Toolbox POD was given (the
 *      stored key id, or WooCommerce's own "Toolbox POD - API (…)" description), after that call, which still
 *      needs the key to draft the products;
 *   3. delete the plugin's options, transients and "Toolbox POD shipping" zone methods.
 * The products and orders stay: they belong to the store.
 *
 * Runs without the plugin loaded, so it loads the cleanup class itself and repeats the few constants it needs.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-toolbox-pod-cleanup.php';

/**
 * Removes the plugin's data from the current site.
 */
function toolbox_pod_uninstall_site() {
	global $wpdb;

	$app_url = defined( 'TOOLBOX_POD_APP_URL' ) ? (string) TOOLBOX_POD_APP_URL : 'https://toolboxpod.com';
	/** This filter is documented in includes/class-toolbox-pod-plugin.php */
	$app_url = untrailingslashit( (string) apply_filters( 'toolbox_pod_app_url', $app_url ) );

	$connection = get_option( 'toolbox_pod_connection' );
	if ( is_array( $connection ) && ! empty( $connection['token'] ) ) {
		// The answer does not matter: the plugin goes either way, and Toolbox POD marks a store it can no longer
		// reach as needing a reconnect, then disconnects it after 30 days.
		wp_safe_remote_post(
			$app_url . '/api/woocommerce/plugin/v1/disconnect',
			array(
				'timeout'    => 15,
				'user-agent' => 'Toolbox POD for WooCommerce (uninstall); ' . home_url( '/' ),
				'headers'    => array(
					'Authorization' => 'Bearer ' . $connection['token'],
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'body'       => (string) wp_json_encode( array( 'products' => 'draft' ) ),
			)
		);
	}

	Toolbox_POD_Cleanup::run( $app_url, is_array( $connection ) && ! empty( $connection['key_id'] ) ? (int) $connection['key_id'] : 0 );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- With the plugin inactive its zone methods are invisible to the zone API.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s", 'toolbox_pod_shipping' ) );
	// Per-user admin notices (toolbox_pod_notice_<user id>), kept for a minute.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_toolbox_pod_notice_' ) . '%', $wpdb->esc_like( '_transient_timeout_toolbox_pod_notice_' ) . '%' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery

	foreach ( array( 'toolbox_pod_connection', 'toolbox_pod_settings', 'toolbox_pod_log', 'toolbox_pod_needs_reconnect' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'toolbox_pod_app_reachable', 'toolbox_pod_dashboard', 'toolbox_pod_checks', 'toolbox_pod_min_version', 'toolbox_pod_shipping', 'toolbox_pod_shipping_unavailable' ) as $transient ) {
		delete_transient( $transient );
	}
	if ( class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $toolbox_pod_site_id ) {
		switch_to_blog( (int) $toolbox_pod_site_id );
		toolbox_pod_uninstall_site();
		restore_current_blog();
	}
} else {
	toolbox_pod_uninstall_site();
}
