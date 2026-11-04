<?php
/**
 * Checkout for carts with Toolbox POD products.
 *
 * - Package split: `woocommerce_cart_shipping_packages` moves the items whose product carries `_toolbox_pod_listing`
 *   into their own package. That package gets one rate (Toolbox_POD_Shipping_Method), named by the shipping answer or
 *   "Standard shipping": buyers never see our name (white-label). Its price is our per-order cost from Toolbox POD's
 *   shipping answer (cached an hour), free, or that cost plus the merchant's amount.
 *   The method is registered for zones; when the buyer's zone has no instance of it, the rate is added to our package
 *   anyway, so a merchant needs no shipping setup. "Hide other rates" leaves only ours on that package.
 * - When the shipping answer can't be had (not connected, our API down; a failure is remembered for 5 minutes so
 *   checkout is not slowed), nothing is split and the merchant's own rates apply: checkout never fails because of us.
 * - Toolbox POD ships to US addresses only: our package gets no rate elsewhere, and while the store is connected the
 *   US-only guard refuses the order on classic checkout (`woocommerce_after_checkout_validation`) and block checkout (`woocommerce_store_api_cart_errors`,
 *   once a country is set).
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Package split, rate and US-only guard.
 */
final class Toolbox_POD_Shipping {

	/** Shipping method id. */
	const METHOD_ID = 'toolbox_pod_shipping';

	/** Product meta naming our listing, written at publish. */
	const LISTING_META = '_toolbox_pod_listing';

	/** Package key on our package: the rate's cost and "hide other rates", so WooCommerce's rate cache follows them. */
	const PACKAGE_KEY = 'toolbox_pod';

	/** Transient: the shipping answer could not be had; don't ask again for a while. */
	const UNAVAILABLE_TRANSIENT = 'toolbox_pod_shipping_unavailable';

	/** Where Toolbox POD ships when its shipping answer is not at hand. */
	const FALLBACK_COUNTRIES = array( 'US' );

	/** Error code of the US-only guard. */
	const US_ONLY_ERROR = 'toolbox_pod_us_only';

	/**
	 * Whether each product (parent id) is ours, for this request.
	 *
	 * @var array<int, bool>
	 */
	private static $ours = array();

	/**
	 * The usable shipping answer for this request: null = not read yet, false = none.
	 *
	 * @var array{cents: int, countries: string[], label: string}|false|null
	 */
	private static $config = null;

	/**
	 * Hooks the method, the split, the rates and the guard.
	 */
	public static function init() {
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'load_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_method' ) );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'split_packages' ), 20 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'package_rates' ), 20, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_checkout' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'validate_store_api_cart' ), 10, 2 );
	}

	/**
	 * Loads the method class once WooCommerce's shipping classes are.
	 */
	public static function load_method() {
		require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-shipping-method.php';
	}

	/**
	 * Registers the method for shipping zones.
	 *
	 * @param array $methods Method id => class name.
	 * @return array
	 */
	public static function register_method( $methods ) {
		$methods[ self::METHOD_ID ] = 'Toolbox_POD_Shipping_Method';
		return $methods;
	}

	/**
	 * Moves our items into their own package. Unchanged when the cart has none of ours, the shipping setting is
	 * off, or the shipping answer can't be had.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public static function split_packages( $packages ) {
		if ( ! is_array( $packages ) || empty( $packages ) || ! self::packages_have_ours( $packages ) ) {
			return $packages;
		}
		$settings = Toolbox_POD_Plugin::settings();
		$config   = empty( $settings['shipping_enabled'] ) ? null : self::config();
		if ( ! $config ) {
			return $packages;
		}

		$result = array();
		$ours   = null;
		foreach ( $packages as $package ) {
			$mine = array();
			$rest = array();
			foreach ( self::contents( $package ) as $key => $item ) {
				if ( isset( $item['data'] ) && self::is_ours( $item['data'] ) ) {
					$mine[ $key ] = $item;
				} else {
					$rest[ $key ] = $item;
				}
			}
			if ( empty( $mine ) ) {
				$result[] = $package;
				continue;
			}
			$ours = self::with_contents( null === $ours ? $package : $ours, ( null === $ours ? array() : $ours['contents'] ) + $mine );
			if ( ! empty( $rest ) ) {
				$result[] = self::with_contents( $package, $rest );
			}
		}
		$ours[ self::PACKAGE_KEY ] = array(
			'cost' => self::rate_cost( $config, $settings ),
			'hide' => ! empty( $settings['hide_other_rates'] ),
		);
		$result[]                  = $ours;
		return $result;
	}

	/**
	 * On our package: adds our rate when the zone has no instance of the method, and leaves only ours when "hide
	 * other rates" is on.
	 *
	 * @param WC_Shipping_Rate[] $rates   Rates by id.
	 * @param array              $package Package.
	 * @return WC_Shipping_Rate[]
	 */
	public static function package_rates( $rates, $package ) {
		if ( ! is_array( $rates ) || empty( $package[ self::PACKAGE_KEY ] ) ) {
			return $rates;
		}
		$ours = array();
		foreach ( $rates as $id => $rate ) {
			if ( $rate instanceof WC_Shipping_Rate && self::METHOD_ID === $rate->get_method_id() ) {
				$ours[ $id ] = $rate;
			}
		}
		if ( empty( $ours ) && class_exists( 'Toolbox_POD_Shipping_Method' ) ) {
			$method = new Toolbox_POD_Shipping_Method();
			$ours   = (array) $method->get_rates_for_package( $package );
			$rates  = $ours + $rates;
		}
		return ! empty( $ours ) && ! empty( $package[ self::PACKAGE_KEY ]['hide'] ) ? $ours : $rates;
	}

	/**
	 * The rate for our package, or null (not our package, or a destination we don't ship to).
	 *
	 * @param array $package Package.
	 * @return array|null Arguments for WC_Shipping_Method::add_rate().
	 */
	public static function rate_args( $package ) {
		if ( empty( $package[ self::PACKAGE_KEY ] ) || ! is_array( $package[ self::PACKAGE_KEY ] ) ) {
			return null;
		}
		$config  = self::config();
		$country = isset( $package['destination']['country'] ) ? strtoupper( (string) $package['destination']['country'] ) : '';
		if ( ! $config || ! in_array( $country, $config['countries'], true ) ) {
			return null;
		}
		return array(
			'label'    => $config['label'],
			'cost'     => $package[ self::PACKAGE_KEY ]['cost'],
			'calc_tax' => 'per_order',
			'package'  => $package,
		);
	}

	/**
	 * Classic checkout: refuses the order when the cart has our items and the shipping address (or the billing one,
	 * when shipping to it) is outside the countries we ship to.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Checkout errors.
	 */
	public static function validate_classic_checkout( $data, $errors ) {
		if ( ! $errors instanceof WP_Error || ! function_exists( 'WC' ) || ! self::guard_applies( WC()->cart ) ) {
			return;
		}
		$country = ! empty( $data['shipping_country'] ) ? $data['shipping_country'] : ( isset( $data['billing_country'] ) ? $data['billing_country'] : '' );
		if ( '' !== (string) $country && ! self::ships_to( $country ) ) {
			$errors->add( self::US_ONLY_ERROR, self::us_only_message() );
		}
	}

	/**
	 * Block checkout (Store API): the same, once the buyer's shipping country is set.
	 *
	 * @param WP_Error $errors Cart errors.
	 * @param WC_Cart  $cart   Cart.
	 */
	public static function validate_store_api_cart( $errors, $cart ) {
		if ( ! $errors instanceof WP_Error || ! self::guard_applies( $cart ) || ! WC()->customer ) {
			return;
		}
		$country = (string) WC()->customer->get_shipping_country();
		if ( '' !== $country && ! self::ships_to( $country ) ) {
			$errors->add( self::US_ONLY_ERROR, self::us_only_message() );
		}
	}

	/**
	 * Whether the guard looks at this cart: the store is connected (a disconnected store keeps the listing meta on
	 * products it may now ship itself) and the cart holds one of our products.
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return bool
	 */
	private static function guard_applies( $cart ) {
		return null !== Toolbox_POD_Plugin::connection() && self::cart_has_ours( $cart );
	}

	/**
	 * The guard's message. Buyers read it, so it never names Toolbox POD (white-label).
	 *
	 * @return string
	 */
	public static function us_only_message() {
		return __( 'Some items in your cart ship to US addresses only. Remove them or use a US address.', 'toolbox-pod-for-woocommerce' );
	}

	/**
	 * The rate's name when the shipping answer has none. Buyers see it at checkout, in order emails and in My Account,
	 * so it never names Toolbox POD (white-label).
	 *
	 * @return string
	 */
	public static function default_label() {
		return __( 'Standard shipping', 'toolbox-pod-for-woocommerce' );
	}

	/**
	 * Whether Toolbox POD ships to a country.
	 *
	 * @param string $country ISO country code.
	 * @return bool
	 */
	public static function ships_to( $country ) {
		$settings  = Toolbox_POD_Plugin::settings();
		$config    = empty( $settings['shipping_enabled'] ) ? null : self::config();
		$countries = $config ? $config['countries'] : self::FALLBACK_COUNTRIES;
		return in_array( strtoupper( (string) $country ), $countries, true );
	}

	/**
	 * Whether a product (or its parent, for a variation) is one Toolbox POD published.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function is_ours( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		if ( ! isset( self::$ours[ $id ] ) ) {
			$parent            = $product->get_parent_id() ? wc_get_product( $id ) : $product;
			self::$ours[ $id ] = $parent instanceof WC_Product && '' !== (string) $parent->get_meta( self::LISTING_META );
		}
		return self::$ours[ $id ];
	}

	/**
	 * Whether the cart holds one of our products.
	 *
	 * @param WC_Cart|null $cart Cart.
	 * @return bool
	 */
	public static function cart_has_ours( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return false;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['data'] ) && self::is_ours( $item['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Toolbox POD's shipping answer when it can be used on this store, read once per request.
	 *
	 * @return array{cents: int, countries: string[], label: string}|null
	 */
	public static function config() {
		if ( null !== self::$config ) {
			return self::$config ? self::$config : null;
		}
		self::$config = false;
		if ( ! Toolbox_POD_Plugin::connection() || false !== get_transient( self::UNAVAILABLE_TRANSIENT ) ) {
			return null;
		}
		$answer = Toolbox_POD_API::shipping( Toolbox_POD_API::CHECKOUT_TIMEOUT );
		if ( is_wp_error( $answer ) ) {
			set_transient( self::UNAVAILABLE_TRANSIENT, $answer->get_error_code(), 5 * MINUTE_IN_SECONDS );
			return null;
		}
		$cents     = isset( $answer['cents'] ) && is_int( $answer['cents'] ) ? $answer['cents'] : -1;
		$currency  = isset( $answer['currency'] ) && is_string( $answer['currency'] ) ? strtoupper( $answer['currency'] ) : '';
		$countries = isset( $answer['countries'] ) && is_array( $answer['countries'] ) ? array_values( array_filter( $answer['countries'], 'is_string' ) ) : array();
		$label     = isset( $answer['label'] ) && is_string( $answer['label'] ) ? trim( $answer['label'] ) : '';
		// A price we can't show in this store's currency is no price: the merchant's rates apply.
		if ( $cents < 0 || empty( $countries ) || get_woocommerce_currency() !== $currency ) {
			return null;
		}
		self::$config = array(
			'cents'     => $cents,
			'countries' => array_map( 'strtoupper', $countries ),
			'label'     => '' !== $label ? $label : self::default_label(),
		);
		return self::$config;
	}

	/**
	 * The buyer's price for our package per the Settings tab.
	 *
	 * @param array $config   Shipping answer.
	 * @param array $settings Plugin settings.
	 * @return string Decimal amount.
	 */
	private static function rate_cost( $config, $settings ) {
		if ( 'free' === $settings['price_mode'] ) {
			return '0';
		}
		$cents = $config['cents'] + ( 'cost_plus' === $settings['price_mode'] ? max( 0, (int) $settings['price_plus_cents'] ) : 0 );
		return wc_format_decimal( $cents / 100, 2 );
	}

	/**
	 * Whether any package holds one of our products.
	 *
	 * @param array $packages Packages.
	 * @return bool
	 */
	private static function packages_have_ours( $packages ) {
		foreach ( $packages as $package ) {
			foreach ( self::contents( $package ) as $item ) {
				if ( isset( $item['data'] ) && self::is_ours( $item['data'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A package's items.
	 *
	 * @param array $package Package.
	 * @return array
	 */
	private static function contents( $package ) {
		return is_array( $package ) && isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
	}

	/**
	 * A copy of a package with other items and their cost.
	 *
	 * @param array $package  Package.
	 * @param array $contents Items.
	 * @return array
	 */
	private static function with_contents( $package, $contents ) {
		$package['contents']      = $contents;
		$package['contents_cost'] = array_sum( wp_list_pluck( $contents, 'line_total' ) );
		return $package;
	}
}
