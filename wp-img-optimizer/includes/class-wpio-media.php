<?php
/**
 * Media-library integration: convert an attachment's full image and registered
 * sub-sizes, record generated derivatives, and hook new uploads.
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts WordPress attachments non-destructively.
 */
class WPIO_Media {

	/**
	 * Source MIME types we convert.
	 *
	 * @var array<int,string>
	 */
	private const SOURCE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Whether an attachment is an image type we can convert.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_convertible( int $attachment_id ): bool {
		$mime = get_post_mime_type( $attachment_id );
		return is_string( $mime ) && in_array( $mime, self::SOURCE_MIMES, true );
	}

	/**
	 * Collect the absolute file paths for an attachment: the full-size file and
	 * (optionally) each registered sub-size.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string,mixed> $opts          Settings.
	 * @return array<int,string> Absolute paths that exist on disk.
	 */
	public static function source_paths( int $attachment_id, array $opts ): array {
		$paths = array();
		$full  = get_attached_file( $attachment_id );
		if ( ! $full || ! file_exists( $full ) ) {
			return $paths;
		}
		$paths[] = $full;

		if ( ! empty( $opts['convert_sizes'] ) ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				$base = trailingslashit( dirname( $full ) );
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$candidate = $base . $size['file'];
						if ( file_exists( $candidate ) ) {
							$paths[] = $candidate;
						}
					}
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Convert a single attachment (full + sub-sizes). Records derivatives in
	 * attachment meta and updates the cumulative report totals.
	 *
	 * @param int                      $attachment_id Attachment ID.
	 * @param array<string,mixed>|null $opts          Settings (fetched if null).
	 * @return array{converted:int,skipped:int,failed:int,original_bytes:int,new_bytes:int,messages:array<int,string>}
	 */
	public static function convert_attachment( int $attachment_id, ?array $opts = null ): array {
		$opts = $opts ?? WPIO_Settings::get();

		$summary = array(
			'converted'      => 0,
			'skipped'        => 0,
			'failed'         => 0,
			'original_bytes' => 0,
			'new_bytes'      => 0,
			'messages'       => array(),
		);

		if ( ! self::is_convertible( $attachment_id ) ) {
			$summary['messages'][] = 'not a convertible image';
			return $summary;
		}

		$converter = new WPIO_Converter( $opts );
		$generated = array();

		foreach ( self::source_paths( $attachment_id, $opts ) as $src ) {
			$res = $converter->convert_file( $src );

			if ( ! $res['ok'] ) {
				++$summary['failed'];
				$summary['messages'][] = basename( $src ) . ': ' . $res['message'];
				continue;
			}

			if ( 'skipped (exists)' === $res['message'] ) {
				++$summary['skipped'];
			} else {
				++$summary['converted'];
				$summary['original_bytes'] += $res['src_bytes'];
				$summary['new_bytes']      += $res['dst_bytes'];
				WPIO_Report::add( $res['src_bytes'], $res['dst_bytes'] );
			}

			$generated[] = array(
				'source'    => $src,
				'dest'      => $res['dst'],
				'src_bytes' => $res['src_bytes'],
				'dst_bytes' => $res['dst_bytes'],
			);
		}

		if ( ! empty( $generated ) ) {
			update_post_meta( $attachment_id, WPIO_META_KEY, $generated );
		}

		return $summary;
	}

	/**
	 * Delete all derivative files recorded for an attachment (used on media
	 * deletion so we do not leave orphaned .webp/.avif files behind).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function delete_derivatives( int $attachment_id ): void {
		$generated = get_post_meta( $attachment_id, WPIO_META_KEY, true );
		if ( is_array( $generated ) ) {
			foreach ( $generated as $entry ) {
				if ( ! empty( $entry['dest'] ) && file_exists( $entry['dest'] ) ) {
					wp_delete_file( $entry['dest'] );
				}
			}
		}
		delete_post_meta( $attachment_id, WPIO_META_KEY );
	}

	/**
	 * Return attachment IDs for all convertible images (for bulk processing).
	 *
	 * @return array<int,int>
	 */
	public static function all_image_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => self::SOURCE_MIMES,
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return array_map( 'intval', (array) $ids );
	}
}
