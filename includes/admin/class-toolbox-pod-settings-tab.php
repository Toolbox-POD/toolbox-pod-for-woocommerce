<?php
/**
 * Settings tab: order settings kept by Toolbox POD (saved through our API), checkout settings kept on this store
 * (Settings API, option `toolbox_pod_settings`), and Reconnect / Disconnect.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab.
 */
final class Toolbox_POD_Settings_Tab {

	/** Settings API group. */
	const GROUP = 'toolbox_pod_settings';

	/** Buyer price modes for Toolbox POD shipping. */
	const PRICE_MODES = array( 'cost', 'free', 'cost_plus' );

	/**
	 * Registers the setting and the actions.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( __CLASS__, 'capability' ) );
		add_action( 'admin_post_toolbox_pod_server_settings', array( __CLASS__, 'handle_server_settings' ) );
		add_action( 'admin_post_toolbox_pod_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * The local settings option.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			Toolbox_POD_Plugin::SETTINGS_OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Toolbox_POD_Plugin::default_settings(),
			)
		);
	}

	/**
	 * Store managers may save it (options.php asks for manage_options by default).
	 *
	 * @return string
	 */
	public static function capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Cleans the submitted local settings. The form sends the extra price in dollars (`price_plus`).
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = Toolbox_POD_Plugin::default_settings();
		$mode     = isset( $input['price_mode'] ) ? sanitize_key( $input['price_mode'] ) : $defaults['price_mode'];
		if ( isset( $input['price_plus'] ) ) {
			$plus_cents = (int) round( (float) str_replace( array( '$', ',' ), '', (string) $input['price_plus'] ) * 100 );
		} else {
			$plus_cents = isset( $input['price_plus_cents'] ) ? (int) $input['price_plus_cents'] : 0;
		}
		$label = isset( $input['size_guide_label'] ) ? sanitize_text_field( (string) $input['size_guide_label'] ) : '';
		return array(
			'shipping_enabled' => ! empty( $input['shipping_enabled'] ),
			'price_mode'       => in_array( $mode, self::PRICE_MODES, true ) ? $mode : $defaults['price_mode'],
			'price_plus_cents' => max( 0, min( 100000, $plus_cents ) ),
			'hide_other_rates' => ! empty( $input['hide_other_rates'] ),
			'size_guide'       => ! empty( $input['size_guide'] ),
			'size_guide_label' => '' === $label ? $defaults['size_guide_label'] : mb_substr( $label, 0, 60 ),
		);
	}

	/**
	 * Renders the tab.
	 */
	public static function render() {
		settings_errors();
		self::render_server_settings();
		self::render_local_settings();
		self::render_connection();
	}

	/**
	 * Order settings kept by Toolbox POD.
	 */
	private static function render_server_settings() {
		$answer = Toolbox_POD_API::get( 'settings' );
		?>
		<div class="toolbox-pod-card">
			<h2><?php esc_html_e( 'Orders', 'toolbox-pod-for-woocommerce' ); ?></h2>
			<?php if ( is_wp_error( $answer ) || ! isset( $answer['settings'] ) || ! is_array( $answer['settings'] ) ) : ?>
				<p><?php echo esc_html( is_wp_error( $answer ) ? $answer->get_error_message() : __( 'Toolbox POD sent an answer the plugin could not read.', 'toolbox-pod-for-woocommerce' ) ); ?></p>
			<?php else : ?>
				<?php $settings = $answer['settings']; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="toolbox_pod_server_settings">
					<?php wp_nonce_field( 'toolbox_pod_server_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'New orders', 'toolbox-pod-for-woocommerce' ); ?></th>
							<td>
								<fieldset>
									<label><input type="radio" name="approval_mode" value="automatic" <?php checked( 'manual' !== ( $settings['approval_mode'] ?? 'automatic' ) ); ?>> <?php esc_html_e( 'Send to production automatically', 'toolbox-pod-for-woocommerce' ); ?></label><br>
									<label><input type="radio" name="approval_mode" value="manual" <?php checked( 'manual', $settings['approval_mode'] ?? 'automatic' ); ?>> <?php esc_html_e( 'Let me review each order in Toolbox POD first', 'toolbox-pod-for-woocommerce' ); ?></label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'When an order ships', 'toolbox-pod-for-woocommerce' ); ?></th>
							<td>
								<label><input type="checkbox" name="complete_on_ship" value="1" <?php checked( ! empty( $settings['complete_on_ship'] ) ); ?>> <?php esc_html_e( 'Mark the order Completed', 'toolbox-pod-for-woocommerce' ); ?></label><br>
								<label><input type="checkbox" name="customer_note_on_ship" value="1" <?php checked( ! empty( $settings['customer_note_on_ship'] ) ); ?>> <?php esc_html_e( 'Add a note with tracking for the customer', 'toolbox-pod-for-woocommerce' ); ?></label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save order settings', 'toolbox-pod-for-woocommerce' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Checkout and product page settings kept on this store.
	 */
	private static function render_local_settings() {
		$settings = Toolbox_POD_Plugin::settings();
		$name     = Toolbox_POD_Plugin::SETTINGS_OPTION;
		$shipping = Toolbox_POD_API::shipping();
		?>
		<div class="toolbox-pod-card">
			<h2><?php esc_html_e( 'Checkout', 'toolbox-pod-for-woocommerce' ); ?></h2>
			<?php if ( ! is_wp_error( $shipping ) && isset( $shipping['cents'] ) ) : ?>
				<p class="description">
					<?php
					/* translators: %s: shipping price per order, e.g. $5.95. */
					echo esc_html( sprintf( __( 'Toolbox POD shipping: %s per order, US only.', 'toolbox-pod-for-woocommerce' ), Toolbox_POD_Admin::money( (int) $shipping['cents'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Toolbox POD shipping', 'toolbox-pod-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[shipping_enabled]" value="1" <?php checked( $settings['shipping_enabled'] ); ?>> <?php esc_html_e( 'Show it at checkout for Toolbox POD items', 'toolbox-pod-for-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'What buyers pay', 'toolbox-pod-for-woocommerce' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[price_mode]" value="cost" <?php checked( 'cost', $settings['price_mode'] ); ?>> <?php esc_html_e( 'Our shipping cost', 'toolbox-pod-for-woocommerce' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[price_mode]" value="free" <?php checked( 'free', $settings['price_mode'] ); ?>> <?php esc_html_e( 'Free shipping', 'toolbox-pod-for-woocommerce' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[price_mode]" value="cost_plus" <?php checked( 'cost_plus', $settings['price_mode'] ); ?>> <?php esc_html_e( 'Our cost plus', 'toolbox-pod-for-woocommerce' ); ?></label>
								$<input type="number" class="small-text" min="0" step="0.01" name="<?php echo esc_attr( $name ); ?>[price_plus]" value="<?php echo esc_attr( number_format( $settings['price_plus_cents'] / 100, 2, '.', '' ) ); ?>" aria-label="<?php esc_attr_e( 'Extra per order, in dollars', 'toolbox-pod-for-woocommerce' ); ?>">
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Other shipping rates', 'toolbox-pod-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hide_other_rates]" value="1" <?php checked( $settings['hide_other_rates'] ); ?>> <?php esc_html_e( 'Hide other rates for Toolbox POD items', 'toolbox-pod-for-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Size guide', 'toolbox-pod-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[size_guide]" value="1" <?php checked( $settings['size_guide'] ); ?>> <?php esc_html_e( 'Show a size guide tab on Toolbox POD products', 'toolbox-pod-for-woocommerce' ); ?></label>
							<p>
								<label for="toolbox-pod-size-guide-label"><?php esc_html_e( 'Tab name', 'toolbox-pod-for-woocommerce' ); ?></label>
								<input type="text" id="toolbox-pod-size-guide-label" class="regular-text" maxlength="60" name="<?php echo esc_attr( $name ); ?>[size_guide_label]" value="<?php echo esc_attr( $settings['size_guide_label'] ); ?>">
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save checkout settings', 'toolbox-pod-for-woocommerce' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Reconnect and Disconnect.
	 */
	private static function render_connection() {
		$connection = Toolbox_POD_Plugin::connection();
		?>
		<div class="toolbox-pod-card">
			<h2><?php esc_html_e( 'Connection', 'toolbox-pod-for-woocommerce' ); ?></h2>
			<?php if ( $connection && ! empty( $connection['connected_at'] ) ) : ?>
				<p>
					<?php
					/* translators: 1: Toolbox POD account name, 2: date. */
					echo esc_html( sprintf( __( 'Connected to %1$s since %2$s.', 'toolbox-pod-for-woocommerce' ), $connection['account_name'], Toolbox_POD_Admin::date( $connection['connected_at'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<p class="toolbox-pod-actions">
				<a class="button" href="<?php echo esc_url( Toolbox_POD_Admin::connect_url() ); ?>"><?php esc_html_e( 'Reconnect', 'toolbox-pod-for-woocommerce' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-toolbox-pod-confirm="<?php esc_attr_e( 'Disconnect this store from Toolbox POD? New orders will no longer be sent to production.', 'toolbox-pod-for-woocommerce' ); ?>">
				<input type="hidden" name="action" value="toolbox_pod_disconnect">
				<?php wp_nonce_field( 'toolbox_pod_disconnect' ); ?>
				<fieldset>
					<legend><?php esc_html_e( 'Products published from Toolbox POD:', 'toolbox-pod-for-woocommerce' ); ?></legend>
					<label><input type="radio" name="products" value="draft" checked> <?php esc_html_e( 'Set them to draft', 'toolbox-pod-for-woocommerce' ); ?></label><br>
					<label><input type="radio" name="products" value="keep"> <?php esc_html_e( 'Keep them as they are', 'toolbox-pod-for-woocommerce' ); ?></label><br>
					<label><input type="radio" name="products" value="delete"> <?php esc_html_e( 'Move them to the trash', 'toolbox-pod-for-woocommerce' ); ?></label>
				</fieldset>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Disconnect', 'toolbox-pod-for-woocommerce' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Saves the order settings through our API.
	 */
	public static function handle_server_settings() {
		Toolbox_POD_Admin::verify_action( 'toolbox_pod_server_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify_action().
		$mode = isset( $_POST['approval_mode'] ) ? sanitize_key( wp_unslash( $_POST['approval_mode'] ) ) : 'automatic';
		$body = array(
			'approval_mode'         => 'manual' === $mode ? 'manual' : 'automatic',
			'complete_on_ship'      => ! empty( $_POST['complete_on_ship'] ),
			'customer_note_on_ship' => ! empty( $_POST['customer_note_on_ship'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$answer = Toolbox_POD_API::put( 'settings', $body );
		if ( is_wp_error( $answer ) ) {
			Toolbox_POD_Admin::redirect( 'settings', 'error', $answer->get_error_message() );
		}
		Toolbox_POD_Admin::redirect( 'settings', 'success', __( 'Order settings saved.', 'toolbox-pod-for-woocommerce' ) );
	}

	/**
	 * Disconnect: tell Toolbox POD, with what to do with the products it published (draft by default, keep, or
	 * trash), then forget the connection here whatever it answered (a 401 means it already forgot us; during the
	 * call it may also have deleted the option and our key itself). When it could not be told, the webhooks that
	 * deliver to it and our API key are deleted here, so the store sends it nothing more.
	 */
	public static function handle_disconnect() {
		Toolbox_POD_Admin::verify_action( 'toolbox_pod_disconnect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in verify_action().
		$products = isset( $_POST['products'] ) ? sanitize_key( wp_unslash( $_POST['products'] ) ) : 'draft';
		if ( ! in_array( $products, array( 'draft', 'keep', 'delete' ), true ) ) {
			$products = 'draft';
		}
		$stored = get_option( Toolbox_POD_Plugin::CONNECTION_OPTION );
		$key_id = is_array( $stored ) && ! empty( $stored['key_id'] ) ? (int) $stored['key_id'] : 0;
		$answer = Toolbox_POD_API::post( 'disconnect', array( 'products' => $products ) );
		delete_option( Toolbox_POD_Plugin::CONNECTION_OPTION );
		delete_option( Toolbox_POD_API::NEEDS_RECONNECT_OPTION );
		delete_transient( Toolbox_POD_API::SHIPPING_TRANSIENT );
		Toolbox_POD_Status::clear_cache();
		if ( is_wp_error( $answer ) && 'reconnect' !== $answer->get_error_code() ) {
			Toolbox_POD_Cleanup::run( Toolbox_POD_Plugin::app_url(), $key_id );
			Toolbox_POD_Admin::redirect( 'dashboard', 'warning', __( 'Disconnected on this store, which no longer sends anything to Toolbox POD: its webhooks and API key were removed here. Toolbox POD could not be told right now; disconnect there too.', 'toolbox-pod-for-woocommerce' ) );
		}
		Toolbox_POD_Admin::redirect( 'dashboard', 'success', __( 'Disconnected from Toolbox POD.', 'toolbox-pod-for-woocommerce' ) );
	}
}
