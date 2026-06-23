<?php
/**
 * Import/Export Admin Page - Thin shell for menu registration and asset loading.
 *
 * This replaces the old Import_Export class which was 2,499 lines and handled
 * everything from menu registration to CSV parsing. This class does three things:
 * 1. Registers the admin menu page
 * 2. Enqueues the React UI + localizes config data
 * 3. Registers the export admin_post handler
 *
 * All business logic lives in the extracted classes:
 * - CSV_Exporter: config-driven export for all entity types
 * - CSV_Importer: config-driven import for all entity types
 * - CSV_Validator: config-driven validation from entities.json
 * - Legacy_Memorial_Parser: specialized legacy CSV parser
 * - Import_Ajax_Handler: AJAX endpoint registration + delegation
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Admin\Import_Export\{ CSV_Exporter, Import_Ajax_Handler };
use Starter_Shelter\Core\Config;

/**
 * Import/Export admin page.
 *
 * @since 2.0.0
 */
class Import_Export_Page {

	/**
	 * Page slug.
	 */
	private const PAGE_SLUG = 'starter-shelter-import-export';

	/**
	 * Nonce action for exports.
	 */
	private const EXPORT_NONCE = 'sd_import_export';

	/**
	 * Initialize the page and all its handlers.
	 *
	 * @since 2.0.0
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );

		// Single generic export handler (replaces 4 separate admin_post actions).
		add_action( 'admin_post_sd_export', [ self::class, 'handle_export' ] );

		// Full-backup ZIP (all entities) — for pre-uninstall backups.
		add_action( 'admin_post_sd_export_all', [ self::class, 'handle_export_all' ] );

		// Register all AJAX handlers.
		Import_Ajax_Handler::init();
	}

	/**
	 * Add the import/export submenu page.
	 *
	 * @since 2.0.0
	 */
	public static function add_menu_page(): void {
		add_submenu_page(
			Menu::MENU_SLUG,
			__( 'Import / Export', 'starter-shelter' ),
			__( 'Import / Export', 'starter-shelter' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Enqueue assets for the React UI.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		// Only load on our page. Check multiple hook formats for compatibility.
		$expected_hook = Menu::MENU_SLUG . '_page_' . self::PAGE_SLUG;

		if ( $hook !== $expected_hook && strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		// WordPress component dependencies.
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-i18n' );
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_style( 'wp-components' );

		// Our React app.
		wp_enqueue_script(
			'sd-import-export',
			STARTER_SHELTER_URL . 'assets/js/admin-import-export.js',
			[ 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ],
			STARTER_SHELTER_VERSION,
			true
		);

		// Get record counts and template URLs.
		$counts = Import_Ajax_Handler::get_record_counts();

		// Localize config data for the React UI.
		wp_localize_script( 'sd-import-export', 'sdImportExport', [
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'adminPostUrl'   => admin_url( 'admin-post.php' ),
			'exportNonce'    => wp_create_nonce( self::EXPORT_NONCE ),
			'previewNonce'   => wp_create_nonce( 'sd_preview_import' ),
			'importNonce'    => wp_create_nonce( 'sd_process_import' ),
			'errorCsvNonce'  => wp_create_nonce( 'sd_download_error_csv' ),
			'counts'         => $counts,
			'templateUrls'   => self::get_template_urls(),
		] );

		// Minimal styles for the React UI layout.
		wp_add_inline_style( 'wp-components', self::get_styles() );
	}

	/**
	 * Handle CSV export.
	 *
	 * Generic handler for all entity types. The type comes from POST data.
	 * Replaces the four separate admin_post_sd_export_* actions.
	 *
	 * @since 2.0.0
	 */
	public static function handle_export(): void {
		check_admin_referer( self::EXPORT_NONCE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'starter-shelter' ) );
		}

		$type = sanitize_key( $_POST['export_type'] ?? '' );

		$options = [
			'date_range' => sanitize_key( $_POST['date_range'] ?? 'all' ),
			'status'     => sanitize_key( $_POST['status'] ?? 'all' ),
			'date_from'  => sanitize_text_field( $_POST['date_from'] ?? '' ),
			'date_to'    => sanitize_text_field( $_POST['date_to'] ?? '' ),
		];

		CSV_Exporter::export( $type, $options );
	}

	/**
	 * Stream a full-backup ZIP of every entity type.
	 *
	 * @since 1.2.0
	 */
	public static function handle_export_all(): void {
		check_admin_referer( self::EXPORT_NONCE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'starter-shelter' ) );
		}

		$archive = CSV_Exporter::build_archive();
		if ( is_wp_error( $archive ) ) {
			wp_die( esc_html( $archive->get_error_message() ) );
		}

		$filename = 'shelter-data-backup-' . wp_date( 'Y-m-d' ) . '.zip';

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $archive ) );
		readfile( $archive ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $archive );
		exit;
	}

	/**
	 * Render the admin page.
	 *
	 * A server-rendered full-backup card above the React mount point (all
	 * other UI is client-side).
	 *
	 * @since 2.0.0
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<?php self::render_backup_card(); ?>
			<div id="sd-import-export-root">
				<p><?php esc_html_e( 'Loading Import/Export interface...', 'starter-shelter' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Server-rendered "full backup" card — a plain form download (no React),
	 * with an explicit note on what does and doesn't round-trip.
	 *
	 * @since 1.2.0
	 */
	private static function render_backup_card(): void {
		?>
		<div class="sd-backup-card" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:4px;padding:16px 20px;margin:0 0 20px;max-width:1200px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Full data backup', 'starter-shelter' ); ?></h2>
			<p><?php esc_html_e( 'Download every donor, donation, membership, and memorial as a single CSV ZIP. Do this before uninstalling with "Delete all data on uninstall" enabled — deletion is permanent.', 'starter-shelter' ); ?></p>
			<p class="description"><?php esc_html_e( 'Included: donors, donations, memberships, memorials — relinked by email, with their campaign association and logo/photo references restored on same-site re-import. Not restored automatically: campaign goals/end-dates, candle counts, the activity log, and settings.', 'starter-shelter' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sd_export_all" />
				<?php wp_nonce_field( self::EXPORT_NONCE ); ?>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Download full backup (CSV ZIP)', 'starter-shelter' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Build template download URLs for each entity type.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, string> Keyed by entity type.
	 */
	private static function get_template_urls(): array {
		$nonce = wp_create_nonce( 'sd_download_template' );

		$entity_types = Config::get_path( 'import-export', 'entity_types', [] );
		$urls = [];

		foreach ( array_keys( $entity_types ) as $type ) {
			$urls[ $type ] = admin_url( 'admin-ajax.php' ) . '?' . http_build_query( [
				'action'   => 'sd_download_template',
				'_wpnonce' => $nonce,
				'type'     => $type,
			] );
		}

		return $urls;
	}

	/**
	 * Minimal inline styles for the React UI layout.
	 *
	 * @since 2.0.0
	 *
	 * @return string CSS.
	 */
	private static function get_styles(): string {
		return '
			.sd-import-export-app {
				max-width: 1200px;
			}
			.sd-import-export-tabs .components-tab-panel__tabs {
				margin-bottom: 0;
				border-bottom: 1px solid #c3c4c7;
			}
			.sd-import-export-tabs .components-tab-panel__tabs button {
				padding: 12px 16px;
				font-size: 14px;
			}
			.sd-export-card .components-card__header,
			.sd-import-card .components-card__header {
				padding: 16px 20px;
			}
			.sd-export-card .components-card__body,
			.sd-import-card .components-card__body {
				padding: 16px 20px;
			}
			.sd-export-card .components-card__footer,
			.sd-import-card .components-card__footer {
				padding: 12px 20px;
				border-top: 1px solid #e0e0e0;
			}
			.sd-import-card .sd-import-results {
				margin-top: 16px;
				padding: 12px;
				background: #f6f7f7;
				border-radius: 4px;
			}
		';
	}
}
