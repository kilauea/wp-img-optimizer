<?php
/**
 * Admin UI: settings page, bulk-convert page, and AJAX batch handlers.
 *
 * @package WP_Img_Optimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the admin menu, pages, and AJAX endpoints.
 */
class WPIO_Admin {

	public const MENU_SLUG   = 'wpio-settings';
	public const BULK_SLUG   = 'wpio-bulk';
	public const AJAX_ACTION = 'wpio_bulk_convert';
	public const NONCE       = 'wpio_bulk_nonce';
	public const CAP         = 'manage_options';

	/**
	 * Singleton instance.
	 *
	 * @var WPIO_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return WPIO_Admin
	 */
	public static function instance(): WPIO_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( 'WPIO_Settings', 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_bulk_convert' ) );
		add_action( 'wp_ajax_wpio_bulk_init', array( $this, 'ajax_bulk_init' ) );
	}

	/**
	 * Register the top-level menu and sub-pages.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Image Optimizer', 'wp-img-optimizer' ),
			__( 'Image Optimizer', 'wp-img-optimizer' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-images-alt2'
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'wp-img-optimizer' ),
			__( 'Settings', 'wp-img-optimizer' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bulk Convert', 'wp-img-optimizer' ),
			__( 'Bulk Convert', 'wp-img-optimizer' ),
			self::CAP,
			self::BULK_SLUG,
			array( $this, 'render_bulk_page' )
		);
	}

	/**
	 * Enqueue the bulk-page script/style only on our bulk page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::BULK_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'wpio-admin',
			WPIO_PLUGIN_URL . 'assets/wpio-admin.css',
			array(),
			WPIO_VERSION
		);
		wp_enqueue_script(
			'wpio-bulk',
			WPIO_PLUGIN_URL . 'assets/wpio-bulk.js',
			array( 'jquery' ),
			WPIO_VERSION,
			true
		);
		wp_localize_script(
			'wpio-bulk',
			'WPIO',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'action'  => self::AJAX_ACTION,
				'strings' => array(
					'done'    => __( 'Done.', 'wp-img-optimizer' ),
					'running' => __( 'Converting…', 'wp-img-optimizer' ),
				),
			)
		);
	}

	/**
	 * Render the settings page (capabilities panel + options form).
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$caps = WPIO_Capabilities::detect();
		$opts = WPIO_Settings::get();
		?>
		<div class="wrap wpio-wrap">
			<h1><?php esc_html_e( 'WP Image Optimizer', 'wp-img-optimizer' ); ?></h1>

			<h2><?php esc_html_e( 'Server capabilities', 'wp-img-optimizer' ); ?></h2>
			<ul class="wpio-caps">
				<?php foreach ( WPIO_Capabilities::summary_lines() as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! $caps['webp'] && ! $caps['avif'] ) : ?>
				<div class="notice notice-error inline"><p>
					<?php esc_html_e( 'Neither WebP nor AVIF output is available on this server. Install the Imagick extension (with WebP/AVIF delegates) or a GD build with WebP/AVIF support.', 'wp-img-optimizer' ); ?>
				</p></div>
			<?php elseif ( 'avif' === $opts['format'] && ! $caps['avif'] ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'AVIF is selected but not supported on this server; conversions will be skipped until you choose WebP or enable AVIF.', 'wp-img-optimizer' ); ?>
				</p></div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields( 'wpio_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Output format', 'wp-img-optimizer' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[format]">
								<option value="webp" <?php selected( $opts['format'], 'webp' ); ?>>WebP</option>
								<option value="avif" <?php selected( $opts['format'], 'avif' ); ?>>AVIF</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lossless', 'wp-img-optimizer' ); ?></th>
						<td><label>
							<input type="checkbox" name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[lossless]" value="1" <?php checked( ! empty( $opts['lossless'] ) ); ?>>
							<?php esc_html_e( 'Use lossless encoding (ignores quality)', 'wp-img-optimizer' ); ?>
						</label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Quality', 'wp-img-optimizer' ); ?></th>
						<td><input type="number" min="0" max="100" name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[quality]" value="<?php echo esc_attr( (string) $opts['quality'] ); ?>"> <span class="description">0–100 (lossy mode)</span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Max width (px)', 'wp-img-optimizer' ); ?></th>
						<td><input type="number" min="0" name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[max_width]" value="<?php echo esc_attr( (string) $opts['max_width'] ); ?>"> <span class="description">0 = no limit</span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Max height (px)', 'wp-img-optimizer' ); ?></th>
						<td><input type="number" min="0" name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[max_height]" value="<?php echo esc_attr( (string) $opts['max_height'] ); ?>"> <span class="description">0 = no limit</span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Strip metadata', 'wp-img-optimizer' ); ?></th>
						<td><label>
							<input type="checkbox" name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[strip_metadata]" value="1" <?php checked( ! empty( $opts['strip_metadata'] ) ); ?>>
							<?php esc_html_e( 'Remove EXIF/ICC/XMP metadata from generated images', 'wp-img-optimizer' ); ?>
						</label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Convert on upload', 'wp-img-optimizer' ); ?></th>
						<td><label>
							<input type="checkbox" name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[convert_on_upload]" value="1" <?php checked( ! empty( $opts['convert_on_upload'] ) ); ?>>
							<?php esc_html_e( 'Automatically convert newly uploaded images', 'wp-img-optimizer' ); ?>
						</label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Convert sub-sizes', 'wp-img-optimizer' ); ?></th>
						<td><label>
							<input type="checkbox" name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[convert_sizes]" value="1" <?php checked( ! empty( $opts['convert_sizes'] ) ); ?>>
							<?php esc_html_e( 'Also convert the registered WordPress image sizes (thumbnails)', 'wp-img-optimizer' ); ?>
						</label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Overwrite', 'wp-img-optimizer' ); ?></th>
						<td><label>
							<input type="checkbox" name="<?php echo esc_attr( WPIO_OPTION_KEY ); ?>[overwrite]" value="1" <?php checked( ! empty( $opts['overwrite'] ) ); ?>>
							<?php esc_html_e( 'Re-generate derivatives even if they already exist', 'wp-img-optimizer' ); ?>
						</label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the bulk-convert page shell (JS drives the batches).
	 *
	 * @return void
	 */
	public function render_bulk_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$total = count( WPIO_Media::all_image_ids() );
		?>
		<div class="wrap wpio-wrap">
			<h1><?php esc_html_e( 'Bulk Convert Media', 'wp-img-optimizer' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %d: number of convertible images. */
					esc_html__( '%d convertible image(s) found in the media library.', 'wp-img-optimizer' ),
					(int) $total
				);
				?>
			</p>
			<p>
				<button class="button button-primary" id="wpio-start" data-total="<?php echo esc_attr( (string) $total ); ?>">
					<?php esc_html_e( 'Start conversion', 'wp-img-optimizer' ); ?>
				</button>
			</p>
			<div class="wpio-progress-wrap" style="display:none;">
				<div class="wpio-progress"><div class="wpio-progress-bar" id="wpio-bar"></div></div>
				<p id="wpio-status"></p>
			</div>
			<div class="wpio-report" id="wpio-report" style="display:none;">
				<h2><?php esc_html_e( 'Size-reduction report', 'wp-img-optimizer' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr><th><?php esc_html_e( 'Files converted', 'wp-img-optimizer' ); ?></th><td id="wpio-r-files">0</td></tr>
						<tr><th><?php esc_html_e( 'Original size', 'wp-img-optimizer' ); ?></th><td id="wpio-r-orig">0</td></tr>
						<tr><th><?php esc_html_e( 'New size', 'wp-img-optimizer' ); ?></th><td id="wpio-r-new">0</td></tr>
						<tr><th><?php esc_html_e( 'Reduction', 'wp-img-optimizer' ); ?></th><td id="wpio-r-pct">0%</td></tr>
					</tbody>
				</table>
			</div>
			<div class="wpio-log" id="wpio-log"></div>
		</div>
		<?php
	}

	/**
	 * AJAX: initialise a bulk run. Resets totals and returns the ID list.
	 *
	 * @return void
	 */
	public function ajax_bulk_init(): void {
		$this->verify_ajax();
		WPIO_Report::reset_totals();
		$ids = WPIO_Media::all_image_ids();
		wp_send_json_success(
			array(
				'ids'   => $ids,
				'total' => count( $ids ),
			)
		);
	}

	/**
	 * AJAX: convert one batch of attachment IDs and return progress + totals.
	 *
	 * @return void
	 */
	public function ajax_bulk_convert(): void {
		$this->verify_ajax();

		$ids_raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		$ids     = array_map( 'intval', (array) $ids_raw );

		$opts    = WPIO_Settings::get();
		$log     = array();
		$batch   = array(
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);

		foreach ( $ids as $id ) {
			$summary            = WPIO_Media::convert_attachment( $id, $opts );
			$batch['converted'] += $summary['converted'];
			$batch['skipped']   += $summary['skipped'];
			$batch['failed']    += $summary['failed'];

			$title = get_the_title( $id );
			$log[] = sprintf(
				'#%d %s — %d converted, %d skipped, %d failed',
				$id,
				'' !== $title ? $title : '(untitled)',
				$summary['converted'],
				$summary['skipped'],
				$summary['failed']
			);
		}

		$totals = WPIO_Report::get_totals();
		wp_send_json_success(
			array(
				'batch'  => $batch,
				'log'    => $log,
				'totals' => array(
					'files'     => $totals['files'],
					'orig'      => WPIO_Report::human_size( $totals['original_bytes'] ),
					'new'       => WPIO_Report::human_size( $totals['new_bytes'] ),
					'pct'       => round( WPIO_Report::reduction_pct( $totals['original_bytes'], $totals['new_bytes'] ), 1 ),
					'origBytes' => $totals['original_bytes'],
					'newBytes'  => $totals['new_bytes'],
				),
			)
		);
	}

	/**
	 * Verify the AJAX nonce and capability; dies with a JSON error otherwise.
	 *
	 * @return void
	 */
	private function verify_ajax(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'insufficient permissions' ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_send_json_error( array( 'message' => 'invalid nonce' ), 403 );
		}
	}
}
