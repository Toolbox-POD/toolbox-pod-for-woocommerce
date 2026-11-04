<?php
/**
 * The "Toolbox POD shipping" method. It rates only the package
 * Toolbox_POD_Shipping split off for our items; its price comes from the plugin's Settings tab, not from the zone.
 * Loaded on `woocommerce_shipping_init`.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shipping method for zones.
 */
class Toolbox_POD_Shipping_Method extends WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Zone instance id; 0 when the rate is added to a zone without the method.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = Toolbox_POD_Shipping::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Toolbox POD shipping', 'toolbox-pod-for-woocommerce' );
		$this->method_description = __( 'Toolbox POD items ship as their own package with this rate, priced in WooCommerce → Toolbox POD → Settings. They get it in every US zone, with or without this method.', 'toolbox-pod-for-woocommerce' );
		$this->supports           = array( 'shipping-zones' );
		$this->title              = Toolbox_POD_Shipping::default_label();
		$this->enabled            = 'yes';
		$this->tax_status         = 'taxable';
	}

	/**
	 * Adds our rate to our package; other packages get none.
	 *
	 * @param array $package Package.
	 */
	public function calculate_shipping( $package = array() ) {
		$rate = Toolbox_POD_Shipping::rate_args( $package );
		if ( $rate ) {
			$this->add_rate( $rate );
		}
	}
}
