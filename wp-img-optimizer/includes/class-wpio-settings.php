<?php
/**
 * Plugin settings: defaults, retrieval, registration, and sanitization.
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and sanitizes plugin options in a single wp_options row.
 */
class WPIO_Settings {

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'format'            => 'webp', // 'webp' or 'avif'.
			'lossless'          => false,
			'quality'           => 82,
			'max_width'         => 0,  // 0 = no constraint.
			'max_height'        => 0,
			'strip_metadata'    => true,
			'overwrite'         => false,
			'convert_on_upload' => true,
			'convert_sizes'     => true, // Also convert registered WP sub-sizes.
		);
	}

	/**
	 * Set defaults on activation if no settings exist yet.
	 *
	 * @return void
	 */
	public static function maybe_set_defaults(): void {
		if ( false === get_option( WPIO_OPTION_KEY, false ) ) {
			add_option( WPIO_OPTION_KEY, self::defaults() );
		}
	}

	/**
	 * Get the full, defaults-merged settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( WPIO_OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Register the setting with the Settings API.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			'wpio_settings_group',
			WPIO_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize an incoming settings array from the options form.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		$format         = isset( $input['format'] ) ? sanitize_text_field( (string) $input['format'] ) : $defaults['format'];
		$out['format']  = in_array( $format, array( 'webp', 'avif' ), true ) ? $format : 'webp';
		$out['lossless'] = ! empty( $input['lossless'] );

		$quality        = isset( $input['quality'] ) ? (int) $input['quality'] : $defaults['quality'];
		$out['quality'] = max( 0, min( 100, $quality ) );

		$out['max_width']  = max( 0, (int) ( $input['max_width'] ?? 0 ) );
		$out['max_height'] = max( 0, (int) ( $input['max_height'] ?? 0 ) );

		$out['strip_metadata']    = ! empty( $input['strip_metadata'] );
		$out['overwrite']         = ! empty( $input['overwrite'] );
		$out['convert_on_upload'] = ! empty( $input['convert_on_upload'] );
		$out['convert_sizes']     = ! empty( $input['convert_sizes'] );

		return $out;
	}
}
