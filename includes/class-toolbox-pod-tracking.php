<?php
/**
 * Tracking for the buyer: My Account → View order, the order-received page and the customer order emails.
 *
 * Toolbox POD's servers write the order meta `_toolbox_pod_tracking` through the REST API, a JSON list
 * `[{carrier, number, url, shipped_at}]`. The plugin only reads it. The same numbers also go to
 * `_wc_shipment_tracking_items`, so when a Shipment Tracking plugin shows those, ours steps aside.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracking display.
 */
final class Toolbox_POD_Tracking {

	/** Order meta with the tracking list (JSON string). */
	const META_KEY = '_toolbox_pod_tracking';

	/** Order meta in the WooCommerce Shipment Tracking format, which Shipment Tracking and AST display. */
	const SHIPMENT_TRACKING_META_KEY = '_wc_shipment_tracking_items';

	/** Most packages shown for one order; a longer list counts as malformed. */
	const MAX_PACKAGES = 20;

	/**
	 * Plugin files of the tracking plugins that display `_wc_shipment_tracking_items`. The AST Pro paths are the
	 * ones AST (free) itself checks for in woocommerce-advanced-shipment-tracking.php.
	 */
	const TRACKING_PLUGIN_FILES = array(
		'woocommerce-shipment-tracking/woocommerce-shipment-tracking.php',
		'woo-advanced-shipment-tracking/woocommerce-advanced-shipment-tracking.php',
		'ast-pro/ast-pro.php',
		'advanced-shipment-tracking-pro/advanced-shipment-tracking-pro.php',
	);

	/**
	 * Hooks the order view and the emails.
	 */
	public static function init() {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_details' ) );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'email' ), 20, 4 );
	}

	/**
	 * My Account → View order and the order-received page.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_details( $order ) {
		$packages = self::packages_to_show( $order );
		if ( empty( $packages ) ) {
			return;
		}
		echo '<div class="toolbox-pod-tracking">';
		foreach ( $packages as $package ) {
			echo '<p class="toolbox-pod-tracking__package">' . self::html_line( $package ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html_line() escapes every part.
		}
		echo '</div>';
	}

	/**
	 * Customer order emails, HTML and plain text.
	 *
	 * @param WC_Order      $order         Order.
	 * @param bool          $sent_to_admin Whether the email goes to the store admin.
	 * @param bool          $plain_text    Plain-text email.
	 * @param WC_Email|null $email         Email object.
	 */
	public static function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! ( $order instanceof WC_Order ) ) {
			return;
		}
		if ( $email instanceof WC_Email && in_array( $email->id, array( 'customer_refunded_order', 'customer_partially_refunded_order' ), true ) ) {
			return;
		}
		$packages = self::packages_to_show( $order );
		if ( empty( $packages ) ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n";
			foreach ( $packages as $package ) {
				// Plain text: escaping would turn the & in a tracking URL into &amp;.
				echo wp_strip_all_tags( self::plain_line( $package ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text email body, not HTML.
			}
			echo "\n";
			return;
		}
		echo '<div class="toolbox-pod-tracking" style="margin-bottom: 24px;">';
		foreach ( $packages as $package ) {
			echo '<p style="margin: 0 0 8px;">' . self::html_line( $package ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html_line() escapes every part.
		}
		echo '</div>';
	}

	/**
	 * The order's valid packages, less the ones a Shipment Tracking plugin already shows.
	 *
	 * @param WC_Order $order Order.
	 * @return array[] Each: carrier, number, url (strings; carrier and url may be empty).
	 */
	public static function packages_to_show( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return array();
		}
		$packages = self::read( $order->get_meta( self::META_KEY, true ) );
		if ( empty( $packages ) || ! self::tracking_plugin_active() ) {
			return $packages;
		}
		$shown = self::shipment_tracking_numbers( $order );
		return array_values(
			array_filter(
				$packages,
				function ( $package ) use ( $shown ) {
					return ! in_array( self::normalize_number( $package['number'] ), $shown, true );
				}
			)
		);
	}

	/**
	 * Parses and validates the meta. Anything malformed gives an empty list.
	 *
	 * @param mixed $raw Meta value: a JSON string (or an already decoded array).
	 * @return array[] Each: carrier, number, url.
	 */
	public static function read( $raw ) {
		$list = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $list ) || empty( $list ) || count( $list ) > self::MAX_PACKAGES || array_values( $list ) !== $list ) {
			return array();
		}
		$packages = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['number'] ) || ! is_string( $item['number'] ) ) {
				return array();
			}
			$number = trim( $item['number'] );
			if ( '' === $number ) {
				return array();
			}
			if ( isset( $item['carrier'] ) && ! is_string( $item['carrier'] ) ) {
				return array();
			}
			if ( isset( $item['url'] ) && ! is_string( $item['url'] ) ) {
				return array();
			}
			$packages[] = array(
				'carrier' => isset( $item['carrier'] ) ? trim( $item['carrier'] ) : '',
				'number'  => $number,
				'url'     => isset( $item['url'] ) ? self::safe_url( $item['url'] ) : '',
			);
		}
		return $packages;
	}

	/**
	 * An http(s) URL, or '' when the value is not one.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	private static function safe_url( $url ) {
		$url = trim( $url );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		return '' !== $clean && preg_match( '#^https?://#i', $clean ) ? $clean : '';
	}

	/**
	 * Whether WooCommerce Shipment Tracking or Advanced Shipment Tracking (free or Pro) is active.
	 *
	 * Verified from public code: AST free (wordpress.org woo-advanced-shipment-tracking 4.0.6) defines
	 * wc_advanced_shipment_tracking() and WC_Advanced_Shipment_Tracking_Actions, and names the AST Pro plugin files;
	 * WC_Shipment_Tracking_Actions is the class third-party integrations call on the WooCommerce extension. The
	 * extension's own source is not public, so its plugin file is also checked by its slug.
	 *
	 * @return bool
	 */
	private static function tracking_plugin_active() {
		$active = class_exists( 'WC_Shipment_Tracking_Actions' )
			|| function_exists( 'wc_advanced_shipment_tracking' )
			|| class_exists( 'WC_Advanced_Shipment_Tracking_Actions' );
		if ( ! $active ) {
			$plugins = (array) get_option( 'active_plugins', array() );
			if ( is_multisite() ) {
				$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			$active = (bool) array_intersect( self::TRACKING_PLUGIN_FILES, $plugins );
		}
		/**
		 * Filters whether a Shipment Tracking plugin shows `_wc_shipment_tracking_items`, so the plugin hides the
		 * packages listed there.
		 *
		 * @param bool $active Whether a known tracking plugin is active.
		 */
		return (bool) apply_filters( 'toolbox_pod_tracking_plugin_active', $active );
	}

	/**
	 * Tracking numbers in `_wc_shipment_tracking_items`, normalized.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private static function shipment_tracking_numbers( $order ) {
		$items = $order->get_meta( self::SHIPMENT_TRACKING_META_KEY, true );
		if ( is_string( $items ) ) {
			$items = json_decode( $items, true );
		}
		if ( ! is_array( $items ) ) {
			return array();
		}
		$numbers = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['tracking_number'] ) && is_scalar( $item['tracking_number'] ) ) {
				$numbers[] = self::normalize_number( (string) $item['tracking_number'] );
			}
		}
		return $numbers;
	}

	/**
	 * A tracking number compared without spaces or case.
	 *
	 * @param string $number Tracking number.
	 * @return string
	 */
	private static function normalize_number( $number ) {
		return strtoupper( (string) preg_replace( '/\s+/', '', (string) $number ) );
	}

	/**
	 * One package as escaped HTML: "Tracking: USPS 9400…", the number linked when there is a URL.
	 *
	 * @param array $package Package from read().
	 * @return string
	 */
	private static function html_line( $package ) {
		$number = esc_html( $package['number'] );
		if ( '' !== $package['url'] ) {
			$number = '<a href="' . esc_url( $package['url'] ) . '" target="_blank" rel="noopener noreferrer">' . $number . '</a>';
		}
		return self::line( esc_html( $package['carrier'] ), $number, true );
	}

	/**
	 * One package as plain text: "Tracking: USPS 9400… https://…".
	 *
	 * @param array $package Package from read().
	 * @return string
	 */
	private static function plain_line( $package ) {
		$number = '' !== $package['url'] ? $package['number'] . ' ' . $package['url'] : $package['number'];
		return self::line( $package['carrier'], $number, false );
	}

	/**
	 * The translated "Tracking: carrier number" line.
	 *
	 * @param string $carrier Carrier, may be empty (already escaped for HTML).
	 * @param string $number  Tracking number, with its link or URL (already escaped for HTML).
	 * @param bool   $html    Whether the line is HTML (the translated text is escaped then).
	 * @return string
	 */
	private static function line( $carrier, $number, $html ) {
		if ( '' === $carrier ) {
			/* translators: %s: tracking number, linked to (or followed by) the carrier's tracking page when known. */
			$format = __( 'Tracking: %s', 'toolbox-pod-for-woocommerce' );
			return sprintf( $html ? esc_html( $format ) : $format, $number );
		}
		/* translators: 1: carrier name, e.g. USPS; 2: tracking number, linked to (or followed by) the carrier's tracking page when known. */
		$format = __( 'Tracking: %1$s %2$s', 'toolbox-pod-for-woocommerce' );
		return sprintf( $html ? esc_html( $format ) : $format, $carrier, $number );
	}
}
