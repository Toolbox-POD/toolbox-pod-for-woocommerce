<?php
/**
 * Dashboard tab: account, order counts, money over 28 days, recent Toolbox POD orders, links into Toolbox POD.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard tab.
 */
final class Toolbox_POD_Dashboard_Tab {

	/**
	 * Renders the tab (connected stores only).
	 */
	public static function render() {
		$overview = Toolbox_POD_API::overview();
		if ( is_wp_error( $overview ) ) {
			self::render_unavailable( $overview );
			return;
		}
		$account = isset( $overview['account'] ) && is_array( $overview['account'] ) ? $overview['account'] : array();
		$links   = isset( $overview['links'] ) && is_array( $overview['links'] ) ? $overview['links'] : array();
		$stats   = isset( $overview['stats'] ) && is_array( $overview['stats'] ) ? $overview['stats'] : array();
		?>
		<div class="toolbox-pod-card toolbox-pod-account">
			<div>
				<h2><?php echo esc_html( isset( $account['name'] ) ? (string) $account['name'] : '' ); ?></h2>
				<p>
					<?php
					if ( ! empty( $account['plan_name'] ) ) {
						/* translators: %s: plan name. */
						echo esc_html( sprintf( __( 'Plan: %s', 'toolbox-pod-for-woocommerce' ), $account['plan_name'] ) );
					}
					if ( isset( $account['images_left'], $account['images_limit'] ) ) {
						echo ' · ';
						/* translators: 1: AI images left this month, 2: monthly limit. */
						echo esc_html( sprintf( __( '%1$d of %2$d design images left', 'toolbox-pod-for-woocommerce' ), (int) $account['images_left'], (int) $account['images_limit'] ) );
					}
					?>
				</p>
			</div>
			<p class="toolbox-pod-actions">
				<?php self::link( $links, 'design', __( 'Design a product', 'toolbox-pod-for-woocommerce' ), 'button button-primary' ); ?>
				<?php self::link( $links, 'import', __( 'Import products', 'toolbox-pod-for-woocommerce' ), 'button' ); ?>
				<?php self::link( $links, 'app', __( 'Open Toolbox POD', 'toolbox-pod-for-woocommerce' ), 'button' ); ?>
			</p>
		</div>

		<div class="toolbox-pod-stats">
			<?php self::render_windows( isset( $stats['windows'] ) && is_array( $stats['windows'] ) ? $stats['windows'] : array() ); ?>
			<?php self::stat( __( 'Revenue, last 28 days', 'toolbox-pod-for-woocommerce' ), Toolbox_POD_Admin::money( isset( $stats['revenue_cents'] ) ? (int) $stats['revenue_cents'] : 0 ) ); ?>
			<?php self::stat( __( 'Toolbox POD charges', 'toolbox-pod-for-woocommerce' ), Toolbox_POD_Admin::money( isset( $stats['charges_cents'] ) ? (int) $stats['charges_cents'] : 0 ) ); ?>
			<?php self::stat( __( 'Profit, last 28 days', 'toolbox-pod-for-woocommerce' ), Toolbox_POD_Admin::money( isset( $stats['profit_cents'] ) ? (int) $stats['profit_cents'] : 0 ) ); ?>
		</div>

		<h2><?php esc_html_e( 'Recent orders', 'toolbox-pod-for-woocommerce' ); ?></h2>
		<?php
		self::render_orders( $links );
	}

	/**
	 * Shown when the overview could not be read.
	 *
	 * @param WP_Error $error Why.
	 */
	private static function render_unavailable( $error ) {
		?>
		<div class="toolbox-pod-card">
			<p><?php echo esc_html( $error->get_error_message() ); ?></p>
			<p>
				<?php if ( 'reconnect' === $error->get_error_code() ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( Toolbox_POD_Admin::connect_url() ); ?>"><?php esc_html_e( 'Reconnect', 'toolbox-pod-for-woocommerce' ); ?></a>
				<?php else : ?>
					<a class="button button-primary" href="<?php echo esc_url( Toolbox_POD_Plugin::app_url() . '/app' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open Toolbox POD', 'toolbox-pod-for-woocommerce' ); ?></a>
					<a class="button" href="<?php echo esc_url( Toolbox_POD_Admin::page_url( 'status' ) ); ?>"><?php esc_html_e( 'See store checks', 'toolbox-pod-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Order count cards for the rolling windows, with the trend against the window before.
	 *
	 * @param array[] $windows {key, days, orders, previous_orders}.
	 */
	private static function render_windows( $windows ) {
		$titles = array(
			'day'   => __( 'Orders, last 24 hours', 'toolbox-pod-for-woocommerce' ),
			'week'  => __( 'Orders, last 7 days', 'toolbox-pod-for-woocommerce' ),
			'month' => __( 'Orders, last 28 days', 'toolbox-pod-for-woocommerce' ),
		);
		foreach ( $windows as $window ) {
			$key = isset( $window['key'] ) ? (string) $window['key'] : '';
			if ( ! isset( $titles[ $key ] ) ) {
				continue;
			}
			$orders   = isset( $window['orders'] ) ? (int) $window['orders'] : 0;
			$previous = isset( $window['previous_orders'] ) ? (int) $window['previous_orders'] : 0;
			if ( $orders > $previous ) {
				/* translators: %d: order count in the period before. */
				$trend = sprintf( __( 'Up from %d', 'toolbox-pod-for-woocommerce' ), $previous );
			} elseif ( $orders < $previous ) {
				/* translators: %d: order count in the period before. */
				$trend = sprintf( __( 'Down from %d', 'toolbox-pod-for-woocommerce' ), $previous );
			} else {
				$trend = __( 'Same as the period before', 'toolbox-pod-for-woocommerce' );
			}
			self::stat( $titles[ $key ], number_format_i18n( $orders ), $trend );
		}
	}

	/**
	 * One stats card.
	 *
	 * @param string $title Title.
	 * @param string $value Big value.
	 * @param string $note  Small line under it.
	 */
	private static function stat( $title, $value, $note = '' ) {
		?>
		<div class="toolbox-pod-card toolbox-pod-stat">
			<span class="toolbox-pod-stat-title"><?php echo esc_html( $title ); ?></span>
			<strong class="toolbox-pod-stat-value"><?php echo esc_html( $value ); ?></strong>
			<?php if ( '' !== $note ) : ?>
				<span class="toolbox-pod-stat-note"><?php echo esc_html( $note ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The latest Toolbox POD orders.
	 *
	 * @param array $links Overview links.
	 */
	private static function render_orders( $links ) {
		$answer = Toolbox_POD_API::get( 'orders' );
		if ( is_wp_error( $answer ) ) {
			echo '<p>' . esc_html( $answer->get_error_message() ) . '</p>';
			return;
		}
		$orders = isset( $answer['orders'] ) && is_array( $answer['orders'] ) ? $answer['orders'] : array();
		if ( empty( $orders ) ) {
			echo '<div class="toolbox-pod-card"><p>' . esc_html__( 'No Toolbox POD orders yet.', 'toolbox-pod-for-woocommerce' ) . '</p></div>';
			return;
		}
		?>
		<table class="widefat striped toolbox-pod-orders">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'toolbox-pod-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Date', 'toolbox-pod-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'toolbox-pod-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Status', 'toolbox-pod-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Our charge', 'toolbox-pod-for-woocommerce' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $orders as $order ) : ?>
				<?php
				$woo_id     = isset( $order['woo_order_id'] ) ? absint( $order['woo_order_id'] ) : 0;
				$woo_number = ! empty( $order['woo_order_number'] ) ? (string) $order['woo_order_number'] : (string) $woo_id;
				$our_name   = ! empty( $order['toolbox_name'] ) ? (string) $order['toolbox_name'] : ( isset( $order['number'] ) ? (string) $order['number'] : '' );
				$tone       = isset( $order['status_tone'] ) ? sanitize_html_class( (string) $order['status_tone'] ) : 'neutral';
				?>
				<tr>
					<td>
						<?php if ( $woo_id > 0 ) : ?>
							<a href="<?php echo esc_url( self::order_edit_url( $woo_id ) ); ?>">#<?php echo esc_html( $woo_number ); ?></a>
						<?php endif; ?>
						<?php if ( ! empty( $order['url'] ) ) : ?>
							<a class="description" href="<?php echo esc_url( (string) $order['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $our_name ); ?></a>
						<?php else : ?>
							<span class="description"><?php echo esc_html( $our_name ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $order['test'] ) ) : ?>
							<span class="toolbox-pod-pill toolbox-pod-pill-info"><?php esc_html_e( 'Test', 'toolbox-pod-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( Toolbox_POD_Admin::date( isset( $order['created_at'] ) ? (string) $order['created_at'] : '' ) ); ?></td>
					<td><?php echo esc_html( isset( $order['recipient_first_name'] ) ? (string) $order['recipient_first_name'] : '' ); ?></td>
					<td><span class="toolbox-pod-pill toolbox-pod-tone-<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( isset( $order['status'] ) ? (string) $order['status'] : '' ); ?></span></td>
					<td><?php echo esc_html( Toolbox_POD_Admin::money( isset( $order['charge_cents'] ) ? (int) $order['charge_cents'] : null ) ); ?></td>
					<td>
						<?php if ( ! empty( $order['url'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $order['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in Toolbox POD', 'toolbox-pod-for-woocommerce' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::link( $links, 'orders', __( 'All Toolbox POD orders', 'toolbox-pod-for-woocommerce' ), '' );
	}

	/**
	 * The WooCommerce edit screen for an order, HPOS or not.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function order_edit_url( $order_id ) {
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id );
		}
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * A link from the overview's links, opening Toolbox POD in a new tab.
	 *
	 * @param array  $links Links.
	 * @param string $key   Which.
	 * @param string $text  Text.
	 * @param string $css_class CSS class.
	 */
	private static function link( $links, $key, $text, $css_class ) {
		if ( empty( $links[ $key ] ) ) {
			return;
		}
		printf(
			'<a class="%s" href="%s" target="_blank" rel="noopener">%s</a> ',
			esc_attr( $css_class ),
			esc_url( (string) $links[ $key ] ),
			esc_html( $text )
		);
	}
}
