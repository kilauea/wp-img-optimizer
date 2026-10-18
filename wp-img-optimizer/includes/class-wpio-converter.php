<?php
/**
 * Image converter: scales and encodes a source image to WebP/AVIF using
 * Imagick (preferred) or GD, writing the result alongside the original.
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a single source image file to a WebP/AVIF derivative.
 *
 * Mirrors the img2webp behaviour: aspect-ratio-preserving downscale that never
 * upscales, lossless-by-default or lossy quality, optional metadata stripping,
 * and non-destructive output next to the source.
 */
class WPIO_Converter {

	/**
	 * Conversion options.
	 *
	 * @var array<string,mixed>
	 */
	private $opts;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $opts Settings array (see WPIO_Settings::get()).
	 */
	public function __construct( array $opts ) {
		$this->opts = $opts;
	}

	/**
	 * Compute the destination path for a source file in the chosen format.
	 *
	 * Produces e.g. "photo.jpg" -> "photo.jpg.webp" so the mapping is
	 * unambiguous and never clobbers another source (two different sources
	 * cannot map to the same derivative).
	 *
	 * @param string $src_path Absolute source path.
	 * @param string $format   'webp' or 'avif'.
	 * @return string
	 */
	public static function dest_path( string $src_path, string $format ): string {
		return $src_path . '.' . $format;
	}

	/**
	 * Compute target dimensions, preserving aspect ratio and never upscaling.
	 *
	 * @param int      $w          Source width.
	 * @param int      $h          Source height.
	 * @param int|null $max_width  Max width or null.
	 * @param int|null $max_height Max height or null.
	 * @return array{0:int,1:int} [width, height]
	 */
	public static function compute_size( int $w, int $h, ?int $max_width, ?int $max_height ): array {
		$scales = array();
		if ( $max_width && $max_width > 0 ) {
			$scales[] = $max_width / $w;
		}
		if ( $max_height && $max_height > 0 ) {
			$scales[] = $max_height / $h;
		}
		if ( empty( $scales ) ) {
			return array( $w, $h );
		}
		$scale = min( $scales );
		if ( $scale >= 1.0 ) { // Already fits; never upscale.
			return array( $w, $h );
		}
		return array(
			max( 1, (int) round( $w * $scale ) ),
			max( 1, (int) round( $h * $scale ) ),
		);
	}

	/**
	 * Convert a single file.
	 *
	 * @param string $src_path Absolute path to a source image.
	 * @return array{ok:bool,message:string,dst:string,src_bytes:int,dst_bytes:int}
	 */
	public function convert_file( string $src_path ): array {
		$result = array(
			'ok'        => false,
			'message'   => '',
			'dst'       => '',
			'src_bytes' => 0,
			'dst_bytes' => 0,
		);

		if ( ! is_readable( $src_path ) ) {
			$result['message'] = 'source not readable';
			return $result;
		}

		$format = (string) $this->opts['format'];
		$engine = WPIO_Capabilities::engine_for_format( $format );
		if ( '' === $engine ) {
			$result['message'] = sprintf( 'no engine supports %s on this server', $format );
			return $result;
		}

		$dst = self::dest_path( $src_path, $format );
		$result['dst'] = $dst;

		if ( file_exists( $dst ) && empty( $this->opts['overwrite'] ) ) {
			$result['ok']        = true;
			$result['message']   = 'skipped (exists)';
			$result['src_bytes'] = (int) filesize( $src_path );
			$result['dst_bytes'] = (int) filesize( $dst );
			return $result;
		}

		try {
			if ( WPIO_Capabilities::ENGINE_IMAGICK === $engine ) {
				$this->convert_with_imagick( $src_path, $dst, $format );
			} else {
				$this->convert_with_gd( $src_path, $dst, $format );
			}
		} catch ( \Throwable $e ) {
			$result['message'] = 'error: ' . $e->getMessage();
			return $result;
		}

		if ( ! file_exists( $dst ) ) {
			$result['message'] = 'encoder produced no output';
			return $result;
		}

		$result['ok']        = true;
		$result['message']   = 'ok';
		$result['src_bytes'] = (int) filesize( $src_path );
		$result['dst_bytes'] = (int) filesize( $dst );
		return $result;
	}

	/**
	 * Imagick conversion path.
	 *
	 * @param string $src    Source path.
	 * @param string $dst    Destination path.
	 * @param string $format 'webp' or 'avif'.
	 * @return void
	 * @throws \ImagickException On Imagick failure.
	 */
	private function convert_with_imagick( string $src, string $dst, string $format ): void {
		$img = new \Imagick( $src );

		// Flatten animations/extra frames to the first frame for stills.
		$img = $img->coalesceImages();
		$img->setIteratorIndex( 0 );

		$geo = $img->getImageGeometry();
		list( $tw, $th ) = self::compute_size(
			(int) $geo['width'],
			(int) $geo['height'],
			$this->max_width(),
			$this->max_height()
		);
		if ( $tw !== (int) $geo['width'] || $th !== (int) $geo['height'] ) {
			// Lanczos filter for high-quality downscaling.
			$img->resizeImage( $tw, $th, \Imagick::FILTER_LANCZOS, 1 );
		}

		if ( ! empty( $this->opts['strip_metadata'] ) ) {
			$img->stripImage();
		}

		$img->setImageFormat( $format );

		if ( ! empty( $this->opts['lossless'] ) ) {
			// webp:lossless is honoured for WebP; AVIF treats high quality as
			// near-lossless. Set both the define and a top quality.
			if ( 'webp' === $format ) {
				$img->setOption( 'webp:lossless', 'true' );
			}
			$img->setImageCompressionQuality( 100 );
		} else {
			$img->setImageCompressionQuality( (int) $this->opts['quality'] );
		}

		$img->writeImage( $dst );
		$img->clear();
		$img->destroy();
	}

	/**
	 * GD conversion path.
	 *
	 * @param string $src    Source path.
	 * @param string $dst    Destination path.
	 * @param string $format 'webp' or 'avif'.
	 * @return void
	 * @throws \RuntimeException On GD failure.
	 */
	private function convert_with_gd( string $src, string $dst, string $format ): void {
		$info = getimagesize( $src );
		if ( false === $info ) {
			throw new \RuntimeException( 'unsupported or unreadable image' );
		}

		$src_img = $this->gd_load( $src, (int) $info[2] );
		if ( ! $src_img ) {
			throw new \RuntimeException( 'could not decode source with GD' );
		}

		$sw = imagesx( $src_img );
		$sh = imagesy( $src_img );
		list( $tw, $th ) = self::compute_size( $sw, $sh, $this->max_width(), $this->max_height() );

		if ( $tw !== $sw || $th !== $sh ) {
			$dst_img = imagecreatetruecolor( $tw, $th );
			$this->gd_preserve_alpha( $dst_img );
			imagecopyresampled( $dst_img, $src_img, 0, 0, 0, 0, $tw, $th, $sw, $sh );
			imagedestroy( $src_img );
			$src_img = $dst_img;
		} else {
			$this->gd_preserve_alpha( $src_img );
		}

		// GD always drops EXIF/other metadata on re-encode, so the
		// strip_metadata option is effectively always satisfied here.
		$lossless = ! empty( $this->opts['lossless'] );
		$quality  = (int) $this->opts['quality'];

		$ok = false;
		if ( 'webp' === $format ) {
			// GD WebP quality: 0-100; use 100 to approximate lossless.
			$ok = imagewebp( $src_img, $dst, $lossless ? 100 : $quality );
		} elseif ( 'avif' === $format && function_exists( 'imageavif' ) ) {
			// imageavif( img, file, quality, speed ). -1 quality = lossless.
			$ok = imageavif( $src_img, $dst, $lossless ? -1 : $quality );
		}

		imagedestroy( $src_img );

		if ( ! $ok ) {
			throw new \RuntimeException( 'GD failed to write ' . $format );
		}
	}

	/**
	 * Load an image resource with GD based on the IMAGETYPE constant.
	 *
	 * @param string $src  Source path.
	 * @param int    $type IMAGETYPE_* constant.
	 * @return \GdImage|false
	 */
	private function gd_load( string $src, int $type ) {
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $src );
			case IMAGETYPE_PNG:
				return imagecreatefrompng( $src );
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $src );
			case IMAGETYPE_WEBP:
				return function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $src ) : false;
			default:
				return false;
		}
	}

	/**
	 * Enable alpha preservation on a GD image.
	 *
	 * @param \GdImage $img GD image.
	 * @return void
	 */
	private function gd_preserve_alpha( $img ): void {
		imagealphablending( $img, false );
		imagesavealpha( $img, true );
	}

	/**
	 * Normalised max-width option (null when unset/zero).
	 *
	 * @return int|null
	 */
	private function max_width(): ?int {
		$v = (int) ( $this->opts['max_width'] ?? 0 );
		return $v > 0 ? $v : null;
	}

	/**
	 * Normalised max-height option (null when unset/zero).
	 *
	 * @return int|null
	 */
	private function max_height(): ?int {
		$v = (int) ( $this->opts['max_height'] ?? 0 );
		return $v > 0 ? $v : null;
	}
}
