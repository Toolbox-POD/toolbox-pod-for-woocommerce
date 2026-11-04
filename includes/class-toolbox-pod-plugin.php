<?php
/**
 * Bootstrap and the few values every part of the plugin shares.
 *
 * The plugin is a thin connector: Toolbox POD's servers publish products, read orders and write tracking
 * through the WooCommerce REST API with the keys the store owner approves on WooCommerce's own screen.
 * The only credential the plugin keeps is the per-store token Toolbox POD issues at connect, for its own
 * screens and shipping rates.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Toolbox_POD_Plugin {

	/** Option (autoload off) holding what Toolbox POD told us at connect: store id, account name, store token, key id, connected at. */
	const CONNECTION_OPTION = 'toolbox_pod_connection';

	/** Option with the plugin-local settings (Settings tab). */
	const SETTINGS_OPTION = 'toolbox_pod_settings';

	/**
	 * Hooks everything up once WooCommerce is loaded.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Toolbox_POD_REST::init();
		Toolbox_POD_Log::init();
		Toolbox_POD_Tracking::init();
		Toolbox_POD_Shipping::init();
		Toolbox_POD_Size_Guide::init();
		if ( is_admin() ) {
			Toolbox_POD_Admin::init();
		}
	}

	/**
	 * Toolbox POD's origin. A dev site sets TOOLBOX_POD_APP_URL in wp-config.php.
	 *
	 * @return string Origin without a trailing slash.
	 */
	public static function app_url() {
		$url = defined( 'TOOLBOX_POD_APP_URL' ) ? (string) TOOLBOX_POD_APP_URL : 'https://toolboxpod.com';
		/**
		 * Filters the Toolbox POD origin the plugin links to and checks.
		 *
		 * @param string $url Origin without a trailing slash.
		 */
		return untrailingslashit( (string) apply_filters( 'toolbox_pod_app_url', $url ) );
	}

	/**
	 * The connection Toolbox POD recorded on this store, or null when the store is not connected.
	 *
	 * @return array|null
	 */
	public static function connection() {
		$connection = get_option( self::CONNECTION_OPTION );
		return is_array( $connection ) && ! empty( $connection['store_id'] ) ? $connection : null;
	}

	/**
	 * Plugin-local settings with their defaults.
	 *
	 * @return array{shipping_enabled: bool, price_mode: string, price_plus_cents: int, hide_other_rates: bool, size_guide: bool, size_guide_label: string}
	 */
	public static function settings() {
		$defaults = self::default_settings();
		$saved    = get_option( self::SETTINGS_OPTION );
		$settings = is_array( $saved ) ? array_merge( $defaults, array_intersect_key( $saved, $defaults ) ) : $defaults;
		return array(
			'shipping_enabled' => (bool) $settings['shipping_enabled'],
			'price_mode'       => (string) $settings['price_mode'],
			'price_plus_cents' => (int) $settings['price_plus_cents'],
			'hide_other_rates' => (bool) $settings['hide_other_rates'],
			'size_guide'       => (bool) $settings['size_guide'],
			'size_guide_label' => (string) $settings['size_guide_label'],
		);
	}

	/**
	 * Defaults for the plugin-local settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'shipping_enabled' => true,
			'price_mode'       => 'cost',
			'price_plus_cents' => 0,
			'hide_other_rates' => false,
			'size_guide'       => true,
			'size_guide_label' => __( 'Size guide', 'toolbox-pod-for-woocommerce' ),
		);
	}
}
