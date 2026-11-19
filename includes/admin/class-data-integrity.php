<?php
/**
 * Data Integrity — Admin tool for backfilling missing meta and purging records.
 *
 * Adds a "Data Tools" submenu under ShelterKit Donations with two tabs:
 *
 * 1. BACKFILL — Scans existing records against entities.json, reports
 *    missing/empty meta fields, and fills them in batches. Also handles:
 *    - Memorial donor_display_name denormalization
 *    - Memorial year taxonomy terms
 *    - Donation allocation defaults
 *    - Donor lifetime_giving recalculation
 *
 * 2. PURGE — Bulk-delete all records of a chosen CPT. Double confirmation
 *    (type the post type slug to confirm). Useful for re-syncing from
 *    WooCommerce orders or re-importing from CSV.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\{ Config, Entity_Hydrator };
use Starter_Shelter\Helpers;

/**
 * Admin screen that scans stored entities for missing or inconsistent meta
 * and offers batched backfills.
 *
 * @since 2.0.0
 */
class Data_Integrity {

	/**
	 * Page slug.
	 */
	private const SLUG = 'shelterkit-donations-data-tools';

	/**
	 * Nonce action.
	 */
	private const NONCE = 'sd_data_integrity';

	/**
	 * Batch size for AJAX operations.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Entity types we manage.
	 */
	private const ENTITY_TYPES = [
		'skdon_donation'   => 'Donations',
		'skdon_membership' => 'Memberships',
		'skdon_memorial'   => 'Memorials',
		'skdon_donor'      => 'Donors',
	];

	/**
	 * Initialize.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu_page' ], 30 );
		add_action( 'wp_ajax_skdon_integrity_scan', [ self::class, 'ajax_scan' ] );
		add_action( 'wp_ajax_skdon_integrity_backfill', [ self::class, 'ajax_backfill' ] );
		add_action( 'wp_ajax_skdon_integrity_purge', [ self::class, 'ajax_purge' ] );
		add_action( 'wp_ajax_skdon_integrity_recalc_donors', [ self::class, 'ajax_recalc_donors' ] );
		add_action( 'wp_ajax_skdon_legacy_sync_reset', [ self::class, 'ajax_reset_sync_flags' ] );
		add_action( 'wp_ajax_skdon_integrity_repair_merges', [ self::class, 'ajax_repair_merges' ] );
		add_action( 'wp_ajax_skdon_integrity_assign_refund', [ self::class, 'ajax_assign_refund' ] );
		add_action( 'wp_ajax_skdon_integrity_apply_refunds', [ self::class, 'ajax_apply_refunds' ] );
	}

	/**
	 * Order Records tab → Repair: give each record Legacy Order Sync merged
	 * back to its own order line (#40).
	 */
	public static function ajax_repair_merges(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'shelterkit-donations' ), 403 );
		}

		$run      = Activity_Log::begin_run( 'merge_repair', [], 'tool' );
		$result   = \Starter_Shelter\Admin\Legacy_Sync\Merge_Repair::repair_all();
		$repaired = count( $result['repaired'] );
		Activity_Log::finish_run( $run, [ 'repaired' => $repaired, 'failed' => count( $result['failed'] ) ] );

		wp_send_json_success( [
			'repaired' => $repaired,
			'failed'   => $result['failed'],
			'message'  => sprintf(
				/* translators: %d: number of order lines given their own record. */
				_n( 'Repaired %d merged record.', 'Repaired %d merged records.', $repaired, 'shelterkit-donations' ),
				$repaired
			),
		] );
	}

	/**
	 * Order Records tab → say which gift a refund returned, for a refund that
	 * named no lines on an order with several (#34).
	 */
	public static function ajax_assign_refund(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'shelterkit-donations' ), 403 );
		}

		// intval, not absint: a negative ID must not become a real one.
		$order_id  = isset( $_POST['order_id'] ) ? intval( wp_unslash( $_POST['order_id'] ) ) : 0;
		$refund_id = isset( $_POST['refund_id'] ) ? intval( wp_unslash( $_POST['refund_id'] ) ) : 0;
		$record    = isset( $_POST['record'] ) ? intval( wp_unslash( $_POST['record'] ) ) : -1;
		$order     = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order || $refund_id <= 0 || $record < 0 ) {
			wp_send_json_error( __( 'That order or refund was not found.', 'shelterkit-donations' ), 400 );
		}

		$run    = Activity_Log::begin_run( 'assign_refund', [ 'order_id' => $order_id, 'refund_id' => $refund_id, 'record' => $record ], 'tool' );
		$result = \Starter_Shelter\WooCommerce\Refund_Handler::assign_refund( $order, $refund_id, $record );
		Activity_Log::finish_run( $run, [], is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : [] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 400 );
		}

		wp_send_json_success( [
			'message' => $record
				/* translators: 1: record ID; 2: order number. */
				? sprintf( __( 'Refund applied to #%1$d on order #%2$s.', 'shelterkit-donations' ), $record, $order->get_order_number() )
				/* translators: %s: order number. */
				: sprintf( __( 'Refund on order #%s marked as not returning a gift.', 'shelterkit-donations' ), $order->get_order_number() ),
		] );
	}

	/**
	 * Order Records tab → apply refunds and cancellations made before
	 * refunds reached the records (#34).
	 */
	public static function ajax_apply_refunds(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'shelterkit-donations' ), 403 );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			wp_send_json_error( __( 'WooCommerce is not active.', 'shelterkit-donations' ), 400 );
		}

		$run    = Activity_Log::begin_run( 'apply_refunds', [], 'tool' );
		$result = \Starter_Shelter\WooCommerce\Refund_Handler::reconcile_all();
		Activity_Log::finish_run( $run, $result );

		$message = sprintf(
			/* translators: %d: number of orders. */
			_n( 'Checked %d refunded or cancelled order with gifts.', 'Checked %d refunded or cancelled orders with gifts.', $result['orders'], 'shelterkit-donations' ),
			$result['orders']
		) . ' ' . sprintf(
			/* translators: %d: number of donations, memberships and memorials. */
			_n( '%d gift changed.', '%d gifts changed.', $result['changed'], 'shelterkit-donations' ),
			$result['changed']
		);
		if ( $result['review'] ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of refunds. */
				_n( '%d refund needs you to choose its gift below.', '%d refunds need you to choose their gift below.', $result['review'], 'shelterkit-donations' ),
				$result['review']
			);
		}

		wp_send_json_success( [ 'message' => $message ] );
	}

	/**
	 * Purge tab → Reset Sync Flags. The button had no handler, so it always
	 * failed (#32). Legacy Order Sync's own reset takes a different nonce and
	 * different fields, so this screen gets its own handler over the shared
	 * reset.
	 */
	public static function ajax_reset_sync_flags(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'shelterkit-donations' ), 403 );
		}

		$run   = Activity_Log::begin_run( 'reset_sync_flags', [], 'tool' );
		$count = \Starter_Shelter\Admin\Legacy_Sync\Order_Scanner::reset_sync_flags();
		Activity_Log::finish_run( $run, [ 'orders' => $count ] );

		wp_send_json_success( [
			'count'   => $count,
			'message' => sprintf(
				/* translators: %d: number of WooCommerce orders. */
				_n(
					'Sync flags reset on %d order. Run Legacy Order Sync to process it again.',
					'Sync flags reset on %d orders. Run Legacy Order Sync to process them again.',
					$count,
					'shelterkit-donations'
				),
				$count
			),
		] );
	}

	/**
	 * Register the admin submenu page.
	 */
	public static function add_menu_page(): void {
		$hook = add_submenu_page(
			Menu::MENU_SLUG,
			__( 'Data Tools', 'shelterkit-donations' ),
			__( 'Data Tools', 'shelterkit-donations' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'render_page' ]
		);

		add_action( "admin_print_scripts-{$hook}", [ self::class, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue JS and localize data.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_script(
			'sd-data-integrity',
			plugins_url( 'assets/js/admin-data-integrity.js', STARTER_SHELTER_FILE ),
			[ 'jquery' ],
			STARTER_SHELTER_VERSION,
			true
		);

		wp_localize_script( 'sd-data-integrity', 'sdDataIntegrity', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'batchSize'  => self::BATCH_SIZE,
			'entityTypes' => self::ENTITY_TYPES,
			'i18n'       => [
				'scanning'        => __( 'Scanning…', 'shelterkit-donations' ),
				'backfilling'     => __( 'Backfilling…', 'shelterkit-donations' ),
				'purging'         => __( 'Deleting…', 'shelterkit-donations' ),
				'recalculating'   => __( 'Recalculating…', 'shelterkit-donations' ),
				'complete'        => __( 'Complete!', 'shelterkit-donations' ),
				'confirmPurge'    => __( 'Type the post type slug to confirm:', 'shelterkit-donations' ),
				/* translators: %s: record type label. */
				'purgeWarning'    => __( 'This will permanently delete ALL %s records. This cannot be undone.', 'shelterkit-donations' ),
				'noIssues'        => __( 'All records look good — no missing fields found.', 'shelterkit-donations' ),
			],
		] );
	}

	// ─────────────────────────────────────────────────────────────
	// Page Render
	// ─────────────────────────────────────────────────────────────

	/**
	 * Render the admin page.
	 */
	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Data Tools', 'shelterkit-donations' ); ?></h1>

			<nav class="nav-tab-wrapper" id="sd-data-tools-tabs">
				<a href="#backfill" class="nav-tab nav-tab-active"><?php esc_html_e( 'Backfill &amp; Repair', 'shelterkit-donations' ); ?></a>
				<a href="#purge" class="nav-tab"><?php esc_html_e( 'Purge Records', 'shelterkit-donations' ); ?></a>
				<a href="#recalc" class="nav-tab"><?php esc_html_e( 'Recalculate', 'shelterkit-donations' ); ?></a>
				<a href="#orders" class="nav-tab"><?php esc_html_e( 'Order Records', 'shelterkit-donations' ); ?></a>
			</nav>

			<?php self::render_backfill_tab(); ?>
			<?php self::render_purge_tab(); ?>
			<?php self::render_recalc_tab(); ?>
			<?php self::render_orders_tab(); ?>

			<div id="sd-progress-area" style="display:none; margin-top: 20px;">
				<div class="sd-progress-bar-wrap" style="background:#ddd; border-radius:4px; height:24px; max-width:600px;">
					<div id="sd-progress-bar" style="background:#2271b1; height:100%; border-radius:4px; width:0%; transition:width 0.3s;"></div>
				</div>
				<p id="sd-progress-text" style="margin-top:8px;"></p>
			</div>

			<div id="sd-results-area" style="margin-top: 20px;"></div>
		</div>
		<style>
			.sd-tool-section { display: none; margin-top: 20px; }
			.sd-tool-section.active { display: block; }
			.sd-entity-card { background: #fff; border: 1px solid #c3c4c7; padding: 16px 20px; margin-bottom: 12px; }
			.sd-entity-card h3 { margin: 0 0 8px; }
			.sd-entity-card .sd-field-list { margin: 8px 0; color: #646970; }
			.sd-purge-danger { border-left: 4px solid #d63638; }
			.sd-scan-ok { border-left: 4px solid #00a32a; }
			.sd-scan-issues { border-left: 4px solid #dba617; }
			.sd-count-badge { display: inline-block; background: #dba617; color: #fff; border-radius: 10px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
			.sd-count-badge.ok { background: #00a32a; }
		</style>
		<?php
	}

	/**
	 * Render the Backfill tab.
	 */
	private static function render_backfill_tab(): void {
		?>
		<div id="tab-backfill" class="sd-tool-section active">
			<h2><?php esc_html_e( 'Backfill Missing Fields', 'shelterkit-donations' ); ?></h2>
			<p class="description">
				<?php
                esc_html_e(
					'Scans all records and reports missing or empty meta fields based on the entity schema. You can then backfill defaults, denormalized fields, and taxonomy terms in batches.',
					'shelterkit-donations'
				);
                ?>
			</p>

			<p>
				<button type="button" id="sd-scan-btn" class="button button-primary">
					<?php esc_html_e( 'Scan All Records', 'shelterkit-donations' ); ?>
				</button>
			</p>

			<div id="sd-scan-results"></div>

			<p id="sd-backfill-actions" style="display:none;">
				<button type="button" id="sd-backfill-btn" class="button button-primary">
					<?php esc_html_e( 'Backfill All Issues', 'shelterkit-donations' ); ?>
				</button>
				<span class="description" style="margin-left: 10px;">
					<?php esc_html_e( 'Fills in missing defaults, display names, and taxonomy terms. Does not overwrite existing values.', 'shelterkit-donations' ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Purge tab.
	 */
	private static function render_purge_tab(): void {
		?>
		<div id="tab-purge" class="sd-tool-section">
			<h2><?php esc_html_e( 'Purge Records', 'shelterkit-donations' ); ?></h2>

			<div class="notice notice-warning inline" style="margin: 10px 0 20px;">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'shelterkit-donations' ); ?></strong>
					<?php
                    esc_html_e(
						'This permanently deletes posts and their meta. Use this to start fresh before re-running Legacy Order Sync or CSV imports. WooCommerce orders and donor records are not affected unless you explicitly choose them.',
						'shelterkit-donations'
					);
                    ?>
				</p>
			</div>

			<?php foreach ( self::ENTITY_TYPES as $post_type => $label ) : ?>
				<?php
				$count = wp_count_posts( $post_type );
				$total = ( $count->publish ?? 0 ) + ( $count->draft ?? 0 ) + ( $count->private ?? 0 ) + ( $count->trash ?? 0 );
				?>
				<div class="sd-entity-card sd-purge-danger">
					<h3>
						<?php echo esc_html( $label ); ?>
						<span class="sd-count-badge"><?php echo esc_html( number_format( $total ) ); ?></span>
					</h3>
					<p class="description">
						<?php
                        printf(
							/* translators: %s: post type slug */
							esc_html__( 'Post type: %s', 'shelterkit-donations' ),
							'<code>' . esc_html( $post_type ) . '</code>'
						);
                        ?>
					</p>
					<p style="margin-top: 10px;">
						<button type="button"
							class="button sd-purge-btn"
							data-post-type="<?php echo esc_attr( $post_type ); ?>"
							data-label="<?php echo esc_attr( $label ); ?>"
							data-count="<?php echo esc_attr( (string) $total ); ?>"
							<?php echo $total === 0 ? 'disabled' : ''; ?>
						>
							<?php
                            printf(
								/* translators: %s: record type label */
								esc_html__( 'Delete All %s', 'shelterkit-donations' ),
								esc_html( $label )
							);
                            ?>
						</button>
					</p>
				</div>
			<?php endforeach; ?>

			<?php if ( function_exists( 'wc_get_orders' ) ) : ?>
			<div class="sd-entity-card" style="border-left: 4px solid #2271b1; margin-top: 20px;">
				<h3><?php esc_html_e( 'Reset Legacy Sync Flags', 'shelterkit-donations' ); ?></h3>
				<p class="description">
					<?php
                    esc_html_e(
						'Clears the "_skdon_legacy_synced" flag on all WooCommerce orders so they can be re-processed by the Legacy Order Sync tool. Does NOT delete any records.',
						'shelterkit-donations'
					);
                    ?>
				</p>
				<p style="margin-top: 10px;">
					<button type="button" id="sd-reset-sync-flags" class="button">
						<?php esc_html_e( 'Reset Sync Flags', 'shelterkit-donations' ); ?>
					</button>
				</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Recalculate tab.
	 */
	private static function render_recalc_tab(): void {
		?>
		<div id="tab-recalc" class="sd-tool-section">
			<h2><?php esc_html_e( 'Recalculate Donor Stats', 'shelterkit-donations' ); ?></h2>
			<p class="description">
				<?php
                esc_html_e(
					'Recalculates lifetime_giving for every donor by summing their donations, memorials, and memberships. Use this after bulk imports or if stats look incorrect.',
					'shelterkit-donations'
				);
                ?>
			</p>
			<p>
				<button type="button" id="sd-recalc-btn" class="button button-primary">
					<?php esc_html_e( 'Recalculate All Donors', 'shelterkit-donations' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Order Records tab: orders with lines that weren't recorded
	 * (#35), and records Legacy Order Sync merged (#40).
	 */
	private static function render_orders_tab(): void {
		$with_errors = array_filter( array_map( 'wc_get_order', Helpers\order_ids_with_meta( \Starter_Shelter\WooCommerce\Order_Handler::ERRORS_META_KEY, 50 ) ) );
		$merged = \Starter_Shelter\Admin\Legacy_Sync\Merge_Repair::find();
		?>
		<div id="tab-orders" class="sd-tool-section">
			<h2><?php esc_html_e( 'Orders with lines that weren\'t recorded', 'shelterkit-donations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A paid line from one of the plugin\'s products that produced no donation, membership or memorial. Fix the cause shown, then use Reprocess on the order: lines already recorded are skipped.', 'shelterkit-donations' ); ?></p>
			<?php if ( ! $with_errors ) : ?>
				<p><?php esc_html_e( 'None.', 'shelterkit-donations' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th><?php esc_html_e( 'Order', 'shelterkit-donations' ); ?></th><th><?php esc_html_e( 'Lines not recorded', 'shelterkit-donations' ); ?></th><th><?php esc_html_e( 'Reason', 'shelterkit-donations' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $with_errors as $order ) : ?>
						<?php
						$reason = '';
						foreach ( (array) $order->get_meta( '_skdon_processing_results' ) as $result ) {
							if ( is_wp_error( $result ) ) {
								$reason = $result->get_error_message();
								break;
							}
						}
						?>
						<tr>
							<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
							<td><?php echo esc_html( (string) (int) $order->get_meta( \Starter_Shelter\WooCommerce\Order_Handler::ERRORS_META_KEY ) ); ?></td>
							<td><?php echo esc_html( $reason ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Records merged by Legacy Order Sync', 'shelterkit-donations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Before this release, an order with two lines of the same kind (an individual and a business membership, say) had its second line overwrite the record its first line created. Repair restores each record to the line that created it and gives the other line its own record. No donor is emailed.', 'shelterkit-donations' ); ?></p>
			<?php if ( ! $merged ) : ?>
				<p><?php esc_html_e( 'None found.', 'shelterkit-donations' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th><?php esc_html_e( 'Record', 'shelterkit-donations' ); ?></th><th><?php esc_html_e( 'Belongs to', 'shelterkit-donations' ); ?></th><th><?php esc_html_e( 'Overwritten by', 'shelterkit-donations' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $merged as $merge ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $merge['record'] ) ); ?>">#<?php echo esc_html( (string) $merge['record'] ); ?></a></td>
							<td><?php echo esc_html( self::order_line_label( $merge['order_id'], $merge['item_id'] ) ); ?></td>
							<td><?php echo esc_html( self::order_line_label( $merge['by_order'], $merge['by_item'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" id="sd-repair-merges" class="button button-primary"><?php esc_html_e( 'Repair', 'shelterkit-donations' ); ?></button></p>
			<?php endif; ?>

			<?php self::render_refund_review(); ?>
		</div>
		<?php
	}

	/**
	 * Order Records tab, refunds (#34): refunds to match to a gift, and
	 * applying refunds made before this release.
	 */
	private static function render_refund_review(): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$handler = \Starter_Shelter\WooCommerce\Refund_Handler::class;
		$orders  = array_filter( array_map( 'wc_get_order', Helpers\order_ids_with_meta( $handler::REVIEW_META, 50 ) ) );
		?>
		<h2><?php esc_html_e( 'Refunds to match to a gift', 'shelterkit-donations' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A refund that names no order lines, on an order with more than one, could have returned any of them. Choose the gift it returned, or "Not a gift" if it returned shipping or another product. Until then, every gift on the order counts in full.', 'shelterkit-donations' ); ?></p>
		<?php if ( ! $orders ) : ?>
			<p><?php esc_html_e( 'None.', 'shelterkit-donations' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th><?php esc_html_e( 'Order', 'shelterkit-donations' ); ?></th><th><?php esc_html_e( 'Refund', 'shelterkit-donations' ); ?></th><th><?php esc_html_e( 'Gift it returned', 'shelterkit-donations' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<?php
					$records = $handler::records_for_order( $order->get_id() );
					foreach ( array_map( 'intval', (array) $order->get_meta( $handler::REVIEW_META ) ) as $refund_id ) :
						$refund = wc_get_order( $refund_id );
						if ( ! $refund instanceof \WC_Order_Refund ) {
							continue;
						}
						?>
						<tr>
							<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
							<td>
								<?php echo esc_html( Helpers\format_currency( abs( (float) $refund->get_amount() ), $order->get_currency() ) ); ?>
								<?php if ( $refund->get_reason() ) : ?>
									<br><span class="description"><?php echo esc_html( $refund->get_reason() ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<select class="sd-refund-record" aria-label="<?php esc_attr_e( 'Gift it returned', 'shelterkit-donations' ); ?>">
									<?php foreach ( $records as $record ) : ?>
										<option value="<?php echo esc_attr( (string) $record ); ?>"><?php echo esc_html( self::record_label( $record, $order->get_currency() ) ); ?></option>
									<?php endforeach; ?>
									<option value="0"><?php esc_html_e( 'Not a gift (shipping, another product)', 'shelterkit-donations' ); ?></option>
								</select>
							</td>
							<td><button type="button" class="button sd-assign-refund" data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-refund="<?php echo esc_attr( (string) $refund_id ); ?>"><?php esc_html_e( 'Apply', 'shelterkit-donations' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Refunds made before this update', 'shelterkit-donations' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Until this release a refunded or cancelled order left its gifts counting in full. This applies the same rules to every order with a refund or a cancelled or refunded status. Running it again changes nothing.', 'shelterkit-donations' ); ?></p>
		<p><button type="button" id="sd-apply-refunds" class="button"><?php esc_html_e( 'Apply past refunds', 'shelterkit-donations' ); ?></button></p>
		<?php
	}

	/**
	 * "Donation #812: $50.00" for the refund matcher.
	 *
	 * @param int    $record   Record post ID.
	 * @param string $currency The order's currency.
	 * @return string
	 */
	private static function record_label( int $record, string $currency ): string {
		$type = get_post_type_object( (string) get_post_type( $record ) );
		return sprintf(
			/* translators: 1: record type (Donation, Membership, Memorial); 2: record ID; 3: formatted amount. */
			__( '%1$s #%2$d: %3$s', 'shelterkit-donations' ),
			$type ? $type->labels->singular_name : __( 'Gift', 'shelterkit-donations' ),
			$record,
			Helpers\format_currency( \Starter_Shelter\WooCommerce\Refund_Handler::original_amount( $record ), $currency )
		);
	}

	/**
	 * "Order #877: Shelter Memberships - Single Membership - $10".
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id  Order line ID.
	 * @return string
	 */
	private static function order_line_label( int $order_id, int $item_id ): string {
		$order = wc_get_order( $order_id );
		$item  = $order ? $order->get_item( $item_id ) : null;
		return sprintf(
			/* translators: 1: order number; 2: order line name. */
			__( 'Order #%1$s: %2$s', 'shelterkit-donations' ),
			$order ? $order->get_order_number() : $order_id,
			$item ? $item->get_name() : '?'
		);
	}

	// ─────────────────────────────────────────────────────────────
	// AJAX: Scan
	// ─────────────────────────────────────────────────────────────

	/**
	 * Scan all entity types and report missing fields.
	 *
	 * Returns a summary per entity type:
	 *   { skdon_donation: { total: 500, issues: { allocation: 12, donation_date: 0 } } }
	 */
	public static function ajax_scan(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$entities = Config::get_item( 'entities', 'entities', [] );
		$report   = [];

		foreach ( self::ENTITY_TYPES as $post_type => $label ) {
			$config = $entities[ $post_type ] ?? null;
			if ( ! $config ) {
				continue;
			}

			$prefix = $config['meta_prefix'] ?? '_skdon_';
			$fields = $config['fields'] ?? [];

			// Get all post IDs for this type.
			$post_ids = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );

			$total      = count( $post_ids );
			$issues     = [];
			$issue_ids  = [];

			// Check each field.
			foreach ( $fields as $field_name => $field_config ) {
				$meta_key = $prefix . $field_name;
				$missing  = self::count_missing_meta( $post_ids, $meta_key, $field_config );

				$issues[ $field_name ] = $missing;
				if ( $missing > 0 ) {
					$issue_ids[ $field_name ] = self::get_ids_missing_meta( $post_ids, $meta_key, $field_config, 5 );
				}
			}

			// Special checks per entity type.
			$specials = self::scan_special_fields( $post_type, $post_ids );

			$report[ $post_type ] = [
				'label'       => $label,
				'total'       => $total,
				'issues'      => $issues,
				'specials'    => $specials,
				'sample_ids'  => $issue_ids,
				'has_issues'  => array_sum( $issues ) > 0 || ! empty( $specials ),
			];
		}

		wp_send_json_success( $report );
	}

	/**
	 * Count posts missing a meta field or having it empty.
	 */
	private static function count_missing_meta( array $post_ids, string $meta_key, array $field_config ): int {
		if ( empty( $post_ids ) ) {
			return 0;
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// Posts where meta key doesn't exist or value is empty string.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only interpolated parts are $wpdb table properties and a generated %d placeholder list; every user value is bound through $wpdb->prepare().
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 WHERE p.ID IN ($id_placeholders)
			 AND (pm.meta_value IS NULL OR pm.meta_value = '')",
			array_merge( [ $meta_key ], $post_ids )
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get sample IDs of posts missing a meta field.
	 */
	private static function get_ids_missing_meta( array $post_ids, string $meta_key, array $field_config, int $limit = 5 ): array {
		if ( empty( $post_ids ) ) {
			return [];
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only interpolated parts are $wpdb table properties and a generated %d placeholder list; every user value is bound through $wpdb->prepare().
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 WHERE p.ID IN ($id_placeholders)
			 AND (pm.meta_value IS NULL OR pm.meta_value = '')
			 LIMIT %d",
			array_merge( [ $meta_key ], $post_ids, [ $limit ] )
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Scan entity-specific fields that need special handling.
	 */
	private static function scan_special_fields( string $post_type, array $post_ids ): array {
		if ( empty( $post_ids ) ) {
			return [];
		}

		$specials = [];

		if ( 'skdon_memorial' === $post_type ) {
			// Check for missing skdon_memorial_year taxonomy terms.
			$missing_year = 0;
			foreach ( $post_ids as $id ) {
				$terms = get_the_terms( $id, 'skdon_memorial_year' );
				if ( ! $terms || is_wp_error( $terms ) ) {
					++$missing_year;
				}
			}
			if ( $missing_year > 0 ) {
				$specials['memorial_year_taxonomy'] = $missing_year;
			}

			// Check for email addresses stored as donor display names.
			// This happens when the legacy sync or import creates a memorial
			// and the donor's first/last name fields are empty, causing
			// build_display_name() to fall back to the email address.
			$email_display = self::count_email_display_names( $post_ids );
			if ( $email_display > 0 ) {
				$specials['donor_display_name_email'] = $email_display;
			}

			// Check for order-linked memorials whose date doesn't match the order.
			// This happens when Product_Mapper builds the input (product still exists)
			// but doesn't pass a 'date' field, so the ability defaults to wp_date()
			// (the sync timestamp) instead of the original order date.
			$date_mismatches = self::count_memorial_date_mismatches( $post_ids );
			if ( $date_mismatches > 0 ) {
				$specials['order_date_mismatch'] = $date_mismatches;
			}
		}

		if ( 'skdon_donor' === $post_type ) {
			// Check for donors whose _skdon_display_name is an email address.
			// These need rebuilding from first_name + last_name.
			$donor_email_display = self::count_email_display_names_on_donors( $post_ids );
			if ( $donor_email_display > 0 ) {
				$specials['display_name_is_email'] = $donor_email_display;
			}
		}

		// Check for missing import hashes on non-donor entity types.
		if ( 'skdon_donor' !== $post_type ) {
			$missing_hash = self::count_missing_meta(
				$post_ids,
				\Starter_Shelter\Admin\Import_Export\CSV_Importer::HASH_META_KEY,
				[ 'type' => 'string' ]
			);
			if ( $missing_hash > 0 ) {
				$specials['import_hash'] = $missing_hash;
			}
		}

		return $specials;
	}

	// ─────────────────────────────────────────────────────────────
	// AJAX: Backfill
	// ─────────────────────────────────────────────────────────────

	/**
	 * Backfill missing meta for a batch of posts.
	 *
	 * Expects POST: post_type, offset
	 * Returns: { processed, fixed, done, offset }
	 */
	public static function ajax_backfill(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$post_type = sanitize_key( $_POST['post_type'] ?? '' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- check_ajax_referer() and current_user_can() run at the top of this handler.
		$offset    = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( ! isset( self::ENTITY_TYPES[ $post_type ] ) ) {
			wp_send_json_error( 'Invalid post type.' );
		}

		$entities = Config::get_item( 'entities', 'entities', [] );
		$config   = $entities[ $post_type ] ?? null;

		if ( ! $config ) {
			wp_send_json_error( 'No entity config found.' );
		}

		$prefix = $config['meta_prefix'] ?? '_skdon_';
		$fields = $config['fields'] ?? [];

		// One log entry for the whole Backfill, across its record types and
		// batches; the browser sends the run back with each request (#36).
		$run_id = Activity_Log::join_run( sanitize_text_field( wp_unslash( $_POST['run_id'] ?? '' ) ), 'backfill' );

		// Get batch of posts.
		$posts = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => self::BATCH_SIZE,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );

		$processed = 0;
		$fixed     = 0;

		foreach ( $posts as $post ) {
			++$processed;
			$all_meta = get_post_meta( $post->ID );

			// Fill missing meta fields with defaults.
			foreach ( $fields as $field_name => $field_config ) {
				$meta_key = $prefix . $field_name;
				$current  = $all_meta[ $meta_key ][0] ?? '';

				if ( '' !== $current && null !== $current ) {
					continue; // Already has a value, don't overwrite.
				}

				$default = self::get_backfill_value( $post_type, $field_name, $field_config, $post, $all_meta );
				if ( null !== $default && '' !== $default ) {
					update_post_meta( $post->ID, $meta_key, $default );
					++$fixed;
				}
			}

			// Entity-specific fixes.
			$fixed += self::backfill_special_fields( $post_type, $post, $all_meta );
		}

		// Check if we're done.
		$total = wp_count_posts( $post_type );
		$total_count = ( $total->publish ?? 0 ) + ( $total->draft ?? 0 ) + ( $total->private ?? 0 );
		$new_offset  = $offset + count( $posts );
		$done        = count( $posts ) < self::BATCH_SIZE;

		// The last record type's last batch finishes the run.
		Activity_Log::end_batch( $run_id, [ 'records' => $processed, 'fixed' => $fixed ], $done && ! empty( $_POST['final'] ) );

		wp_send_json_success( [
			'processed' => $processed,
			'fixed'     => $fixed,
			'offset'    => $new_offset,
			'total'     => $total_count,
			'done'      => $done,
			'run_id'    => $run_id,
		] );
	}

	/**
	 * Determine the backfill value for a missing field.
	 *
	 * Returns the value to set, or null to skip.
	 */
	private static function get_backfill_value(
		string $post_type,
		string $field_name,
		array $field_config,
		\WP_Post $post,
		array $all_meta
	) {
		// Use schema default if defined.
		if ( isset( $field_config['default'] ) ) {
			return $field_config['default'];
		}

		// Entity-specific inference for fields without schema defaults.
		return match ( $post_type . '.' . $field_name ) {
			// Donations: default allocation to general-fund.
			'skdon_donation.allocation' => 'general-fund',

			// Memorials: denormalize donor display name.
			'skdon_memorial.donor_display_name' => self::resolve_donor_display_name(
				(int) ( $all_meta['_skdon_donor_id'][0] ?? 0 ),
				filter_var( $all_meta['_skdon_is_anonymous'][0] ?? false, FILTER_VALIDATE_BOOLEAN )
			),

			// Memorials: default type to 'pet' (shelter context).
			'skdon_memorial.memorial_type' => 'pet',

			// Donors: fill display_name from post title.
			'skdon_donor.display_name' => $post->post_title,

			// Everything else: skip (don't invent data).
			default => null,
		};
	}

	/**
	 * Resolve a donor display name from donor_id.
	 */
	private static function resolve_donor_display_name( int $donor_id, bool $is_anonymous ): string {
		if ( $is_anonymous || ! $donor_id ) {
			return '';
		}

		$name = get_post_meta( $donor_id, '_skdon_display_name', true );
		if ( $name ) {
			return $name;
		}

		$donor = get_post( $donor_id );
		return $donor ? $donor->post_title : '';
	}

	/**
	 * Count memorials whose _skdon_donor_display_name looks like an email address.
	 *
	 * Uses a direct DB query with LIKE '%@%.%' for efficiency rather than
	 * iterating all posts in PHP.
	 *
	 * @since 2.1.0
	 *
	 * @param int[] $post_ids Memorial post IDs to check.
	 * @return int Number of memorials with email-like display names.
	 */
	private static function count_email_display_names( array $post_ids ): int {
		if ( empty( $post_ids ) ) {
			return 0;
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only interpolated parts are $wpdb table properties and a generated %d placeholder list; every user value is bound through $wpdb->prepare().
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE post_id IN ($id_placeholders)
			   AND meta_key = '_skdon_donor_display_name'
			   AND meta_value LIKE %s",
			array_merge( $post_ids, [ '%@%.%' ] )
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) );
	}

	/**
	 * Check if a string looks like an email address.
	 *
	 * @since 2.1.0
	 *
	 * @param string $value The string to check.
	 * @return bool True if the value contains @ and looks like an email.
	 */
	private static function looks_like_email( string $value ): bool {
		return ! empty( $value ) && str_contains( $value, '@' ) && is_email( $value );
	}

	/**
	 * Count donors whose _skdon_display_name is an email address.
	 *
	 * @since 2.1.0
	 *
	 * @param int[] $post_ids Donor post IDs to check.
	 * @return int Number of donors with email-like display names.
	 */
	private static function count_email_display_names_on_donors( array $post_ids ): int {
		if ( empty( $post_ids ) ) {
			return 0;
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only interpolated parts are $wpdb table properties and a generated %d placeholder list; every user value is bound through $wpdb->prepare().
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE post_id IN ($id_placeholders)
			   AND meta_key = '_skdon_display_name'
			   AND meta_value LIKE %s",
			array_merge( $post_ids, [ '%@%.%' ] )
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) );
	}

	/**
	 * Count order-linked memorials whose post_date differs from the order date.
	 *
	 * During Legacy Order Sync, memorials processed via Product_Mapper (product
	 * still exists) don't receive a 'date' in the ability input, so the ability
	 * defaults to wp_date() — the sync timestamp instead of the order date.
	 *
	 * Only checks memorials that have a _skdon_wc_order_id (i.e. order-linked),
	 * skipping legacy CSV imports which have no order reference.
	 *
	 * @since 2.1.0
	 *
	 * @param int[] $post_ids Memorial post IDs to check.
	 * @return int Number of memorials with date mismatches.
	 */
	private static function count_memorial_date_mismatches( array $post_ids ): int {
		if ( empty( $post_ids ) || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		global $wpdb;

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// Get memorials that have a non-zero order ID.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$order_linked = $wpdb->get_results( $wpdb->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the only interpolated parts are $wpdb table properties and a generated %d placeholder list; every user value is bound through $wpdb->prepare().
			"SELECT p.ID as memorial_id, p.post_date, pm.meta_value as order_id
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE p.ID IN ($id_placeholders)
			   AND pm.meta_key = '_skdon_wc_order_id'
			   AND pm.meta_value > 0",
			$post_ids
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) );

		$mismatches = 0;

		foreach ( $order_linked as $row ) {
			$order = wc_get_order( (int) $row->order_id );
			if ( ! $order || ! $order->get_date_created() ) {
				continue;
			}

			$order_date    = $order->get_date_created()->format( 'Y-m-d H:i:s' );
			$memorial_date = $row->post_date;

			if ( abs( strtotime( $memorial_date ) - strtotime( $order_date ) ) > 60 ) {
				++$mismatches;
			}
		}

		return $mismatches;
	}

	/**
	 * Backfill entity-specific fields that aren't simple meta defaults.
	 *
	 * Returns count of fixes applied.
	 */
	private static function backfill_special_fields( string $post_type, \WP_Post $post, array $all_meta ): int {
		$fixed = 0;

		if ( 'skdon_memorial' === $post_type ) {
			// Assign skdon_memorial_year taxonomy if missing.
			$terms = get_the_terms( $post->ID, 'skdon_memorial_year' );
			if ( ! $terms || is_wp_error( $terms ) ) {
				$date = $all_meta['_skdon_donation_date'][0] ?? $post->post_date;
				$year = Helpers\local_date( (string) $date )?->format( 'Y' ) ?? '';
				if ( $year ) {
					wp_set_object_terms( $post->ID, [ $year ], 'skdon_memorial_year' );
					++$fixed;
				}
			}

			// Fix email addresses stored as donor display names.
			$current_display = $all_meta['_skdon_donor_display_name'][0] ?? '';
			if ( self::looks_like_email( $current_display ) ) {
				$donor_id = (int) ( $all_meta['_skdon_donor_id'][0] ?? 0 );
				$is_anon  = filter_var( $all_meta['_skdon_is_anonymous'][0] ?? false, FILTER_VALIDATE_BOOLEAN );

				if ( ! $is_anon && $donor_id ) {
					$corrected = self::resolve_donor_display_name( $donor_id, false );

					// If the donor's _skdon_display_name is also an email
					// (hasn't been fixed yet), build from first/last directly.
					if ( empty( $corrected ) || self::looks_like_email( $corrected ) ) {
						$first = get_post_meta( $donor_id, '_skdon_first_name', true );
						$last  = get_post_meta( $donor_id, '_skdon_last_name', true );
						$corrected = trim( "$first $last" );
					}

					// If first/last are also empty (order had no billing name),
					// derive a name from the email address local part.
					if ( empty( $corrected ) || self::looks_like_email( $corrected ) ) {
						$donor_email = get_post_meta( $donor_id, '_skdon_email', true );
						if ( ! empty( $donor_email ) ) {
							$corrected = \Starter_Shelter\Admin\Shared\Donor_Lookup::build_display_name( '', '', $donor_email );
						}
					}

					if ( ! empty( $corrected ) && ! self::looks_like_email( $corrected ) ) {
						update_post_meta( $post->ID, '_skdon_donor_display_name', $corrected );
						++$fixed;
					}
				}
			}

			// Fix order-linked memorial dates that don't match the order date.
			$order_id = (int) ( $all_meta['_skdon_wc_order_id'][0] ?? 0 );
			if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order && $order->get_date_created() ) {
					$order_date    = $order->get_date_created()->format( 'Y-m-d H:i:s' );
					$memorial_date = $post->post_date;

					// Compare dates: if the memorial's date differs from the order by
					// more than 60 seconds, the memorial was likely stamped with the
					// sync time rather than the original order date.
					if ( abs( strtotime( $memorial_date ) - strtotime( $order_date ) ) > 60 ) {
						wp_update_post( [
							'ID'        => $post->ID,
							'post_date' => $order_date,
							'post_date_gmt' => get_gmt_from_date( $order_date ),
						] );
						update_post_meta( $post->ID, '_skdon_donation_date', $order_date );
						++$fixed;
					}
				}
			}
		}

		// Fix donors whose _skdon_display_name is an email address.
		if ( 'skdon_donor' === $post_type ) {
			$donor_display = $all_meta['_skdon_display_name'][0] ?? '';
			if ( self::looks_like_email( $donor_display ) ) {
				$first = $all_meta['_skdon_first_name'][0] ?? '';
				$last  = $all_meta['_skdon_last_name'][0] ?? '';
				$name  = trim( "$first $last" );

				// If first/last are empty (order had no billing name),
				// use build_display_name which derives a name from the
				// email local part (e.g. john.doe@example.com → "John Doe").
				if ( empty( $name ) ) {
					$email = $all_meta['_skdon_email'][0] ?? $donor_display;
					$name  = \Starter_Shelter\Admin\Shared\Donor_Lookup::build_display_name( '', '', $email );
				}

				if ( ! empty( $name ) && ! self::looks_like_email( $name ) ) {
					update_post_meta( $post->ID, '_skdon_display_name', $name );
					++$fixed;

					// Also fix post_title if it's the same email.
					if ( self::looks_like_email( $post->post_title ) ) {
						wp_update_post( [ 'ID' => $post->ID, 'post_title' => $name ] );
					}
				}
			}
		}

		// Generate import hash for records that don't have one (non-donor types).
		if ( 'skdon_donor' !== $post_type ) {
			$existing_hash = $all_meta[ \Starter_Shelter\Admin\Import_Export\CSV_Importer::HASH_META_KEY ][0] ?? '';
			if ( '' === $existing_hash ) {
				$hash = self::compute_backfill_hash( $post_type, $post->ID );
				if ( $hash ) {
					update_post_meta( $post->ID, \Starter_Shelter\Admin\Import_Export\CSV_Importer::HASH_META_KEY, $hash );
					++$fixed;
				}
			}
		}

		return $fixed;
	}

	/**
	 * Compute an import hash for an existing record (retroactive backfill).
	 *
	 * Maps post type → entity type key, looks up hash_columns from config,
	 * and delegates to CSV_Importer::compute_post_hash().
	 *
	 * @since 2.1.0
	 *
	 * @param string $post_type The post type slug.
	 * @param int    $post_id   The post ID.
	 * @return string|null The hash, or null if config not available.
	 */
	private static function compute_backfill_hash( string $post_type, int $post_id ): ?string {
		// Map post type → entity type key in import-export.json.
		$entity_type_map = [
			'skdon_donation'   => 'donations',
			'skdon_membership' => 'memberships',
			'skdon_memorial'   => 'memorials',
		];

		$entity_type = $entity_type_map[ $post_type ] ?? null;
		if ( ! $entity_type ) {
			return null;
		}

		$hash_columns = Config::get_path( 'import-export', "entity_types.{$entity_type}.import.hash_columns" );
		if ( empty( $hash_columns ) ) {
			return null;
		}

		return \Starter_Shelter\Admin\Import_Export\CSV_Importer::compute_post_hash(
			$entity_type,
			$post_id,
			$hash_columns
		);
	}

	// ─────────────────────────────────────────────────────────────
	// AJAX: Purge
	// ─────────────────────────────────────────────────────────────

	/**
	 * Delete a batch of posts for a given post type.
	 *
	 * Expects POST: post_type, confirmation (must match post_type slug), offset
	 * Returns: { deleted, remaining, done }
	 */
	public static function ajax_purge(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$post_type    = sanitize_key( $_POST['post_type'] ?? '' );
		$confirmation = sanitize_key( $_POST['confirmation'] ?? '' );

		if ( ! isset( self::ENTITY_TYPES[ $post_type ] ) ) {
			wp_send_json_error( 'Invalid post type.' );
		}

		// Double-check confirmation matches.
		if ( $confirmation !== $post_type ) {
			wp_send_json_error( __( 'Confirmation does not match. Type the exact post type slug.', 'shelterkit-donations' ) );
		}

		// One log entry for the purge, across its batches (#36). Each record
		// it deletes also gets an entry, attached to it.
		$run_id = Activity_Log::join_run( sanitize_text_field( wp_unslash( $_POST['run_id'] ?? '' ) ), 'purge', [ 'post_type' => $post_type ] );

		// Every status, the trash included. 'any' leaves the trash out while
		// the remaining count included it, so one trashed record kept the
		// purge asking for batches that deleted nothing, for ever (#36).
		$statuses = array_values( array_diff( get_post_stati(), [ 'auto-draft' ] ) );

		// Get a batch of posts to delete.
		$posts = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => $statuses,
			'posts_per_page' => self::BATCH_SIZE,
			'fields'         => 'ids',
		] );

		$deleted = 0;
		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true ); // Force delete, skip trash.
			++$deleted;
		}

		// Check remaining, counted over the same statuses.
		$remaining = ( new \WP_Query( [
			'post_type'      => $post_type,
			'post_status'    => $statuses,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] ) )->found_posts;

		Activity_Log::end_batch( $run_id, [ 'deleted' => $deleted ], 0 === $remaining );

		wp_send_json_success( [
			'deleted'   => $deleted,
			'remaining' => $remaining,
			'done'      => $remaining === 0,
			'run_id'    => $run_id,
		] );
	}

	// ─────────────────────────────────────────────────────────────
	// AJAX: Recalculate Donor Stats
	// ─────────────────────────────────────────────────────────────

	/**
	 * Recalculate lifetime_giving for a batch of donors.
	 *
	 * Sums all skdon_donation, skdon_memorial, and skdon_membership amounts
	 * linked to each donor.
	 */
	public static function ajax_recalc_donors(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- check_ajax_referer() and current_user_can() run at the top of this handler.
		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		$run_id = Activity_Log::join_run( sanitize_text_field( wp_unslash( $_POST['run_id'] ?? '' ) ), 'recalc_donors' );

		$donors = get_posts( [
			'post_type'      => 'skdon_donor',
			'post_status'    => 'any',
			'posts_per_page' => self::BATCH_SIZE,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		] );

		$processed = 0;
		$updated   = 0;

		foreach ( $donors as $donor_id ) {
			++$processed;

			// Sum amounts across all three entity types in a single query.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$current = (float) get_post_meta( $donor_id, '_skdon_lifetime_giving', true );

			if ( abs( Helpers\recalculate_donor_lifetime_giving( (int) $donor_id ) - $current ) > 0.005 ) {
				++$updated;
			}
		}

		// Total donors for progress.
		$total_counts = wp_count_posts( 'skdon_donor' );
		$total_donors = ( $total_counts->publish ?? 0 ) + ( $total_counts->draft ?? 0 );
		$new_offset   = $offset + count( $donors );

		Activity_Log::end_batch( $run_id, [ 'processed' => $processed, 'updated' => $updated ], count( $donors ) < self::BATCH_SIZE );

		wp_send_json_success( [
			'processed' => $processed,
			'updated'   => $updated,
			'offset'    => $new_offset,
			'total'     => $total_donors,
			'done'      => count( $donors ) < self::BATCH_SIZE,
			'run_id'    => $run_id,
		] );
	}
}
