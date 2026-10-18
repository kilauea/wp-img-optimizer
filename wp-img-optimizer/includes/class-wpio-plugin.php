<?php
/**
 * Plugin orchestration: registers hooks for on-upload conversion, media
 * cleanup, and admin wiring.
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton that boots the plugin's runtime hooks.
 */
class WPIO_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WPIO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return WPIO_Plugin
	 */
	public static function instance(): WPIO_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Convert on new upload once WordPress has generated the sub-sizes.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_generate_metadata' ), 20, 2 );

		// Clean up derivatives when an attachment is deleted.
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );

		if ( is_admin() ) {
			WPIO_Admin::instance()->init();
		}
	}

	/**
	 * Convert a freshly uploaded attachment after its metadata (and sub-sizes)
	 * have been generated. Returns the metadata unchanged (filter contract).
	 *
	 * @param array<string,mixed> $metadata      Attachment metadata.
	 * @param int                 $attachment_id Attachment ID.
	 * @return array<string,mixed>
	 */
	public function on_generate_metadata( $metadata, $attachment_id ) {
		$opts = WPIO_Settings::get();
		if ( empty( $opts['convert_on_upload'] ) ) {
			return $metadata;
		}
		if ( WPIO_Media::is_convertible( (int) $attachment_id ) ) {
			WPIO_Media::convert_attachment( (int) $attachment_id, $opts );
		}
		return $metadata;
	}

	/**
	 * Remove derivative files when the source attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_delete_attachment( $attachment_id ): void {
		WPIO_Media::delete_derivatives( (int) $attachment_id );
	}
}
