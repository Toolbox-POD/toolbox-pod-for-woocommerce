<?php
/**
 * Support tab: a copyable technical report, a message to Toolbox POD support, and help links.
 *
 * The report holds versions, settings and check results only: no customer data, no token, no keys.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Support tab.
 */
final class Toolbox_POD_Support_Tab {

	/** Longest report the server accepts. */
	const REPORT_MAX = 20000;

	/** Longest message the server accepts. */
	const MESSAGE_MAX = 5000;

	/**
	 * Registers the send action.
	 */
	public static function init() {
		add_action( 'admin_post_toolbox_pod_support', array( __CLASS__, 'handle_send' ) );
	}

	/**
	 * The technical report as plain text.
	 *
	 * @return string
	 */
	public static function report() {
		$lines   = array();
		$lines[] = '### Toolbox POD for WooCommerce report';
		$lines[] = 'Generated: ' . gmdate( 'c' );
		$lines[] = 'Plugin: ' . TOOLBOX_POD_VERSION;
		$lines[] = 'WordPress: ' . get_bloginfo( 'version' );
		$lines[] = 'WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '-' );
		$lines[] = 'PHP: ' . PHP_VERSION;
		$lines[] = 'Home URL: ' . home_url( '/' );
		$lines[] = 'Currency: ' . get_woocommerce_currency();
		$lines[] = 'Permalinks: ' . ( '' === (string) get_option( 'permalink_structure' ) ? 'plain' : (string) get_option( 'permalink_structure' ) );
		$lines[] = 'Checkout: ' . Toolbox_POD_Status::checkout_type();
		$lines[] = 'HPOS: ' . ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' );
		$lines[] = 'Multisite: ' . ( is_multisite() ? 'yes' : 'no' );
		$lines[] = 'Connected: ' . ( Toolbox_POD_Plugin::connection() ? 'yes' : 'no' );
		$lines[] = 'Plugin settings: ' . wp_json_encode( Toolbox_POD_Plugin::settings() );

		$lines[] = '';
		$lines[] = '### Active plugins';
		foreach ( Toolbox_POD_Status::active_plugins() as $plugin ) {
			$lines[] = $plugin['Name'] . ' ' . $plugin['Version'];
		}

		$lines[] = '';
		$lines[] = '### Checks';
		foreach ( Toolbox_POD_Status::checks() as $check ) {
			$lines[] = $check['id'] . ': ' . $check['status'];
		}

		$log = Toolbox_POD_Log::entries();
		foreach ( array( 'incoming', 'outgoing' ) as $direction ) {
			$lines[] = '';
			$lines[] = '### Requests ' . $direction;
			foreach ( $log[ $direction ] as $entry ) {
				$lines[] = $entry['at'] . ' ' . $entry['method'] . ' ' . $entry['path'] . ' ' . (int) $entry['status'];
			}
		}

		$report = implode( "\n", $lines );
		return strlen( $report ) > self::REPORT_MAX ? substr( $report, 0, self::REPORT_MAX ) : $report;
	}

	/**
	 * Renders the tab.
	 */
	public static function render() {
		$connected = null !== Toolbox_POD_Plugin::connection();
		?>
		<div class="toolbox-pod-card">
			<h2><?php esc_html_e( 'Ask Toolbox POD support', 'toolbox-pod-for-woocommerce' ); ?></h2>
			<?php if ( $connected ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="toolbox_pod_support">
					<?php wp_nonce_field( 'toolbox_pod_support' ); ?>
					<p>
						<label for="toolbox-pod-support-message"><?php esc_html_e( 'What do you need help with?', 'toolbox-pod-for-woocommerce' ); ?></label><br>
						<textarea id="toolbox-pod-support-message" name="message" rows="5" class="large-text" maxlength="<?php echo esc_attr( (string) self::MESSAGE_MAX ); ?>" required></textarea>
					</p>
					<p class="description"><?php esc_html_e( 'The technical report below is sent with your message.', 'toolbox-pod-for-woocommerce' ); ?></p>
					<?php submit_button( __( 'Send to Toolbox POD support', 'toolbox-pod-for-woocommerce' ), 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Connect your store to send a message from here, or use the help links below. You can copy the report into your message.', 'toolbox-pod-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( Toolbox_POD_Plugin::app_url() . '/support' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Toolbox POD help', 'toolbox-pod-for-woocommerce' ); ?></a>
				·
				<a href="https://wordpress.org/support/plugin/toolbox-pod-for-woocommerce/" target="_blank" rel="noopener"><?php esc_html_e( 'Plugin support forum', 'toolbox-pod-for-woocommerce' ); ?></a>
			</p>
		</div>

		<details class="toolbox-pod-card toolbox-pod-details">
			<summary><?php esc_html_e( 'Technical report', 'toolbox-pod-for-woocommerce' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Versions, settings and check results only. No customer data, passwords or keys.', 'toolbox-pod-for-woocommerce' ); ?></p>
			<textarea id="toolbox-pod-report" class="large-text code" rows="16" readonly><?php echo esc_textarea( self::report() ); ?></textarea>
			<p><button type="button" class="button" data-toolbox-pod-copy="toolbox-pod-report"><?php esc_html_e( 'Copy report', 'toolbox-pod-for-woocommerce' ); ?></button></p>
		</details>
		<?php
	}

	/**
	 * Sends the message and the report to Toolbox POD support.
	 */
	public static function handle_send() {
		Toolbox_POD_Admin::verify_action( 'toolbox_pod_support' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in verify_action().
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$message = mb_substr( trim( $message ), 0, self::MESSAGE_MAX );
		if ( '' === $message ) {
			Toolbox_POD_Admin::redirect( 'support', 'error', __( 'Write a message first.', 'toolbox-pod-for-woocommerce' ) );
		}
		$answer = Toolbox_POD_API::post(
			'support',
			array(
				'message' => $message,
				'report'  => self::report(),
			)
		);
		if ( is_wp_error( $answer ) ) {
			Toolbox_POD_Admin::redirect( 'support', 'error', $answer->get_error_message() );
		}
		$reference = isset( $answer['request']['reference'] ) ? (string) $answer['request']['reference'] : '';
		Toolbox_POD_Admin::redirect(
			'support',
			'success',
			'' === $reference
				? __( 'Sent to Toolbox POD support. We usually answer within one business day.', 'toolbox-pod-for-woocommerce' )
				/* translators: %s: support request reference. */
				: sprintf( __( 'Sent to Toolbox POD support (reference %s). We usually answer within one business day.', 'toolbox-pod-for-woocommerce' ), $reference )
		);
	}
}
