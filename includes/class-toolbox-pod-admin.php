<?php
/**
 * The WooCommerce → Toolbox POD screen: menu, tabs, notices, and the not-connected screen.
 *
 * Tabs (`?page=toolbox-pod&tab=…`): Dashboard, Settings, Status, Support. Each tab renders from its own class in
 * includes/admin/. Actions post to admin-post.php with a nonce and come back here with a one-time notice.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
final class Toolbox_POD_Admin {

	const PAGE = 'toolbox-pod';

	/** Per-user transient prefix for the notice shown after an action. */
	const NOTICE_TRANSIENT = 'toolbox_pod_notice_';

	/**
	 * The screen's hook suffix (for enqueueing).
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Registers the menu item, notices, assets, actions and the plugins-list link.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_toolbox_pod_recheck', array( __CLASS__, 'handle_recheck' ) );
		add_action( 'admin_post_toolbox_pod_webhooks_ensure', array( __CLASS__, 'handle_webhooks_ensure' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TOOLBOX_POD_FILE ), array( __CLASS__, 'action_links' ) );
		Toolbox_POD_Settings_Tab::init();
		Toolbox_POD_Support_Tab::init();
	}

	/**
	 * WooCommerce → Toolbox POD.
	 */
	public static function add_menu() {
		self::$hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Toolbox POD', 'toolbox-pod-for-woocommerce' ),
			__( 'Toolbox POD', 'toolbox-pod-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The stylesheet and the few lines of script (copy report, confirm disconnect), on this screen only.
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( '' === self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		$base = plugin_dir_url( TOOLBOX_POD_FILE ) . 'assets/';
		wp_enqueue_style( 'toolbox-pod-admin', $base . 'admin.css', array(), TOOLBOX_POD_VERSION );
		wp_enqueue_script( 'toolbox-pod-admin', $base . 'admin.js', array(), TOOLBOX_POD_VERSION, true );
		wp_localize_script(
			'toolbox-pod-admin',
			'toolboxPodAdmin',
			array( 'copied' => __( 'Copied', 'toolbox-pod-for-woocommerce' ) )
		);
	}

	/**
	 * Adds "Open" next to Deactivate on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$open = sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Open', 'toolbox-pod-for-woocommerce' ) );
		array_unshift( $links, $open );
		return $links;
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy → Policy Guide).
	 * White-label: buyers read the store's policy, so the text names a category, not Toolbox POD.
	 * The guide's section title is wp-admin only and keeps our name.
	 */
	public static function privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'A print-on-demand partner prints and ships some of our products. Orders placed in our store, including your name, shipping address, the items, and your email address and phone number if you gave them, are sent to that partner. For orders that contain its products, it uses them to print the items, ship them and add tracking to your order.', 'toolbox-pod-for-woocommerce' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Toolbox POD for WooCommerce', 'toolbox-pod-for-woocommerce' ), wp_kses_post( $content ) );
	}

	/**
	 * This screen's URL.
	 *
	 * @param string $tab Tab id, or '' for the default.
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * Where the Connect button goes: Toolbox POD signs the owner in, then sends them to WooCommerce's own
	 * screen to approve the API keys, then back here.
	 *
	 * @return string
	 */
	public static function connect_url() {
		return add_query_arg(
			array(
				'store_url'  => rawurlencode( home_url( '/' ) ),
				'return_url' => rawurlencode( self::page_url() ),
			),
			Toolbox_POD_Plugin::app_url() . '/connect/woocommerce'
		);
	}

	/**
	 * Tabs available now: Settings needs a connection.
	 *
	 * @return string[] Id → label.
	 */
	private static function tabs() {
		$tabs = array( 'dashboard' => __( 'Dashboard', 'toolbox-pod-for-woocommerce' ) );
		if ( Toolbox_POD_Plugin::connection() ) {
			$tabs['settings'] = __( 'Settings', 'toolbox-pod-for-woocommerce' );
		}
		$tabs['status']  = __( 'Status', 'toolbox-pod-for-woocommerce' );
		$tabs['support'] = __( 'Support', 'toolbox-pod-for-woocommerce' );
		return $tabs;
	}

	/**
	 * Renders the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}
		?>
		<div class="wrap toolbox-pod">
			<h1><?php esc_html_e( 'Toolbox POD', 'toolbox-pod-for-woocommerce' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="<?php echo esc_url( self::page_url( $id ) ); ?>" class="nav-tab<?php echo $id === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'status' === $tab ) {
				self::render_status();
			} elseif ( 'support' === $tab ) {
				Toolbox_POD_Support_Tab::render();
			} elseif ( 'settings' === $tab ) {
				Toolbox_POD_Settings_Tab::render();
			} elseif ( Toolbox_POD_Plugin::connection() ) {
				Toolbox_POD_Dashboard_Tab::render();
			} else {
				self::render_not_connected();
			}
			?>
		</div>
		<?php
	}

	/**
	 * What Toolbox POD does and the Connect button, disabled while a hard check fails.
	 */
	private static function render_not_connected() {
		$blockers = Toolbox_POD_Status::blockers();
		?>
		<div class="toolbox-pod-card">
			<h2><?php esc_html_e( 'Print on demand for your store', 'toolbox-pod-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Design shirts, publish them to this store, and Toolbox POD prints and ships every order with tracking. You only pay for what sells.', 'toolbox-pod-for-woocommerce' ); ?></p>
			<?php if ( empty( $blockers ) ) : ?>
				<p><a class="button button-primary button-hero" href="<?php echo esc_url( self::connect_url() ); ?>"><?php esc_html_e( 'Connect to Toolbox POD', 'toolbox-pod-for-woocommerce' ); ?></a></p>
			<?php else : ?>
				<p><button type="button" class="button button-primary button-hero" disabled><?php esc_html_e( 'Connect to Toolbox POD', 'toolbox-pod-for-woocommerce' ); ?></button></p>
				<p><strong><?php esc_html_e( 'Fix this first:', 'toolbox-pod-for-woocommerce' ); ?></strong></p>
				<ul class="toolbox-pod-blockers">
					<?php foreach ( $blockers as $check ) : ?>
						<li>
							<?php echo esc_html( $check['message'] ); ?>
							<?php if ( ! empty( $check['fix']['url'] ) ) : ?>
								<a href="<?php echo esc_url( $check['fix']['url'] ); ?>"><?php echo esc_html( $check['fix']['label'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Status tab: the checks with a fix for each, and the request log.
	 */
	private static function render_status() {
		$labels = self::status_labels();
		$log    = Toolbox_POD_Log::entries();
		?>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=toolbox_pod_recheck' ), 'toolbox_pod_recheck' ) ); ?>"><?php esc_html_e( 'Run checks again', 'toolbox-pod-for-woocommerce' ); ?></a>
		</p>
		<table class="widefat striped toolbox-pod-checks">
			<tbody>
			<?php foreach ( Toolbox_POD_Status::checks() as $check ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
					<td><span class="toolbox-pod-pill toolbox-pod-pill-<?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( $labels[ $check['status'] ] ); ?></span></td>
					<td><?php echo esc_html( $check['message'] ); ?></td>
					<td>
						<?php if ( Toolbox_POD_Status::OK !== $check['status'] && ! empty( $check['fix'] ) ) : ?>
							<?php self::render_fix( $check['fix'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<details class="toolbox-pod-details">
			<summary><?php esc_html_e( 'Recent requests', 'toolbox-pod-for-woocommerce' ); ?></summary>
			<?php
			self::render_log( __( 'From Toolbox POD to your store', 'toolbox-pod-for-woocommerce' ), $log['incoming'] );
			self::render_log( __( 'From your store to Toolbox POD', 'toolbox-pod-for-woocommerce' ), $log['outgoing'] );
			?>
		</details>
		<?php
	}

	/**
	 * One fix: a link, or a button posting a named action.
	 *
	 * @param array $fix {label, url} or {label, action}.
	 */
	private static function render_fix( $fix ) {
		if ( ! empty( $fix['url'] ) ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $fix['url'] ), esc_html( $fix['label'] ) );
			return;
		}
		if ( isset( $fix['action'] ) && 'webhooks_ensure' === $fix['action'] ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="toolbox_pod_webhooks_ensure">
				<?php wp_nonce_field( 'toolbox_pod_webhooks_ensure' ); ?>
				<button type="submit" class="button"><?php echo esc_html( $fix['label'] ); ?></button>
			</form>
			<?php
		}
	}

	/**
	 * One request log table.
	 *
	 * @param string  $title   Heading.
	 * @param array[] $entries Entries.
	 */
	private static function render_log( $title, $entries ) {
		?>
		<h3><?php echo esc_html( $title ); ?></h3>
		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'None yet.', 'toolbox-pod-for-woocommerce' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( self::date( $entry['at'] ) ); ?></td>
						<td><code><?php echo esc_html( $entry['method'] . ' ' . $entry['path'] ); ?></code></td>
						<td><?php echo esc_html( 0 === (int) $entry['status'] ? __( 'No answer', 'toolbox-pod-for-woocommerce' ) : (string) (int) $entry['status'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Labels for check statuses.
	 *
	 * @return string[]
	 */
	public static function status_labels() {
		return array(
			Toolbox_POD_Status::OK      => __( 'OK', 'toolbox-pod-for-woocommerce' ),
			Toolbox_POD_Status::WARNING => __( 'Check', 'toolbox-pod-for-woocommerce' ),
			Toolbox_POD_Status::ERROR   => __( 'Fix needed', 'toolbox-pod-for-woocommerce' ),
			Toolbox_POD_Status::INFO    => __( 'Info', 'toolbox-pod-for-woocommerce' ),
		);
	}

	/**
	 * "Run checks again".
	 */
	public static function handle_recheck() {
		self::verify_action( 'toolbox_pod_recheck' );
		Toolbox_POD_Status::clear_cache();
		self::redirect( 'status' );
	}

	/**
	 * "Fix" on the webhooks check: Toolbox POD re-creates or re-enables its webhooks on this store.
	 */
	public static function handle_webhooks_ensure() {
		self::verify_action( 'toolbox_pod_webhooks_ensure' );
		$answer = Toolbox_POD_API::post( 'webhooks/ensure' );
		Toolbox_POD_Status::clear_cache();
		if ( is_wp_error( $answer ) ) {
			self::redirect( 'status', 'error', $answer->get_error_message() );
		}
		$ensured = isset( $answer['ensured'] ) && is_array( $answer['ensured'] ) ? $answer['ensured'] : array();
		$fixed   = (int) ( $ensured['created'] ?? 0 ) + (int) ( $ensured['reenabled'] ?? 0 );
		self::redirect(
			'status',
			'success',
			0 === $fixed
				? __( 'Webhooks checked: nothing needed fixing.', 'toolbox-pod-for-woocommerce' )
				/* translators: %d: number of webhooks created or turned back on. */
				: sprintf( _n( 'Webhooks fixed: %d created or turned back on.', 'Webhooks fixed: %d created or turned back on.', $fixed, 'toolbox-pod-for-woocommerce' ), $fixed )
		);
	}

	/**
	 * Stops unless the user may manage WooCommerce and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	public static function verify_action( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'toolbox-pod-for-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to a tab, optionally with a notice.
	 *
	 * @param string $tab     Tab id.
	 * @param string $type    success, error, warning or info; '' for none.
	 * @param string $message Notice text.
	 * @return never
	 */
	public static function redirect( $tab, $type = '', $message = '' ) {
		if ( '' !== $type ) {
			set_transient(
				self::NOTICE_TRANSIENT . get_current_user_id(),
				array(
					'type'    => $type,
					'message' => $message,
				),
				MINUTE_IN_SECONDS
			);
		}
		wp_safe_redirect( self::page_url( $tab ) );
		exit;
	}

	/**
	 * Notices: reconnect (every wp-admin screen), plugin update and action results (this screen).
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_page = $screen && '' !== self::$hook && $screen->id === self::$hook;

		if ( Toolbox_POD_API::needs_reconnect() ) {
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Your store\'s connection to Toolbox POD stopped working, so new orders won\'t reach us.', 'toolbox-pod-for-woocommerce' ),
				esc_url( self::connect_url() ),
				esc_html__( 'Reconnect', 'toolbox-pod-for-woocommerce' )
			);
		}
		if ( ! $on_page ) {
			return;
		}
		$min = get_transient( Toolbox_POD_API::MIN_VERSION_TRANSIENT );
		if ( is_string( $min ) && '' !== $min ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				/* translators: %s: minimum plugin version. */
				esc_html( sprintf( __( 'Toolbox POD needs version %s or newer of this plugin.', 'toolbox-pod-for-woocommerce' ), $min ) ),
				esc_url( admin_url( 'plugins.php' ) ),
				esc_html__( 'Update Toolbox POD for WooCommerce', 'toolbox-pod-for-woocommerce' )
			);
		}
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) {
			delete_transient( $key );
			$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice['message'] ) );
		}
	}

	/**
	 * A stored ISO date in the site's date and time format.
	 *
	 * @param string $iso ISO 8601 date.
	 * @return string
	 */
	public static function date( $iso ) {
		$time = strtotime( (string) $iso );
		return $time ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
	}

	/**
	 * US dollars from cents, e.g. "$12.50" or "-$3.00".
	 *
	 * @param int|null $cents Cents.
	 * @return string
	 */
	public static function money( $cents ) {
		if ( null === $cents ) {
			return '—';
		}
		$cents = (int) $cents;
		return ( $cents < 0 ? '-' : '' ) . '$' . number_format_i18n( abs( $cents ) / 100, 2 );
	}
}
