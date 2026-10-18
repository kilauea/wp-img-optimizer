<?php
/**
 * Uninstall routine for WP Image Optimizer.
 *
 * Runs only when the user deletes the plugin from the WordPress admin. Removes
 * the plugin's options and per-attachment meta. Generated .webp/.avif files are
 * left in place by default so existing pages keep working; set the constant
 * WPIO_DELETE_FILES_ON_UNINSTALL to true (e.g. in wp-config.php) to also remove
 * every generated derivative.
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$wpio_option_key = 'wpio_settings';
$wpio_totals_key = 'wpio_report_totals';
$wpio_meta_key   = '_wpio_generated';

// Optionally delete all generated derivative files.
if ( defined( 'WPIO_DELETE_FILES_ON_UNINSTALL' ) && WPIO_DELETE_FILES_ON_UNINSTALL ) {
	$attachment_ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => $wpio_meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		)
	);
	foreach ( (array) $attachment_ids as $aid ) {
		$generated = get_post_meta( (int) $aid, $wpio_meta_key, true );
		if ( is_array( $generated ) ) {
			foreach ( $generated as $entry ) {
				if ( ! empty( $entry['dest'] ) && file_exists( $entry['dest'] ) ) {
					wp_delete_file( $entry['dest'] );
				}
			}
		}
	}
}

// Remove per-attachment meta.
delete_post_meta_by_key( $wpio_meta_key );

// Remove options.
delete_option( $wpio_option_key );
delete_option( $wpio_totals_key );
