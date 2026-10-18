<?php
/**
 * Plugin Name:       WP Image Optimizer
 * Plugin URI:        https://example.com/wp-img-optimizer
 * Description:       Convert existing media library images and new uploads to WebP/AVIF (non-destructively) using Imagick or GD. Mirrors the img2webp feature set: format choice, lossless/quality, max width/height scaling, metadata stripping, and a size-reduction report.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Arturo Crespo
 * License:           MIT
 * Text Domain:       wp-img-optimizer
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WPIO_VERSION', '1.0.0' );
define( 'WPIO_PLUGIN_FILE', __FILE__ );
define( 'WPIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPIO_OPTION_KEY', 'wpio_settings' );
// Attachment meta key storing the list of generated derivative files + stats.
define( 'WPIO_META_KEY', '_wpio_generated' );

require_once WPIO_PLUGIN_DIR . 'includes/class-wpio-capabilities.php';
require_once WPIO_PLUGIN_DIR . 'includes/class-wpio-settings.php';
require_once WPIO_PLUGIN_DIR . 'includes/class-wpio-converter.php';
require_once WPIO_PLUGIN_DIR . 'includes/class-wpio-media.php';
require_once WPIO_PLUGIN_DIR . 'includes/class-wpio-report.php';
require_once WPIO_PLUGIN_DIR . 'includes/class-wpio-plugin.php';

if ( is_admin() ) {
	require_once WPIO_PLUGIN_DIR . 'admin/class-wpio-admin.php';
}

/**
 * Boot the plugin on plugins_loaded.
 *
 * @return void
 */
function wpio_boot() {
	WPIO_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'wpio_boot' );

/**
 * Activation: store default settings if none exist.
 *
 * @return void
 */
function wpio_activate() {
	WPIO_Settings::maybe_set_defaults();
}
register_activation_hook( __FILE__, 'wpio_activate' );

/**
 * Deactivation: nothing destructive. Generated files and settings are kept so
 * deactivating does not break already-served images. Full cleanup happens in
 * uninstall.php only if the user opts in.
 *
 * @return void
 */
function wpio_deactivate() {
	// Intentionally a no-op (non-destructive).
}
register_deactivation_hook( __FILE__, 'wpio_deactivate' );
