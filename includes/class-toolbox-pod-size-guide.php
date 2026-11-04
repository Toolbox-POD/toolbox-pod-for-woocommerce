<?php
/**
 * The size guide tab on Toolbox POD products.
 *
 * Toolbox POD's servers write the product meta `_toolbox_pod_size_chart` at publish, from their size chart catalog:
 * JSON `{v: 1, garment, unit: "in", columns: [{key, label}], rows: [{size, <key>: number}], source, reviewed}`. The
 * plugin checks it on every read; a chart that does not match shows no tab. Inches (to the nearest 1/8), with a cm
 * table one click away (assets/size-guide.js; without JavaScript the inch table shows). Settings tab: on/off, label.
 *
 * @package ToolboxPodForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Size guide tab.
 */
final class Toolbox_POD_Size_Guide {

	/** Product meta with the chart (JSON string). */
	const META_KEY = '_toolbox_pod_size_chart';

	/** Tab key in `woocommerce_product_tabs`. Buyers can see it (the tab's link is `#tab-<key>`), so it never names us. */
	const TAB_KEY = 'garment_size_guide';

	/** Limits the server's schema sets. */
	const MAX_COLUMNS = 8;
	const MAX_ROWS    = 12;
	const MAX_MEASURE = 100;

	/** Inch fractions to the nearest 1/8. */
	const EIGHTHS = array( '', '⅛', '¼', '⅜', '½', '⅝', '¾', '⅞' );

	/**
	 * Hooks the tab.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'add_tab' ), 30 );
	}

	/**
	 * Adds the tab when the setting is on and the product has a valid chart.
	 *
	 * @param array $tabs Product tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		global $product;
		$settings = Toolbox_POD_Plugin::settings();
		if ( ! is_array( $tabs ) || empty( $settings['size_guide'] ) || ! $product instanceof WC_Product ) {
			return $tabs;
		}
		$chart = self::chart( $product );
		if ( ! $chart ) {
			return $tabs;
		}
		$label                 = (string) $settings['size_guide_label'];
		$tabs[ self::TAB_KEY ] = array(
			'title'    => $label,
			'priority' => 30,
			'callback' => function () use ( $chart, $label ) {
				self::render( $chart, $label );
			},
		);
		return $tabs;
	}

	/**
	 * The product's chart when it matches the format, else null.
	 *
	 * @param WC_Product $product Product (a variation reads its parent).
	 * @return array|null
	 */
	public static function chart( $product ) {
		if ( $product instanceof WC_Product && $product->get_parent_id() ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$raw = $product->get_meta( self::META_KEY );
		return is_string( $raw ) && '' !== $raw ? self::parse( $raw ) : null;
	}

	/**
	 * Checks a chart's JSON.
	 *
	 * @param string $json Meta value.
	 * @return array|null `{columns: [{key, label}], rows: [{size, <key>: float}]}`, or null when it does not match.
	 */
	public static function parse( $json ) {
		$chart = json_decode( $json, true );
		if ( ! is_array( $chart ) || 1 !== ( $chart['v'] ?? null ) || 'in' !== ( $chart['unit'] ?? null ) || true !== ( $chart['reviewed'] ?? null ) ) {
			return null;
		}
		$columns = $chart['columns'] ?? null;
		$rows    = $chart['rows'] ?? null;
		if ( ! is_array( $columns ) || ! is_array( $rows ) || empty( $columns ) || empty( $rows ) || count( $columns ) > self::MAX_COLUMNS || count( $rows ) > self::MAX_ROWS ) {
			return null;
		}
		$keys = array();
		$cols = array();
		foreach ( $columns as $column ) {
			$key   = is_array( $column ) ? ( $column['key'] ?? null ) : null;
			$label = is_array( $column ) ? ( $column['label'] ?? null ) : null;
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z_]{1,24}$/', $key ) || isset( $keys[ $key ] ) || ! is_string( $label ) || '' === $label || mb_strlen( $label ) > 40 ) {
				return null;
			}
			$keys[ $key ] = true;
			$cols[]       = array(
				'key'   => $key,
				'label' => $label,
			);
		}
		$out = array();
		foreach ( $rows as $row ) {
			$size = is_array( $row ) ? ( $row['size'] ?? null ) : null;
			if ( ! is_string( $size ) || '' === $size || mb_strlen( $size ) > 4 || count( $row ) !== count( $keys ) + 1 ) {
				return null;
			}
			$clean = array( 'size' => $size );
			foreach ( $keys as $key => $unused ) {
				$value = $row[ $key ] ?? null;
				if ( ! ( is_int( $value ) || is_float( $value ) ) || $value <= 0 || $value > self::MAX_MEASURE ) {
					return null;
				}
				$clean[ $key ] = (float) $value;
			}
			$out[] = $clean;
		}
		return array(
			'columns' => $cols,
			'rows'    => $out,
		);
	}

	/**
	 * The tab's panel: inch and cm tables and the unit switch.
	 *
	 * @param array  $chart Parsed chart.
	 * @param string $label Tab label.
	 */
	public static function render( $chart, $label ) {
		wp_enqueue_script( 'toolbox-pod-size-guide', plugins_url( 'assets/size-guide.js', TOOLBOX_POD_FILE ), array(), TOOLBOX_POD_VERSION, true );
		echo '<div class="toolbox-pod-size-guide" data-toolbox-pod-size-guide>';
		echo '<h2>' . esc_html( $label ) . '</h2>';
		echo '<p class="toolbox-pod-size-guide__units" hidden>';
		echo '<button type="button" class="button" data-unit="in" aria-pressed="true">' . esc_html__( 'Inches', 'toolbox-pod-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button" data-unit="cm" aria-pressed="false">' . esc_html__( 'Centimeters', 'toolbox-pod-for-woocommerce' ) . '</button>';
		echo '</p>';
		self::table( $chart, 'in' );
		self::table( $chart, 'cm' );
		echo '<p class="toolbox-pod-size-guide__note">' . esc_html__( 'Garment measurements, laid flat.', 'toolbox-pod-for-woocommerce' ) . '</p>';
		echo '</div>';
	}

	/**
	 * One table.
	 *
	 * @param array  $chart Parsed chart.
	 * @param string $unit  "in" or "cm".
	 */
	private static function table( $chart, $unit ) {
		$caption = 'in' === $unit ? __( 'Measurements in inches', 'toolbox-pod-for-woocommerce' ) : __( 'Measurements in centimeters', 'toolbox-pod-for-woocommerce' );
		echo '<table class="shop_table toolbox-pod-size-guide__table" data-unit="' . esc_attr( $unit ) . '"' . ( 'cm' === $unit ? ' hidden' : '' ) . '>';
		echo '<caption>' . esc_html( $caption ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Size', 'toolbox-pod-for-woocommerce' ) . '</th>';
		foreach ( $chart['columns'] as $column ) {
			echo '<th scope="col">' . esc_html( $column['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $chart['rows'] as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row['size'] ) . '</th>';
			foreach ( $chart['columns'] as $column ) {
				$value = $row[ $column['key'] ];
				echo '<td>' . esc_html( 'in' === $unit ? self::inches( $value ) : number_format_i18n( $value * 2.54, 1 ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Inches to the nearest 1/8, e.g. 16.625 → "16⅝".
	 *
	 * @param float $value Inches.
	 * @return string
	 */
	public static function inches( $value ) {
		$eighths = (int) round( $value * 8 );
		$whole   = intdiv( $eighths, 8 );
		return ( $whole > 0 ? number_format_i18n( $whole ) : '' ) . self::EIGHTHS[ $eighths % 8 ];
	}
}
