<?php
/**
 * Image-engine capability detection (Imagick vs GD, WebP/AVIF support).
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects which engines and output formats are available on this server.
 */
class WPIO_Capabilities {

	public const ENGINE_IMAGICK = 'imagick';
	public const ENGINE_GD      = 'gd';

	/**
	 * Cached capability snapshot.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Return a capability snapshot describing engines and formats.
	 *
	 * @return array{
	 *     imagick: bool,
	 *     gd: bool,
	 *     imagick_webp: bool,
	 *     imagick_avif: bool,
	 *     gd_webp: bool,
	 *     gd_avif: bool,
	 *     webp: bool,
	 *     avif: bool,
	 *     preferred_engine: string
	 * }
	 */
	public static function detect(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$has_imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$has_gd      = extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' );

		$imagick_formats = array();
		if ( $has_imagick ) {
			try {
				// queryFormats is case-insensitive and returns upper-case names.
				$imagick_formats = array_map( 'strtolower', \Imagick::queryFormats() );
			} catch ( \Throwable $e ) {
				$imagick_formats = array();
			}
		}

		$imagick_webp = $has_imagick && in_array( 'webp', $imagick_formats, true );
		$imagick_avif = $has_imagick && in_array( 'avif', $imagick_formats, true );

		$gd_webp = $has_gd && function_exists( 'imagewebp' );
		// imageavif() exists on PHP 8.1+ when GD was built with AVIF support.
		$gd_avif = $has_gd && function_exists( 'imageavif' );

		$caps = array(
			'imagick'          => $has_imagick,
			'gd'               => $has_gd,
			'imagick_webp'     => $imagick_webp,
			'imagick_avif'     => $imagick_avif,
			'gd_webp'          => $gd_webp,
			'gd_avif'          => $gd_avif,
			'webp'             => $imagick_webp || $gd_webp,
			'avif'             => $imagick_avif || $gd_avif,
			'preferred_engine' => $has_imagick ? self::ENGINE_IMAGICK : ( $has_gd ? self::ENGINE_GD : '' ),
		);

		self::$cache = $caps;
		return $caps;
	}

	/**
	 * Whether a given output format is supported by any available engine.
	 *
	 * @param string $format 'webp' or 'avif'.
	 * @return bool
	 */
	public static function supports_format( string $format ): bool {
		$caps = self::detect();
		return ! empty( $caps[ $format ] );
	}

	/**
	 * Pick the engine to use for a given format.
	 *
	 * Prefers Imagick when it supports the format, otherwise GD when it does.
	 * Returns an empty string when neither can produce the format.
	 *
	 * @param string $format 'webp' or 'avif'.
	 * @return string One of ENGINE_IMAGICK, ENGINE_GD, or ''.
	 */
	public static function engine_for_format( string $format ): string {
		$caps = self::detect();
		if ( ! empty( $caps[ 'imagick_' . $format ] ) ) {
			return self::ENGINE_IMAGICK;
		}
		if ( ! empty( $caps[ 'gd_' . $format ] ) ) {
			return self::ENGINE_GD;
		}
		return '';
	}

	/**
	 * Human-readable summary lines for the admin UI.
	 *
	 * @return array<int,string>
	 */
	public static function summary_lines(): array {
		$c     = self::detect();
		$yesno = static function ( bool $v ): string {
			return $v ? '✓' : '✗';
		};

		return array(
			sprintf( 'Imagick extension: %s', $yesno( $c['imagick'] ) ),
			sprintf( 'GD extension: %s', $yesno( $c['gd'] ) ),
			sprintf( 'WebP output: %s (Imagick %s, GD %s)', $yesno( $c['webp'] ), $yesno( $c['imagick_webp'] ), $yesno( $c['gd_webp'] ) ),
			sprintf( 'AVIF output: %s (Imagick %s, GD %s)', $yesno( $c['avif'] ), $yesno( $c['imagick_avif'] ), $yesno( $c['gd_avif'] ) ),
			sprintf( 'Preferred engine: %s', '' !== $c['preferred_engine'] ? $c['preferred_engine'] : 'none' ),
		);
	}
}
