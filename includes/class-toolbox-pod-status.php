<?php
/**
 * Store checks: the settings and server limits that decide whether the connection works.
 *
 * Shown on the Status tab and returned by GET /wp-json/wc-toolbox-pod/v1/status, so Toolbox POD
 * can tell the merchant what to fix instead of failing quietly.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store checks.
 */
final class Toolbox_POD_Status {

	const OK      = 'ok';
	const WARNING = 'warning';
	const ERROR   = 'error';
	const INFO    = 'info';

	/** Transient with the full list (60 s). */
	const TRANSIENT = 'toolbox_pod_checks';

	/** How late (seconds) scheduled actions may run before webhook delivery counts as stuck. */
	const LATE_ACTIONS_SECONDS = 900;

	/** Toolbox POD's webhook topics on a store (the overview's `webhooks.expected` overrides it). */
	const EXPECTED_WEBHOOKS = 6;

	/** Checks that stop the Connect button when they fail. */
	const HARD = array( 'permalinks' );

	/**
	 * Every check, in display order. Cached for 60 seconds.
	 *
	 * @param bool $fresh Skip the cache. Checks that need our API then use only the cached overview, so a status
	 *                    call from Toolbox POD never calls Toolbox POD back.
	 * @return array[] Each: id, label, status, message, and optionally fix {label, url|action}.
	 */
	public static function checks( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$overview = Toolbox_POD_Plugin::connection() ? Toolbox_POD_API::overview( $fresh ) : null;
		$checks   = array(
			self::check_connection( $overview ),
			self::check_api_key(),
			self::check_webhooks( $overview ),
			self::check_permalinks(),
			self::check_https(),
			self::check_rest_reachable(),
			self::check_wc_auth_reachable(),
			self::check_app_reachable(),
			self::check_auth_header( $overview ),
			self::check_currency(),
			self::check_versions(),
			self::check_memory_limit(),
			self::check_max_execution_time(),
			self::check_uploads_writable(),
			self::check_scheduled_actions(),
			self::check_other_pod_apps(),
			self::check_checkout(),
		);
		if ( ! $fresh ) {
			set_transient( self::TRANSIENT, $checks, MINUTE_IN_SECONDS );
		}
		return $checks;
	}

	/**
	 * Forgets cached answers so the next checks() runs everything again.
	 */
	public static function clear_cache() {
		delete_transient( self::TRANSIENT );
		delete_transient( 'toolbox_pod_app_reachable' );
		delete_transient( Toolbox_POD_API::OVERVIEW_TRANSIENT );
	}

	/**
	 * The hard checks that fail (they block Connect).
	 *
	 * @return array[]
	 */
	public static function blockers() {
		return array_values(
			array_filter(
				self::checks(),
				function ( $check ) {
					return self::ERROR === $check['status'] && in_array( $check['id'], self::HARD, true );
				}
			)
		);
	}

	/**
	 * Builds one check row.
	 *
	 * @param string     $id      Stable id (Toolbox POD reads it).
	 * @param string     $label   What is checked.
	 * @param string     $status  OK, WARNING, ERROR or INFO.
	 * @param string     $message What it means, and what to do when it is not OK.
	 * @param array|null $fix     {label, url} or {label, action}.
	 * @param array      $extra   More fields Toolbox POD reads (other_pod_apps: `apps`, the plugin names).
	 * @return array
	 */
	private static function row( $id, $label, $status, $message, $fix = null, $extra = array() ) {
		$row = array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
		if ( $fix ) {
			$row['fix'] = $fix;
		}
		return array_merge( $row, $extra );
	}

	/**
	 * Row for a check that needs the connection.
	 *
	 * @param string $id    Id.
	 * @param string $label Label.
	 * @return array
	 */
	private static function not_connected( $id, $label ) {
		return self::row( $id, $label, self::INFO, __( 'Not connected.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * Row for a check that needs the overview when it could not be read.
	 *
	 * @param string $id    Id.
	 * @param string $label Label.
	 * @return array
	 */
	private static function not_checked( $id, $label ) {
		return self::row( $id, $label, self::INFO, __( 'Not checked: Toolbox POD did not answer.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * The connection fix: Reconnect.
	 *
	 * @param string $label Button text.
	 * @return array
	 */
	private static function connect_fix( $label ) {
		return array(
			'label' => $label,
			'url'   => Toolbox_POD_Admin::connect_url(),
		);
	}

	/**
	 * Connected, and our API answers with the token.
	 *
	 * @param array|WP_Error|null $overview Overview answer.
	 * @return array
	 */
	private static function check_connection( $overview ) {
		$label = __( 'Connection', 'toolbox-pod-for-woocommerce' );
		if ( ! Toolbox_POD_Plugin::connection() ) {
			return self::row( 'connection', $label, self::INFO, __( 'Not connected.', 'toolbox-pod-for-woocommerce' ), self::connect_fix( __( 'Connect', 'toolbox-pod-for-woocommerce' ) ) );
		}
		if ( is_wp_error( $overview ) && 'reconnect' === $overview->get_error_code() ) {
			return self::row( 'connection', $label, self::ERROR, __( 'Toolbox POD no longer accepts this store\'s connection.', 'toolbox-pod-for-woocommerce' ), self::connect_fix( __( 'Reconnect', 'toolbox-pod-for-woocommerce' ) ) );
		}
		if ( is_array( $overview ) && ! empty( $overview['store']['needs_reconnect'] ) ) {
			return self::row( 'connection', $label, self::ERROR, __( 'Toolbox POD can\'t use this store\'s API key any more.', 'toolbox-pod-for-woocommerce' ), self::connect_fix( __( 'Reconnect', 'toolbox-pod-for-woocommerce' ) ) );
		}
		if ( is_array( $overview ) ) {
			return self::row( 'connection', $label, self::OK, __( 'Connected to Toolbox POD.', 'toolbox-pod-for-woocommerce' ) );
		}
		if ( Toolbox_POD_API::needs_reconnect() ) {
			return self::row( 'connection', $label, self::ERROR, __( 'Toolbox POD no longer accepts this store\'s connection.', 'toolbox-pod-for-woocommerce' ), self::connect_fix( __( 'Reconnect', 'toolbox-pod-for-woocommerce' ) ) );
		}
		if ( null === $overview ) {
			return self::row( 'connection', $label, self::INFO, __( 'Connected. Not checked from here.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'connection', $label, self::WARNING, $overview->get_error_message() );
	}

	/**
	 * The WooCommerce API key the store approved still exists with read/write access.
	 *
	 * @return array
	 */
	private static function check_api_key() {
		global $wpdb;
		$label      = __( 'API key', 'toolbox-pod-for-woocommerce' );
		$connection = Toolbox_POD_Plugin::connection();
		if ( ! $connection ) {
			return self::not_connected( 'api_key', $label );
		}
		$key_id = isset( $connection['key_id'] ) ? (int) $connection['key_id'] : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WooCommerce has no API to read a key row by id.
		$permissions = $key_id > 0 ? $wpdb->get_var( $wpdb->prepare( "SELECT permissions FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d", $key_id ) ) : null;
		if ( 'read_write' === $permissions ) {
			return self::row( 'api_key', $label, self::OK, __( 'The Toolbox POD API key is in place.', 'toolbox-pod-for-woocommerce' ) );
		}
		if ( null === $permissions ) {
			return self::row( 'api_key', $label, self::ERROR, __( 'The Toolbox POD API key was deleted. Reconnect to approve a new one.', 'toolbox-pod-for-woocommerce' ), self::connect_fix( __( 'Reconnect', 'toolbox-pod-for-woocommerce' ) ) );
		}
		return self::row( 'api_key', $label, self::ERROR, __( 'The Toolbox POD API key is not read/write. Reconnect to approve a new one.', 'toolbox-pod-for-woocommerce' ), self::connect_fix( __( 'Reconnect', 'toolbox-pod-for-woocommerce' ) ) );
	}

	/**
	 * Our webhooks exist and are active.
	 *
	 * @param array|WP_Error|null $overview Overview answer.
	 * @return array
	 */
	private static function check_webhooks( $overview ) {
		$label = __( 'Order updates (webhooks)', 'toolbox-pod-for-woocommerce' );
		if ( ! Toolbox_POD_Plugin::connection() ) {
			return self::not_connected( 'webhooks', $label );
		}
		$hooks    = wp_parse_args(
			is_array( $overview ) && isset( $overview['webhooks'] ) && is_array( $overview['webhooks'] ) ? $overview['webhooks'] : array(),
			array(
				'expected'          => self::EXPECTED_WEBHOOKS,
				'awaiting_delivery' => 0,
			)
		);
		$expected = (int) $hooks['expected'];
		$fix      = array(
			'label'  => __( 'Fix', 'toolbox-pod-for-woocommerce' ),
			'action' => 'webhooks_ensure',
		);
		// This store's own webhooks are the truth: one deleted or disabled here never told Toolbox POD.
		$active = self::active_webhooks();
		if ( null === $active ) {
			return self::not_checked( 'webhooks', $label );
		}
		if ( $active !== $expected ) {
			return self::row( 'webhooks', $label, self::ERROR, __( 'Some Toolbox POD webhooks are missing or paused, so new orders reach us late.', 'toolbox-pod-for-woocommerce' ), $fix );
		}
		if ( (int) $hooks['awaiting_delivery'] > 0 ) {
			return self::row( 'webhooks', $label, self::WARNING, __( 'Webhooks are set up. Some have not delivered anything yet; they will after the next order or product change.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'webhooks', $label, self::OK, __( 'Webhooks are set up and active.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * How many active "Toolbox POD" webhooks on this store deliver to this connection's URL; null when WooCommerce's
	 * webhook store can't be read.
	 *
	 * @return int|null
	 */
	private static function active_webhooks() {
		$connection = Toolbox_POD_Plugin::connection();
		if ( ! $connection || ! class_exists( 'WC_Data_Store' ) || ! function_exists( 'wc_get_webhook' ) ) {
			return null;
		}
		try {
			// WC_Data_Store forwards the call to the webhook data store (__call).
			$ids = WC_Data_Store::load( 'webhook' )->search_webhooks( // @phpstan-ignore method.notFound
				array(
					'search' => 'Toolbox POD',
					'limit'  => -1,
				)
			);
		} catch ( Exception $e ) {
			return null;
		}
		$url   = Toolbox_POD_Plugin::app_url() . '/api/woocommerce/webhooks/' . $connection['store_id'];
		$count = 0;
		foreach ( (array) $ids as $id ) {
			$hook = wc_get_webhook( $id );
			if ( $hook && 'Toolbox POD' === $hook->get_name() && 'active' === $hook->get_status() && $url === $hook->get_delivery_url() ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * HTTPS: over plain HTTP the REST API needs signed requests, and some hosts break them.
	 *
	 * @return array
	 */
	private static function check_https() {
		$label = __( 'Secure connection (HTTPS)', 'toolbox-pod-for-woocommerce' );
		if ( 0 === strpos( home_url( '/' ), 'https://' ) ) {
			return self::row( 'https', $label, self::OK, __( 'Your store uses HTTPS.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'https', $label, self::WARNING, __( 'Your store does not use HTTPS. Toolbox POD can still connect, but we recommend turning HTTPS on with your host.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * Pretty permalinks: WooCommerce's app approval screen and REST paths depend on them.
	 *
	 * @return array
	 */
	private static function check_permalinks() {
		$label = __( 'Permalinks', 'toolbox-pod-for-woocommerce' );
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			return self::row( 'permalinks', $label, self::OK, __( 'Pretty permalinks are on.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row(
			'permalinks',
			$label,
			self::ERROR,
			__( 'Plain permalinks are on. Go to Settings → Permalinks and choose any other option, such as "Post name".', 'toolbox-pod-for-woocommerce' ),
			array(
				'label' => __( 'Open permalink settings', 'toolbox-pod-for-woocommerce' ),
				'url'   => admin_url( 'options-permalink.php' ),
			)
		);
	}

	/**
	 * A request from this server to itself (Site Health does the same).
	 *
	 * @param string $url URL on this site.
	 * @return array|WP_Error
	 */
	private static function loopback( $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's loopback filter, as Site Health uses it.
			)
		);
	}

	/**
	 * The REST API answers without redirecting.
	 *
	 * @return array
	 */
	private static function check_rest_reachable() {
		$label    = __( 'Store API (REST)', 'toolbox-pod-for-woocommerce' );
		$response = self::loopback( rest_url() );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return self::row( 'rest_reachable', $label, self::OK, __( 'The WordPress REST API answers.', 'toolbox-pod-for-woocommerce' ) );
		}
		if ( $code >= 300 && $code < 400 ) {
			return self::row( 'rest_reachable', $label, self::ERROR, __( 'The REST API redirects to another address. Check that the WordPress Address and Site Address in Settings → General match how your store is reached.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'rest_reachable', $label, self::ERROR, __( 'The REST API did not answer. A security plugin or your host may be blocking /wp-json/.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * WooCommerce's app approval screen answers (security plugins and mod_security sometimes block it).
	 *
	 * @return array
	 */
	private static function check_wc_auth_reachable() {
		$label    = __( 'WooCommerce approval screen', 'toolbox-pod-for-woocommerce' );
		$response = self::loopback( home_url( '/wc-auth/v1/authorize' ) );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 0 !== $code && 403 !== $code && $code < 500 ) {
			return self::row( 'wc_auth_reachable', $label, self::OK, __( 'WooCommerce\'s approval screen opens.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'wc_auth_reachable', $label, self::ERROR, __( 'WooCommerce\'s approval screen (/wc-auth/v1/authorize) is blocked. Ask your host or security plugin to allow it, or connecting will fail.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * Whether this server can reach Toolbox POD (a firewall or security plugin can block outgoing calls).
	 * Cached for an hour. Only once the owner has connected: before that the plugin contacts no outside server.
	 *
	 * @return array
	 */
	private static function check_app_reachable() {
		$label = __( 'Reaching Toolbox POD', 'toolbox-pod-for-woocommerce' );
		if ( ! Toolbox_POD_Plugin::connection() ) {
			return self::not_connected( 'app_reachable', $label );
		}
		$status = get_transient( 'toolbox_pod_app_reachable' );
		if ( false === $status ) {
			$response = wp_safe_remote_get(
				Toolbox_POD_Plugin::app_url() . '/api/health',
				array(
					'timeout'    => 10,
					'user-agent' => 'Toolbox POD for WooCommerce/' . TOOLBOX_POD_VERSION,
				)
			);
			$status   = ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) ? self::OK : self::ERROR;
			set_transient( 'toolbox_pod_app_reachable', $status, self::OK === $status ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		}
		if ( self::OK === $status ) {
			return self::row( 'app_reachable', $label, self::OK, __( 'Your store can reach Toolbox POD.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'app_reachable', $label, self::ERROR, __( 'Your store could not reach Toolbox POD. A firewall or security plugin may be blocking outgoing requests to toolboxpod.com.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * How Toolbox POD signs in to this store's API (from the overview).
	 *
	 * @param array|WP_Error|null $overview Overview answer.
	 * @return array
	 */
	private static function check_auth_header( $overview ) {
		$label = __( 'API sign-in', 'toolbox-pod-for-woocommerce' );
		if ( ! Toolbox_POD_Plugin::connection() ) {
			return self::not_connected( 'auth_header', $label );
		}
		$mode = is_array( $overview ) && isset( $overview['store']['auth_mode'] ) ? (string) $overview['store']['auth_mode'] : '';
		if ( '' === $mode ) {
			return self::not_checked( 'auth_header', $label );
		}
		if ( 'query' === $mode ) {
			return self::row( 'auth_header', $label, self::WARNING, __( 'Your server drops the Authorization header, so Toolbox POD signs in through the address instead. It works; your host can fix the header.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'auth_header', $label, self::INFO, 'oauth1' === $mode ? __( 'Toolbox POD signs in with signed requests (store without HTTPS).', 'toolbox-pod-for-woocommerce' ) : __( 'Toolbox POD signs in with the Authorization header.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * Store currency is US dollars (Toolbox POD prices and charges in USD).
	 *
	 * @return array
	 */
	private static function check_currency() {
		$label    = __( 'Currency', 'toolbox-pod-for-woocommerce' );
		$currency = get_woocommerce_currency();
		if ( 'USD' === $currency ) {
			return self::row( 'currency', $label, self::OK, __( 'Your store uses US dollars.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row(
			'currency',
			$label,
			self::ERROR,
			/* translators: %s: currency code, e.g. EUR. */
			sprintf( __( 'Your store uses %s. Toolbox POD works with US dollars only. Change it under WooCommerce → Settings → General.', 'toolbox-pod-for-woocommerce' ), $currency ),
			array(
				'label' => __( 'Open settings', 'toolbox-pod-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=wc-settings&tab=general' ),
			)
		);
	}

	/**
	 * WordPress, WooCommerce and PHP are new enough.
	 *
	 * @return array
	 */
	private static function check_versions() {
		$label = __( 'Versions', 'toolbox-pod-for-woocommerce' );
		$wc    = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		$wp    = (string) get_bloginfo( 'version' );
		/* translators: 1: WordPress version, 2: WooCommerce version, 3: PHP version. */
		$now = sprintf( __( 'WordPress %1$s, WooCommerce %2$s, PHP %3$s.', 'toolbox-pod-for-woocommerce' ), $wp, $wc, PHP_VERSION );
		if ( version_compare( $wp, '7.0', '>=' ) && version_compare( $wc, '11.0', '>=' ) && version_compare( PHP_VERSION, '7.4', '>=' ) ) {
			return self::row( 'versions', $label, self::OK, $now );
		}
		return self::row( 'versions', $label, self::ERROR, $now . ' ' . __( 'Toolbox POD needs WordPress 7.0, WooCommerce 11.0 and PHP 7.4 or newer.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * PHP memory limit of at least 128 MB.
	 *
	 * @return array
	 */
	private static function check_memory_limit() {
		$label = __( 'Memory limit', 'toolbox-pod-for-woocommerce' );
		$limit = (string) ini_get( 'memory_limit' );
		$bytes = wp_convert_hr_to_bytes( $limit );
		if ( $bytes < 0 || $bytes >= 128 * MB_IN_BYTES ) {
			/* translators: %s: PHP memory limit, e.g. 256M. */
			return self::row( 'memory_limit', $label, self::OK, sprintf( __( 'PHP memory limit is %s.', 'toolbox-pod-for-woocommerce' ), $limit ) );
		}
		/* translators: %s: PHP memory limit, e.g. 64M. */
		return self::row( 'memory_limit', $label, self::WARNING, sprintf( __( 'PHP memory limit is %s. Ask your host for at least 128M; product images may fail below that.', 'toolbox-pod-for-woocommerce' ), $limit ) );
	}

	/**
	 * PHP time limit of at least 30 seconds (0 means none).
	 *
	 * @return array
	 */
	private static function check_max_execution_time() {
		$label   = __( 'Time limit', 'toolbox-pod-for-woocommerce' );
		$seconds = (int) ini_get( 'max_execution_time' );
		if ( 0 === $seconds || $seconds >= 30 ) {
			return self::row( 'max_execution_time', $label, self::OK, 0 === $seconds ? __( 'PHP has no time limit.', 'toolbox-pod-for-woocommerce' ) : sprintf( /* translators: %d: seconds. */ __( 'PHP time limit is %d seconds.', 'toolbox-pod-for-woocommerce' ), $seconds ) );
		}
		/* translators: %d: seconds. */
		return self::row( 'max_execution_time', $label, self::WARNING, sprintf( __( 'PHP time limit is %d seconds. Ask your host for at least 30; publishing products may time out below that.', 'toolbox-pod-for-woocommerce' ), $seconds ) );
	}

	/**
	 * The uploads folder is writable (product images).
	 *
	 * @return array
	 */
	private static function check_uploads_writable() {
		$label   = __( 'Uploads folder', 'toolbox-pod-for-woocommerce' );
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) && wp_is_writable( $uploads['basedir'] ) ) {
			return self::row( 'uploads_writable', $label, self::OK, __( 'WordPress can save product images.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'uploads_writable', $label, self::ERROR, __( 'WordPress can\'t write to the uploads folder, so product images can\'t be saved. Ask your host to fix the folder permissions.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * Scheduled actions: WooCommerce sends webhooks from Action Scheduler, which runs on WP-Cron.
	 *
	 * @return array
	 */
	private static function check_scheduled_actions() {
		$label = __( 'Background tasks', 'toolbox-pod-for-woocommerce' );
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return self::row( 'scheduled_actions', $label, self::WARNING, __( 'Could not read WooCommerce background tasks.', 'toolbox-pod-for-woocommerce' ) );
		}
		$late = as_get_scheduled_actions(
			array(
				'group'    => 'woocommerce-webhooks',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'date'     => gmdate( 'Y-m-d H:i:s', time() - self::LATE_ACTIONS_SECONDS ),
				'per_page' => 1,
			),
			'ids'
		);
		if ( empty( $late ) ) {
			return self::row( 'scheduled_actions', $label, self::OK, __( 'Webhooks are being sent on time.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'scheduled_actions', $label, self::WARNING, __( 'Some webhooks are more than 15 minutes late, usually because WP-Cron is off or the site gets few visits. Toolbox POD still finds new orders, a little later. Ask your host to run WP-Cron from a real cron job.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * Other print-on-demand plugins that may also fulfill the same orders.
	 *
	 * @return array
	 */
	private static function check_other_pod_apps() {
		$label = __( 'Other print-on-demand apps', 'toolbox-pod-for-woocommerce' );
		$names = array();
		foreach ( self::active_plugins() as $file => $plugin ) {
			$haystack = strtolower( $file . ' ' . $plugin['Name'] );
			if ( false !== strpos( $haystack, 'printful' ) || false !== strpos( $haystack, 'printify' ) ) {
				$names[] = $plugin['Name'];
			}
		}
		if ( empty( $names ) ) {
			return self::row( 'other_pod_apps', $label, self::OK, __( 'No other print-on-demand plugins are active.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row(
			'other_pod_apps',
			$label,
			self::WARNING,
			/* translators: %s: plugin names, comma separated. */
			sprintf( __( 'Also active: %s. Make sure each product is fulfilled by only one service, or an order may be printed twice.', 'toolbox-pod-for-woocommerce' ), implode( ', ', $names ) ),
			null,
			array( 'apps' => $names )
		);
	}

	/**
	 * Block or classic checkout (information for support and shipping).
	 *
	 * @return array
	 */
	private static function check_checkout() {
		$label = __( 'Checkout', 'toolbox-pod-for-woocommerce' );
		if ( 'block' === self::checkout_type() ) {
			return self::row( 'checkout', $label, self::INFO, __( 'Your store uses the block checkout.', 'toolbox-pod-for-woocommerce' ) );
		}
		return self::row( 'checkout', $label, self::INFO, __( 'Your store uses the classic checkout.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * "block" when the checkout page holds the checkout block, else "classic".
	 *
	 * @return string
	 */
	public static function checkout_type() {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		return $page_id > 0 && has_block( 'woocommerce/checkout', $page_id ) ? 'block' : 'classic';
	}

	/**
	 * Active plugins (site and network), keyed by plugin file.
	 *
	 * @return array[] Plugin header data.
	 */
	public static function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_intersect_key( get_plugins(), array_flip( $active ) );
	}
}
