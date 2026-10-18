<?php
/**
 * Standalone server capability check for WP Image Optimizer.
 *
 * Run this from the command line on your server to confirm whether WebP/AVIF
 * output is available via Imagick or GD, BEFORE relying on the plugin:
 *
 *   php tools/check-capabilities.php
 *
 * It is deliberately independent of WordPress so you can run it anywhere PHP is
 * installed. The plugin performs the same detection at runtime via
 * WPIO_Capabilities::detect().
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

/**
 * Format a boolean as a check mark / cross.
 *
 * @param bool $value Flag.
 * @return string
 */
function wpio_yn( bool $value ): string {
	return $value ? 'YES' : 'no';
}

$has_imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
$has_gd      = extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' );

$imagick_formats = array();
if ( $has_imagick ) {
	try {
		$imagick_formats = array_map( 'strtolower', Imagick::queryFormats() );
	} catch ( Throwable $e ) {
		$imagick_formats = array();
	}
}

$imagick_webp = $has_imagick && in_array( 'webp', $imagick_formats, true );
$imagick_avif = $has_imagick && in_array( 'avif', $imagick_formats, true );
$gd_webp      = $has_gd && function_exists( 'imagewebp' );
$gd_avif      = $has_gd && function_exists( 'imageavif' );

echo "WP Image Optimizer — server capability check\n";
echo "PHP version:        " . PHP_VERSION . "\n";
echo "Imagick extension:  " . wpio_yn( $has_imagick ) . "\n";
echo "GD extension:       " . wpio_yn( $has_gd ) . "\n";
echo "Imagick WebP:       " . wpio_yn( $imagick_webp ) . "\n";
echo "Imagick AVIF:       " . wpio_yn( $imagick_avif ) . "\n";
echo "GD WebP:            " . wpio_yn( $gd_webp ) . "\n";
echo "GD AVIF:            " . wpio_yn( $gd_avif ) . "\n";
echo "-------------------------------------------\n";
echo "WebP output usable: " . wpio_yn( $imagick_webp || $gd_webp ) . "\n";
echo "AVIF output usable: " . wpio_yn( $imagick_avif || $gd_avif ) . "\n";

$preferred = $has_imagick ? 'imagick' : ( $has_gd ? 'gd' : 'none' );
echo "Preferred engine:   {$preferred}\n";

if ( ! ( $imagick_webp || $gd_webp ) && ! ( $imagick_avif || $gd_avif ) ) {
	echo "\nWARNING: no WebP/AVIF output engine detected.\n";
	echo "Install php-imagick (with an ImageMagick built with WebP/AVIF\n";
	echo "delegates) or a GD build with WebP/AVIF support.\n";
	exit( 1 );
}

exit( 0 );
