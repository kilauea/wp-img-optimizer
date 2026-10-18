<?php
/**
 * Size-reduction report helpers: aggregate original/new bytes and reduction %.
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Running totals for a conversion run, persisted so the admin page can show a
 * cumulative size-reduction report.
 */
class WPIO_Report {

	public const TOTALS_OPTION = 'wpio_report_totals';

	/**
	 * Zeroed totals structure.
	 *
	 * @return array{files:int,original_bytes:int,new_bytes:int}
	 */
	public static function empty_totals(): array {
		return array(
			'files'          => 0,
			'original_bytes' => 0,
			'new_bytes'      => 0,
		);
	}

	/**
	 * Fetch persisted totals (defaults-merged).
	 *
	 * @return array{files:int,original_bytes:int,new_bytes:int}
	 */
	public static function get_totals(): array {
		$t = get_option( self::TOTALS_OPTION, array() );
		if ( ! is_array( $t ) ) {
			$t = array();
		}
		return wp_parse_args( $t, self::empty_totals() );
	}

	/**
	 * Reset the persisted totals.
	 *
	 * @return void
	 */
	public static function reset_totals(): void {
		update_option( self::TOTALS_OPTION, self::empty_totals(), false );
	}

	/**
	 * Add one converted derivative to the running totals.
	 *
	 * @param int $original_bytes Source size.
	 * @param int $new_bytes      Derivative size.
	 * @return void
	 */
	public static function add( int $original_bytes, int $new_bytes ): void {
		$t                    = self::get_totals();
		$t['files']          += 1;
		$t['original_bytes'] += $original_bytes;
		$t['new_bytes']      += $new_bytes;
		update_option( self::TOTALS_OPTION, $t, false );
	}

	/**
	 * Reduction percentage for a given original/new pair.
	 *
	 * @param int $original_bytes Source size.
	 * @param int $new_bytes      Derivative size.
	 * @return float Positive means the output is smaller.
	 */
	public static function reduction_pct( int $original_bytes, int $new_bytes ): float {
		if ( $original_bytes <= 0 ) {
			return 0.0;
		}
		return ( 1.0 - ( $new_bytes / $original_bytes ) ) * 100.0;
	}

	/**
	 * Format a byte count as a short human-readable string.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	public static function human_size( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$size  = (float) $bytes;
		$i     = 0;
		while ( $size >= 1024 && $i < count( $units ) - 1 ) {
			$size /= 1024;
			++$i;
		}
		return 0 === $i ? sprintf( '%d %s', $size, $units[ $i ] ) : sprintf( '%.1f %s', $size, $units[ $i ] );
	}
}
