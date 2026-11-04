<?php
/**
 * Plugin Name:          Toolbox POD for WooCommerce
 * Plugin URI:           https://toolboxpod.com/woocommerce
 * Description:          Print-on-demand apparel from Toolbox POD. Connect your store, publish designs as products, and we print and ship every order with tracking.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 11.0
 * WC tested up to:      11.2
 * Author:               Toolbox POD
 * Author URI:           https://toolboxpod.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          toolbox-pod-for-woocommerce
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'TOOLBOX_POD_VERSION', '0.1.0' );
define( 'TOOLBOX_POD_FILE', __FILE__ );
define( 'TOOLBOX_POD_DIR', plugin_dir_path( __FILE__ ) );

require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-plugin.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-log.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-api.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-cleanup.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-status.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-rest.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-tracking.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-shipping.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-size-guide.php';
require_once TOOLBOX_POD_DIR . 'includes/class-toolbox-pod-admin.php';
require_once TOOLBOX_POD_DIR . 'includes/admin/class-toolbox-pod-dashboard-tab.php';
require_once TOOLBOX_POD_DIR . 'includes/admin/class-toolbox-pod-settings-tab.php';
require_once TOOLBOX_POD_DIR . 'includes/admin/class-toolbox-pod-support-tab.php';

// Order storage (HPOS) and the cart/checkout blocks: the plugin reads orders only through WooCommerce's CRUD API.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TOOLBOX_POD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TOOLBOX_POD_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Toolbox_POD_Plugin', 'init' ) );
