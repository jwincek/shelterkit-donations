<?php
/**
 * Guards for the sd_ → skdon_ storage move (#30).
 *
 * The rename left `sd_` names in the code on purpose: markup, nonce actions,
 * URL parameters and the candle cookie (see KEEP). Anything else spelled
 * `sd_` or `_sd_` is a stored name that escaped the rename, or a new one
 * written with the old prefix. Either way it would read or write data the
 * migration has moved, and fail silently: a missing meta key reads as ''.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Starter_Shelter\Core\Prefix_Migration;

require_once dirname( __DIR__, 2 ) . '/includes/core/class-prefix-migration.php';

final class PrefixGuardTest extends TestCase {

	/**
	 * `sd_` names that are deliberately not storage, and why.
	 */
	private const KEEP = [
		// Nonce actions and nonce field names. Unchanged, so a nonce on a
		// cached page or an open tab still verifies after the upgrade.
		'sd_add_to_cart', 'sd_create_products', 'sd_dashboard_refresh', 'sd_data_config', 'sd_data_integrity',
		'sd_download_error_csv', 'sd_download_template', 'sd_export_counts', 'sd_export_memberships',
		'sd_export_report', 'sd_extend_', 'sd_import_export', 'sd_legacy_sync', 'sd_logo_moderation',
		'sd_membership_action', 'sd_membership_nonce', 'sd_meta_box_', 'sd_notify_family_', 'sd_preview_import',
		'sd_process_import', 'sd_product_mappings', 'sd_profile_action', 'sd_profile_nonce', 'sd_recheck_sync',
		'sd_recognition', 'sd_recognition_nonce', 'sd_repair_sync', 'sd_reprocess_nonce', 'sd_reprocess_order',
		'sd_resend_receipt_', 'sd_send_reminder_', 'sd_send_statement_', 'sd_view_receipt_',
		// Form field names, add-to-cart URL parameters and admin-notice query
		// arguments: markup and links, which can sit in a shelter's own pages.
		'sd_', 'sd_account_action', 'sd_action', 'sd_allocation', 'sd_allocations', 'sd_amount', 'sd_anonymous',
		'sd_biz_tiers', 'sd_campaign', 'sd_campaign_type', 'sd_count', 'sd_dashboard_period', 'sd_donor_levels',
		'sd_end_date', 'sd_goal', 'sd_ind_tiers', 'sd_list_publicly', 'sd_logo_attachment_id',
		'sd_membership_tier_filter', 'sd_membership_type', 'sd_restore_allocations', 'sd_save_data_config',
		'sd_save_product_mappings', 'sd_species',
		// Bulk-action values, settings-section ids, and meta box / dashboard
		// widget ids (screen preferences store these as values).
		'sd_data', 'sd_emails', 'sd_export_selected', 'sd_extend_30_days', 'sd_features', 'sd_general',
		'sd_notify_families', 'sd_pages', 'sd_processing_status', 'sd_resend_receipts', 'sd_send_reminders',
		'sd_dashboard_widget',
		// A cookie, kept in visitors' browsers where no migration reaches.
		'sd_candles',
	];

	/** Shipped code. Docs, tests and history keep the old names on purpose. */
	private const SCAN = [ 'shelterkit-donations.php', 'includes', 'blocks', 'assets', 'config', 'templates' ];

	/** The migration and uninstall name the old keys; that is their job. */
	private const EXEMPT = [
		'includes/core/class-prefix-migration.php',
		'includes/uninstall-cleanup.php',
		'includes/cli/class-prefix-command.php',
	];

	public function test_no_stored_name_keeps_the_old_prefix(): void {
		$root  = dirname( __DIR__, 2 ) . '/';
		$found = [];

		foreach ( $this->files( $root ) as $file ) {
			$relative = substr( $file, strlen( $root ) );
			foreach ( file( $file ) ?: [] as $index => $line ) {
				if ( ! preg_match_all( '/(?<![A-Za-z0-9_$])(_?sd_[a-z0-9_]*)/', $line, $matches ) ) {
					continue;
				}
				foreach ( $matches[1] as $name ) {
					if ( str_starts_with( $name, '_sd_' ) || ! in_array( $name, self::KEEP, true ) ) {
						$found[] = sprintf( '%s:%d %s', $relative, $index + 1, $name );
					}
				}
			}
		}

		$this->assertSame(
			[],
			$found,
			"Stored names use skdon_ (#30). If one of these is markup, a nonce action or a URL parameter, add it to KEEP with the reason."
		);
	}

	public function test_names_map_one_to_one(): void {
		$lists = [
			Prefix_Migration::POST_TYPES, Prefix_Migration::TAXONOMIES, Prefix_Migration::META_KEYS,
			Prefix_Migration::ATTACHMENT_KEYS, Prefix_Migration::PRODUCT_KEYS, Prefix_Migration::USER_KEYS,
			Prefix_Migration::OPTIONS, Prefix_Migration::USER_OPTIONS, Prefix_Migration::TRANSIENTS,
			Prefix_Migration::TRANSIENT_PREFIXES, Prefix_Migration::CRON_HOOKS, Prefix_Migration::TEMPLATE_SLUGS,
			Prefix_Migration::CART_KEYS, Prefix_Migration::ACTIONS, Prefix_Migration::email_option_names(),
			[ Prefix_Migration::TABLE ],
		];
		foreach ( $lists as $names ) {
			$this->assertSame( array_values( array_unique( $names ) ), $names, 'No duplicates.' );
			foreach ( $names as $old ) {
				$new = Prefix_Migration::new_name( $old );
				$this->assertStringContainsString( 'skdon_', $new, $old );
				$this->assertStringNotContainsString( 'sd_', str_replace( 'skdon_', '', $new ), "$old renames only its prefix." );
				$this->assertSame( $old, Prefix_Migration::old_name( $new ), "$old round-trips." );
			}
		}
	}

	public function test_known_renames(): void {
		$this->assertSame( '_skdon_amount', Prefix_Migration::new_name( '_sd_amount' ) );
		$this->assertSame( 'skdon_campaign_children', Prefix_Migration::new_name( 'sd_campaign_children' ) );
		$this->assertSame( 'single-skdon_memorial', Prefix_Migration::new_name( 'single-sd_memorial' ) );
		$this->assertSame( 'woocommerce_skdon_logo_approved_settings', Prefix_Migration::new_name( 'woocommerce_sd_logo_approved_settings' ) );
		$this->assertSame( 'manageedit-skdon_donationcolumnshidden', Prefix_Migration::new_name( 'manageedit-sd_donationcolumnshidden' ) );
	}

	public function test_new_names_fit_core_limits(): void {
		foreach ( Prefix_Migration::POST_TYPES as $old ) {
			$this->assertLessThanOrEqual( 20, strlen( Prefix_Migration::new_name( $old ) ), 'Post type names max out at 20 characters.' );
		}
		foreach ( Prefix_Migration::TAXONOMIES as $old ) {
			$this->assertLessThanOrEqual( 32, strlen( Prefix_Migration::new_name( $old ) ), 'Taxonomy names max out at 32 characters.' );
		}
	}

	public function test_scoped_key_lists_are_on_the_main_list(): void {
		foreach ( [ Prefix_Migration::ATTACHMENT_KEYS, Prefix_Migration::PRODUCT_KEYS, Prefix_Migration::USER_KEYS ] as $keys ) {
			$this->assertSame( [], array_diff( $keys, Prefix_Migration::META_KEYS ) );
		}
		$this->assertNotContains( Prefix_Migration::LEGACY_DATE_KEY, Prefix_Migration::META_KEYS, '_sd_date is dropped, not moved.' );
		// Order-item meta is '_' . cart key, so every cart key's item key moves too.
		foreach ( Prefix_Migration::CART_KEYS as $key ) {
			$this->assertContains( '_' . $key, Prefix_Migration::META_KEYS );
		}
	}

	/** @return string[] */
	private function files( string $root ): array {
		$files = [];
		foreach ( self::SCAN as $path ) {
			if ( is_file( $root . $path ) ) {
				$files[] = $root . $path;
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $path, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				$relative = substr( $file->getPathname(), strlen( $root ) );
				if ( preg_match( '/\.(php|js|json|html)$/', $relative ) && ! in_array( $relative, self::EXEMPT, true ) && ! str_contains( $relative, '/build/' ) ) {
					$files[] = $file->getPathname();
				}
			}
		}
		return $files;
	}
}
