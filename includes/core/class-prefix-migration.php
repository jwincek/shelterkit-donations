<?php
/**
 * One-time move of the plugin's stored data from the `sd_` prefix to `skdon_`.
 *
 * Loaded directly by the main plugin file (before the autoloader matters) and
 * by uninstall, so it depends on nothing but WordPress core and $wpdb.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Renames every stored name the plugin owns from `sd_` to `skdon_` (#30).
 *
 * WordPress.org asks for prefixes of at least four characters. Every name
 * listed here is persisted somewhere, so it moves by migration rather than by
 * code change alone:
 *
 * - post types and taxonomies, and their names wherever they are stored as
 *   values (menu items, block attributes, the activity log, saved templates)
 * - meta keys in all five meta tables (posts, terms, users, HPOS orders,
 *   order items)
 * - options, WooCommerce's saved email settings, per-user options and screen
 *   preferences, the activity-log table and its cron event
 *
 * Every statement names the keys it touches, and meta is renamed only on
 * objects the plugin owns: its post types and taxonomies, its logo
 * attachments, its products, and WooCommerce orders. Nothing here matches
 * `LIKE 'sd\_%'`. Other plugins use that prefix too, and a key such as
 * `_sd_email` on another plugin's posts is theirs.
 *
 * The map is one-to-one, so run( true ) moves everything back, for a rollback
 * to a 2.x release. Two cleanups ride along on the forward run only: the
 * unread `_sd_date` duplicate of `_sd_donation_date` is dropped, and memorials
 * that still keep family-notification settings as one nested array get the
 * five flat keys every reader uses.
 *
 * The same lists drive uninstall, `wp shelterkit-donations validate` and the
 * guard test, so there is one place to add a name.
 */
final class Prefix_Migration {

	/** Bump to re-run on sites that already migrated. */
	public const VERSION = '1';

	/** Option recording the migration has completed. */
	public const VERSION_OPTION = 'skdon_prefix_version';

	/** Option holding the last run's per-step counts (or its error). */
	public const REPORT_OPTION = 'skdon_prefix_migration_report';

	/**
	 * Set by `migrate-prefix --reverse`. While present the plugin stays inert,
	 * so it cannot move the data forward again before the 2.x code is back.
	 * Activating this release again lifts it (see maybe_run()).
	 */
	public const HOLD_OPTION = 'skdon_prefix_hold';

	/** Seconds a request waits for another request's run to finish. */
	private const LOCK_WAIT = 10;

	public const POST_TYPES = [ 'sd_donation', 'sd_donor', 'sd_membership', 'sd_memorial' ];

	public const TAXONOMIES = [ 'sd_campaign', 'sd_memorial_year', 'sd_donation_allocation' ];

	/**
	 * Every `_sd_` meta key a release has written: the literals in every
	 * tagged version's code, the manifest fields (meta_prefix + field), the
	 * order-item keys built from cart keys, and keys found on real data.
	 * `_sd_date` is not here; see LEGACY_DATE_KEY.
	 */
	public const META_KEYS = [
		'_sd_ability_result', '_sd_address', '_sd_address_last_updated', '_sd_address_line_1',
		'_sd_address_line_2', '_sd_address_update_source', '_sd_admin_notes', '_sd_allocation',
		'_sd_amount', '_sd_auto_renew', '_sd_business_description', '_sd_business_name',
		'_sd_business_website', '_sd_campaign_id', '_sd_campaign_type',
		'_sd_cancellation_reason', '_sd_cancelled_at', '_sd_candle_count', '_sd_candles',
		'_sd_city', '_sd_communication_preferences', '_sd_country', '_sd_created_date',
		'_sd_current_membership_id', '_sd_custom_price', '_sd_dedication',
		'_sd_dedication_enabled', '_sd_dedication_type', '_sd_display_name',
		'_sd_donation_count', '_sd_donation_date', '_sd_donation_type',
		'_sd_donor_display_name', '_sd_donor_id', '_sd_donor_level', '_sd_donor_name',
		'_sd_email', '_sd_email_preferences', '_sd_end_date', '_sd_family_address',
		'_sd_family_email', '_sd_family_name', '_sd_family_notified_date',
		'_sd_first_donation_date', '_sd_first_gift_date', '_sd_first_name', '_sd_goal',
		'_sd_honoree_name', '_sd_honoree_type', '_sd_import_hash', '_sd_import_line',
		'_sd_import_source', '_sd_is_anonymous', '_sd_is_member', '_sd_last_donation_date',
		'_sd_last_gift_date', '_sd_last_name', '_sd_legacy_sync_results', '_sd_legacy_synced',
		'_sd_lifetime_giving', '_sd_logo_approved_by', '_sd_logo_approved_date',
		'_sd_logo_attachment_id', '_sd_logo_rejected_by', '_sd_logo_rejected_date',
		'_sd_logo_rejection_reason', '_sd_logo_source', '_sd_logo_status',
		'_sd_membership_tier', '_sd_membership_tier_filter', '_sd_membership_type',
		'_sd_memorial_type', '_sd_notify_family', '_sd_notify_family_address',
		'_sd_notify_family_email', '_sd_notify_family_enabled', '_sd_notify_family_name',
		'_sd_notify_family_send_card', '_sd_pet_species', '_sd_phone', '_sd_photo_id',
		'_sd_postal_code', '_sd_processed', '_sd_processing_results', '_sd_product_type',
		'_sd_reminder_sent_date', '_sd_renewal_orders', '_sd_renewal_reminder_sent',
		'_sd_send_card', '_sd_start_date', '_sd_state', '_sd_status', '_sd_tier',
		'_sd_tribute_message', '_sd_user_id', '_sd_wc_order_id',
	];

	/** The only plugin keys on attachments: business-logo moderation. */
	public const ATTACHMENT_KEYS = [ '_sd_logo_status', '_sd_logo_source' ];

	/** The only plugin key on products: a donation variation's fund. */
	public const PRODUCT_KEYS = [ '_sd_allocation' ];

	/** The only plugin key in usermeta besides per-user options. */
	public const USER_KEYS = [ '_sd_candles' ];

	/**
	 * Options, including the taxonomies' `{tax}_children` / `default_term_{tax}`
	 * rows that core keys by taxonomy name, and every Config override
	 * (`sd_config_{name}_{key}` for each key in Config::$overridable, past and
	 * present).
	 */
	public const OPTIONS = [
		'sd_activity_log_db_version', 'sd_allocations_version', 'sd_business_membership_product_id',
		'sd_donation_product_id', 'sd_membership_product_id', 'sd_memorial_product_id',
		'sd_products_created', 'sd_legacy_sync_last_scan', 'sd_org_profile_migrated',
		'sd_campaign_children', 'sd_memorial_year_children', 'sd_donation_allocation_children',
		'default_term_sd_campaign', 'default_term_sd_memorial_year', 'default_term_sd_donation_allocation',
		'sd_config_settings_allocations', 'sd_config_settings_retired_allocations',
		'sd_config_settings_memorial_types', 'sd_config_settings_pet_species',
		'sd_config_settings_donor_levels', 'sd_config_tiers_tiers',
	];

	/**
	 * WooCommerce emails. `Config_Email` sets each WC_Email id to
	 * `skdon_{id}`, and WooCommerce saves the settings an admin edits under
	 * `woocommerce_{wc id}_settings`.
	 */
	public const EMAIL_IDS = [
		'donation_receipt', 'donor_annual_summary', 'membership_welcome', 'membership_renewal',
		'logo_approved', 'logo_rejected', 'memorial_confirmation', 'memorial_family_notification',
	];

	/** Per-user options; stored in usermeta behind the site's blog prefix. */
	public const USER_OPTIONS = [ 'sd_dashboard_period' ];

	/** Fixed transient names. They rebuild, so the old ones are deleted, not moved. */
	public const TRANSIENTS = [
		'sd_dashboard_action_items', 'sd_menu_pending_count', 'sd_product_sync_check', 'sd_repair_result',
	];

	/** Transients whose names carry an id or hash after these prefixes. */
	public const TRANSIENT_PREFIXES = [
		'sd_anon_candle_', 'sd_candle_rate_', 'sd_cart_rate_', 'sd_dashboard_stats_v2_',
		'sd_donor_id_', 'sd_export_membership_ids_', 'sd_import_',
	];

	public const CRON_HOOKS = [ 'sd_cleanup_activity_log' ];

	/** Activity-log table, without the site prefix. */
	public const TABLE = 'sd_activity_log';

	/** Block templates are keyed by slug, and a slug carries the post type. */
	public const TEMPLATE_SLUGS = [
		'single-sd_memorial', 'archive-sd_memorial', 'taxonomy-sd_campaign', 'taxonomy-sd_memorial_year',
	];

	/** Cart item data keys. A cart can sit in a WooCommerce session across the upgrade. */
	public const CART_KEYS = [
		'sd_custom_price', 'sd_allocation', 'sd_campaign_id', 'sd_dedication_enabled', 'sd_dedication_type',
		'sd_donor_name', 'sd_honoree_name', 'sd_honoree_type', 'sd_is_anonymous', 'sd_business_name',
		'sd_logo_attachment_id', 'sd_membership_tier', 'sd_notify_family_enabled', 'sd_notify_family_name',
		'sd_notify_family_email', 'sd_notify_family_address', 'sd_notify_family_send_card',
		'sd_product_type', 'sd_tribute_message',
	];

	/**
	 * AJAX and admin-post actions. Cached pages and open tabs keep sending the
	 * old names for a while, so they stay registered as aliases for one
	 * release. Nonce action strings never changed, so their nonces still pass.
	 */
	public const ACTIONS = [
		'sd_add_to_cart', 'sd_approve_logo', 'sd_bulk_logo_action', 'sd_dashboard_refresh', 'sd_debug_order',
		'sd_download_error_csv', 'sd_download_template', 'sd_export', 'sd_export_all', 'sd_export_report',
		'sd_extend_membership', 'sd_get_export_counts', 'sd_get_sync_stats', 'sd_integrity_backfill',
		'sd_integrity_purge', 'sd_integrity_recalc_donors', 'sd_integrity_scan', 'sd_notify_family',
		'sd_preview_import', 'sd_preview_import_memorials_legacy', 'sd_preview_legacy_order',
		'sd_process_import_batch', 'sd_process_import_donations', 'sd_process_import_donors',
		'sd_process_import_memberships', 'sd_process_import_memorials', 'sd_process_import_memorials_legacy',
		'sd_reject_logo', 'sd_reprocess_order', 'sd_resend_receipt', 'sd_reset_sync_status',
		'sd_scan_legacy_orders', 'sd_send_reminder', 'sd_send_statement', 'sd_start_import',
		'sd_sync_all_legacy_orders', 'sd_sync_legacy_orders', 'sd_view_receipt',
	];

	/** Written by 1.x as a copy of `_sd_donation_date`; read by nothing since. */
	public const LEGACY_DATE_KEY = '_sd_date';

	/** The five flat keys that replaced the nested family-notification array. */
	private const NOTIFY_FAMILY_FIELDS = [ 'enabled', 'name', 'email', 'address', 'send_card' ];

	/**
	 * Run the migration if this site has not had it, waiting for a run that
	 * another request already started.
	 *
	 * @return bool True when the data is under the new names and the plugin
	 *              may start. False keeps the plugin inert for this request.
	 */
	public static function maybe_run(): bool {
		// Core loads a plugin in its sandbox only to activate it, to resume it
		// after a fatal error, or to show an activation error: each a
		// deliberate choice to run this code. A site that rolled back to 2.x
		// and later upgrades again must not find the plugin still held, with
		// a notice pointing at a CLI command its admin may not have.
		if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
			delete_option( self::HOLD_OPTION );
		}
		if ( get_option( self::HOLD_OPTION ) ) {
			return false;
		}
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return true;
		}

		global $wpdb;
		$lock = self::lock_name();

		// Blocks for up to LOCK_WAIT seconds while another request migrates.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, self::LOCK_WAIT ) ) ) {
			return false;
		}

		try {
			// Another request may have finished while this one waited; read past the cache.
			$done = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::VERSION_OPTION
			) );
			if ( self::VERSION === $done ) {
				wp_cache_delete( 'alloptions', 'options' );
				return true;
			}

			$counts = self::run();
			update_option( self::VERSION_OPTION, self::VERSION, true );
			update_option( self::REPORT_OPTION, [ 'time' => time(), 'counts' => $counts, 'notice' => true ], false );
			return true;
		} catch ( \Throwable $e ) {
			update_option( self::REPORT_OPTION, [ 'time' => time(), 'error' => $e->getMessage(), 'notice' => true ], false );
			return false;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/**
	 * Move every listed name. Safe to run again: a second run finds nothing.
	 *
	 * @param bool $reverse Move skdon_ names back to sd_ instead.
	 * @return array<string, int> Rows or objects changed, per step.
	 * @throws \RuntimeException When a query fails; the caller records it.
	 */
	public static function run( bool $reverse = false ): array {
		$counts = [];

		$counts['post types']        = self::rename_post_types( $reverse );
		$counts['taxonomies']        = self::rename_taxonomies( $reverse );
		$counts['meta keys']         = self::rename_meta_keys( $reverse );
		$counts['options']           = self::rename_options( $reverse );
		$counts['user options']      = self::rename_user_keys( $reverse );
		$counts['activity log']      = self::rename_table( $reverse );
		$counts['templates']         = self::rename_templates( $reverse );
		$counts['block attributes']  = self::rewrite_block_attributes( $reverse );
		$counts['transients']        = self::delete_transients( $reverse );
		$counts['cron events']       = self::clear_cron( $reverse );

		if ( ! $reverse ) {
			$counts['merged _sd_date']        = self::merge_legacy_date();
			$counts['expanded notify_family'] = self::expand_nested_notify_family();
		}

		// Rewrite rules embed the post type and taxonomy names; core rebuilds
		// them on the next request once the option is gone.
		delete_option( 'rewrite_rules' );
		wp_cache_flush();

		return $counts;
	}

	/**
	 * The new name for an old one: the first `sd_` that starts the name or
	 * follows `_` or `-` becomes `skdon_`.
	 *
	 * `_sd_amount` → `_skdon_amount`, `single-sd_memorial` →
	 * `single-skdon_memorial`, `woocommerce_sd_logo_approved_settings` →
	 * `woocommerce_skdon_logo_approved_settings`.
	 *
	 * @param string $name Old name.
	 * @return string
	 */
	public static function new_name( string $name ): string {
		return (string) preg_replace( '/(^|[_-])sd_/', '$1skdon_', $name, 1 );
	}

	/**
	 * The old name for a new one.
	 *
	 * @param string $name New name.
	 * @return string
	 */
	public static function old_name( string $name ): string {
		return (string) preg_replace( '/(^|[_-])skdon_/', '$1sd_', $name, 1 );
	}

	/**
	 * Every option name the plugin owns, old and new. For uninstall and the
	 * validate check.
	 *
	 * @return string[]
	 */
	public static function all_option_names(): array {
		$old = array_merge( self::OPTIONS, self::email_option_names(), [ self::LEGACY_ORG_OPTION ] );
		$new = array_map( [ self::class, 'new_name' ], array_merge( self::OPTIONS, self::email_option_names() ) );
		return array_merge(
			$old,
			$new,
			[ self::VERSION_OPTION, self::REPORT_OPTION, self::HOLD_OPTION ]
		);
	}

	/**
	 * 2.x kept the organisation details in this option. Settings' one-time
	 * move to the shared ShelterKit profile reads it under this name, so it is
	 * never renamed.
	 */
	public const LEGACY_ORG_OPTION = 'sd_organization';

	/**
	 * WooCommerce's saved-settings option for each plugin email, old names.
	 *
	 * @return string[]
	 */
	public static function email_option_names(): array {
		return array_map(
			static fn ( string $id ): string => 'woocommerce_sd_' . $id . '_settings',
			self::EMAIL_IDS
		);
	}

	/**
	 * Keep a cart saved before the upgrade working: copy its `sd_*` item data
	 * to the new keys when a session cart is restored. Hooked to
	 * `woocommerce_get_cart_item_from_session` for one release.
	 *
	 * @param array $cart_item Cart item being restored.
	 * @param array $values    Raw values from the session.
	 * @return array
	 */
	public static function restore_session_cart_item( $cart_item, $values ) {
		if ( ! is_array( $cart_item ) || ! is_array( $values ) ) {
			return $cart_item;
		}
		foreach ( self::CART_KEYS as $old ) {
			$new = self::new_name( $old );
			if ( array_key_exists( $old, $values ) && ! array_key_exists( $new, $cart_item ) ) {
				$cart_item[ $new ] = $values[ $old ];
			}
		}
		return $cart_item;
	}

	/**
	 * Route the old AJAX and admin-post action names to the new handlers.
	 * Runs on admin_init, after every handler has registered, and only
	 * aliases hooks that have a handler under the new name.
	 */
	public static function register_action_aliases(): void {
		foreach ( self::ACTIONS as $old ) {
			$new = self::new_name( $old );
			foreach ( [ 'wp_ajax_', 'wp_ajax_nopriv_', 'admin_post_', 'admin_post_nopriv_' ] as $hook ) {
				if ( has_action( $hook . $new ) && ! has_action( $hook . $old ) ) {
					add_action(
						$hook . $old,
						static function () use ( $hook, $new ): void {
							do_action( $hook . $new ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- core's own wp_ajax_/admin_post_ hook, under the plugin's new action name.
						}
					);
				}
			}
		}
	}

	/**
	 * Show the last run's result once to an administrator, or keep showing it
	 * while the migration has not completed.
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$report = get_option( self::REPORT_OPTION );
		$done   = self::VERSION === get_option( self::VERSION_OPTION );

		if ( get_option( self::HOLD_OPTION ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'ShelterKit Donations is paused: its data was moved back to the 2.x names for a rollback. Install a 2.x release. To move the data forward again instead, deactivate and reactivate this plugin, or run `wp shelterkit-donations migrate-prefix`.', 'shelterkit-donations' )
			);
			return;
		}

		if ( ! $done ) {
			$error = is_array( $report ) ? (string) ( $report['error'] ?? '' ) : '';
			printf(
				'<div class="notice notice-error"><p>%s</p>%s</div>',
				esc_html__( 'ShelterKit Donations has not finished moving its data to the new storage names, so it is switched off until it does. It retries on every request. Check the database, then reload this page.', 'shelterkit-donations' ),
				'' !== $error ? '<p><code>' . esc_html( $error ) . '</code></p>' : ''
			);
			return;
		}

		if ( ! is_array( $report ) || empty( $report['notice'] ) ) {
			return;
		}

		$moved = array_filter( (array) ( $report['counts'] ?? [] ) );
		if ( $moved ) {
			$parts = [];
			foreach ( $moved as $step => $count ) {
				$parts[] = esc_html( $step ) . ': ' . (int) $count;
			}
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p><p>%s</p></div>',
				esc_html__( 'ShelterKit Donations moved its stored data to the new skdon_ names.', 'shelterkit-donations' ),
				implode( ' · ', $parts ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part is escaped above.
			);
		}

		$report['notice'] = false;
		update_option( self::REPORT_OPTION, $report, false );
	}

	/**
	 * Rows still stored under an old name, per place. Empty when the
	 * migration is complete. Used by `validate` and the tests.
	 *
	 * @return array<string, int>
	 */
	public static function leftovers(): array {
		global $wpdb;
		$found = [];

		$found['posts'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (" . self::in_list( self::POST_TYPES ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- in_list() escapes every value.
		);
		$found['terms'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN (" . self::in_list( self::TAXONOMIES ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- in_list() escapes every value.
		);
		foreach ( self::meta_scopes() as [ $table, , $keys, $join, $where ] ) {
			$found[ $table ] = ( $found[ $table ] ?? 0 ) + (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$table}` src {$join} WHERE src.meta_key IN (" . self::in_list( $keys ) . ") AND {$where}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- built by this class; in_list() escapes every value.
			);
		}
		$found[ $wpdb->postmeta ] += (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND p.post_type IN (" . self::in_list( array_map( [ self::class, 'new_name' ], [ 'sd_donation', 'sd_memorial' ] ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- in_list() escapes every value.
			self::LEGACY_DATE_KEY
		) );
		$found['options'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (" . self::in_list( array_merge( self::OPTIONS, self::email_option_names() ) ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- in_list() escapes every value.
		);
		$found['activity log table'] = self::table_exists( $wpdb->prefix . self::TABLE ) ? 1 : 0;
		$found['activity log rows']  = 0;
		$log                         = $wpdb->prefix . self::new_name( self::TABLE );
		if ( self::table_exists( $log ) ) {
			$found['activity log rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$log}` WHERE object_type IN (" . self::in_list( self::POST_TYPES ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb; in_list() escapes every value.
		}

		return array_filter( $found );
	}

	// ─── Steps ────────────────────────────────────────────────────────────

	/**
	 * Post types, and nav menu items pointing at one of them.
	 *
	 * @param bool $reverse Direction.
	 * @return int
	 */
	private static function rename_post_types( bool $reverse ): int {
		global $wpdb;
		$moved = 0;
		foreach ( self::pairs( self::POST_TYPES, $reverse ) as $from => $to ) {
			$moved += self::query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s", $to, $from ) );
			$moved += self::query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_menu_item_object' AND meta_value = %s",
				$to,
				$from
			) );
		}
		return $moved;
	}

	/**
	 * Taxonomies. When a term already exists under the new name (something
	 * created it before this ran), the old term's posts move onto it.
	 *
	 * @param bool $reverse Direction.
	 * @return int
	 */
	private static function rename_taxonomies( bool $reverse ): int {
		global $wpdb;
		$moved = 0;

		foreach ( self::pairs( self::TAXONOMIES, $reverse ) as $from => $to ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT tt.term_taxonomy_id, t.slug
				 FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE tt.taxonomy = %s",
				$from
			) );

			foreach ( $rows as $row ) {
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT tt.term_taxonomy_id
					 FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					 WHERE tt.taxonomy = %s AND t.slug = %s",
					$to,
					$row->slug
				) );

				if ( null === $existing ) {
					$moved += self::query( $wpdb->prepare(
						"UPDATE {$wpdb->term_taxonomy} SET taxonomy = %s WHERE term_taxonomy_id = %d",
						$to,
						$row->term_taxonomy_id
					) );
					continue;
				}

				// Merge: relationships onto the term that already has the new
				// name, then drop the old term_taxonomy row and recount.
				self::query( $wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->term_relationships} ( object_id, term_taxonomy_id, term_order )
					 SELECT object_id, %d, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
					$existing,
					$row->term_taxonomy_id
				) );
				self::query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $row->term_taxonomy_id ) );
				self::query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $row->term_taxonomy_id ) );
				self::query( $wpdb->prepare(
					"UPDATE {$wpdb->term_taxonomy} SET count = ( SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d ) WHERE term_taxonomy_id = %d",
					$existing,
					$existing
				) );
				++$moved;
			}

			$moved += self::query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_menu_item_object' AND meta_value = %s",
				$to,
				$from
			) );
		}

		return $moved;
	}

	/**
	 * Meta keys, on the plugin's own objects only (see meta_scopes()). Where
	 * an object already has the target key, that row is kept and the source
	 * row dropped.
	 *
	 * @param bool $reverse Direction.
	 * @return int
	 */
	private static function rename_meta_keys( bool $reverse ): int {
		global $wpdb;
		$moved = 0;
		$map   = [ self::class, $reverse ? 'old_name' : 'new_name' ];
		// '_sd_' is 4 characters and '_skdon_' 7, so the rest of the key starts
		// at the 5th or the 8th.
		$target = $reverse ? "CONCAT( '_sd_', SUBSTRING( src.meta_key, 8 ) )" : "CONCAT( '_skdon_', SUBSTRING( src.meta_key, 5 ) )";

		foreach ( self::meta_scopes() as [ $table, $object_column, $keys, $join, $where ] ) {
			$sources = $reverse ? array_map( [ self::class, 'new_name' ], $keys ) : $keys;
			$in      = self::in_list( $sources );
			$id      = self::id_column( $table );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table, column and scope SQL are built by this class; in_list() escapes every key.

			// An object that already has the target key keeps it; its source
			// row is dropped. Found in two passes, never a self-join, because
			// MySQL cannot open a TEMPORARY table twice in one query.
			$existing = $wpdb->get_results(
				"SELECT {$object_column} AS o, meta_key AS k FROM `{$table}` WHERE meta_key IN (" . self::in_list( array_map( $map, $sources ) ) . ')'
			);
			if ( $existing ) {
				$taken = [];
				foreach ( $existing as $row ) {
					$taken[ $row->o . '|' . $row->k ] = true;
				}
				$drop = [];
				foreach ( $wpdb->get_results( "SELECT src.{$id} AS id, src.{$object_column} AS o, src.meta_key AS k FROM `{$table}` src {$join} WHERE src.meta_key IN ({$in}) AND {$where}" ) as $row ) {
					if ( isset( $taken[ $row->o . '|' . $map( $row->k ) ] ) ) {
						$drop[] = (int) $row->id;
					}
				}
				foreach ( array_chunk( $drop, 500 ) as $chunk ) {
					self::query( "DELETE FROM `{$table}` WHERE {$id} IN (" . implode( ',', $chunk ) . ')' );
				}
			}

			$moved += self::query(
				"UPDATE `{$table}` src {$join} SET src.meta_key = {$target} WHERE src.meta_key IN ({$in}) AND {$where}"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		}

		return $moved;
	}

	/**
	 * Primary key column of a meta table.
	 *
	 * @param string $table Full table name.
	 * @return string
	 */
	private static function id_column( string $table ): string {
		global $wpdb;
		if ( $wpdb->usermeta === $table ) {
			return 'umeta_id';
		}
		return str_ends_with( $table, 'wc_orders_meta' ) ? 'id' : 'meta_id';
	}

	/**
	 * Options and WooCommerce's saved email settings. An existing target
	 * option is kept and the source dropped.
	 *
	 * @param bool $reverse Direction.
	 * @return int
	 */
	private static function rename_options( bool $reverse ): int {
		$names = array_merge( self::OPTIONS, self::email_option_names() );
		$moved = 0;
		foreach ( self::pairs( $names, $reverse ) as $from => $to ) {
			$moved += self::rename_row( 'options', 'option_name', $from, $to );
		}
		return $moved;
	}

	/**
	 * Per-user options and the screen preferences core keys by post type or
	 * taxonomy (hidden columns, closed boxes, box order, items per page).
	 *
	 * @param bool $reverse Direction.
	 * @return int
	 */
	private static function rename_user_keys( bool $reverse ): int {
		global $wpdb;
		$names = [];
		foreach ( self::USER_OPTIONS as $option ) {
			$names[] = $wpdb->get_blog_prefix() . $option;
		}
		foreach ( self::POST_TYPES as $post_type ) {
			foreach ( [ 'metaboxhidden_%s', 'closedpostboxes_%s', 'meta-box-order_%s', 'screen_layout_%s', 'manageedit-%scolumnshidden', 'edit_%s_per_page' ] as $pattern ) {
				$names[] = sprintf( $pattern, $post_type );
			}
		}
		foreach ( self::TAXONOMIES as $taxonomy ) {
			foreach ( [ 'manageedit-%scolumnshidden', 'edit_%s_per_page' ] as $pattern ) {
				$names[] = sprintf( $pattern, $taxonomy );
			}
		}

		$moved = 0;
		foreach ( self::pairs( $names, $reverse ) as $from => $to ) {
			$moved += self::rename_row( 'usermeta', 'meta_key', $from, $to, 'user_id' );
		}
		return $moved;
	}

	/**
	 * The activity-log table, and the post type names it stores as values.
	 *
	 * @param bool $reverse Direction.
	 * @return int Rows whose object_type changed, plus one when the table moved.
	 */
	private static function rename_table( bool $reverse ): int {
		global $wpdb;
		$old  = $wpdb->prefix . self::TABLE;
		$new  = $wpdb->prefix . self::new_name( self::TABLE );
		$from = $reverse ? $new : $old;
		$to   = $reverse ? $old : $new;

		$moved = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names are the site prefix plus a constant.
		if ( self::table_exists( $from ) ) {
			// Copy rather than ALTER TABLE … RENAME: MySQL commits implicitly on
			// ALTER even for a TEMPORARY table, and the test suite runs inside a
			// transaction. INSERT IGNORE keeps each row's id, so a retry after a
			// run that stopped between the copy and the drop adds nothing twice.
			// The log holds one short row per event, so the copy is quick.
			if ( ! self::table_exists( $to ) ) {
				self::query( "CREATE TABLE `{$to}` LIKE `{$from}`" );
			}
			self::query( "INSERT IGNORE INTO `{$to}` SELECT * FROM `{$from}`" );
			self::query( "DROP TABLE `{$from}`" );
			$moved = 1;
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange

		if ( ! self::table_exists( $to ) ) {
			return 0;
		}
		foreach ( self::pairs( self::POST_TYPES, $reverse ) as $type_from => $type_to ) {
			$moved += self::query( $wpdb->prepare( "UPDATE `{$to}` SET object_type = %s WHERE object_type = %s", $type_to, $type_from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is the site prefix plus a constant.
		}
		return $moved;
	}

	/**
	 * Site Editor customisations of templates whose slug carries a renamed
	 * post type or taxonomy.
	 *
	 * @param bool $reverse Direction.
	 * @return int
	 */
	private static function rename_templates( bool $reverse ): int {
		global $wpdb;
		$moved = 0;
		foreach ( self::pairs( self::TEMPLATE_SLUGS, $reverse ) as $from => $to ) {
			$moved += self::query( $wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_name = %s WHERE post_type = 'wp_template' AND post_name = %s",
				$to,
				$from
			) );
		}
		return $moved;
	}

	/**
	 * Post type and taxonomy names inside block comment delimiters, such as
	 * a Query Loop's `"postType":"sd_memorial"` or a `taxQuery` keyed by
	 * `sd_campaign`. Prose is never touched.
	 *
	 * @param bool $reverse Direction.
	 * @return int Posts changed.
	 * @throws \RuntimeException When a post cannot be updated.
	 */
	private static function rewrite_block_attributes( bool $reverse ): int {
		global $wpdb;
		$names = self::pairs( array_merge( self::POST_TYPES, self::TAXONOMIES ), $reverse );
		uksort( $names, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$needle = $reverse ? '%"skdon\\_%' : '%"sd\\_%';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_type <> 'revision' AND post_content LIKE %s AND post_content LIKE %s",
			'%<!-- wp:%',
			$needle
		) );

		$pattern = '/"(' . implode( '|', array_map( 'preg_quote', array_keys( $names ) ) ) . ')"/';
		$changed = 0;
		foreach ( $rows as $row ) {
			$content = preg_replace_callback(
				'/<!--\s+\/?wp:.*?-->/s',
				static fn ( array $block ): string => (string) preg_replace_callback(
					$pattern,
					static fn ( array $m ): string => '"' . $names[ $m[1] ] . '"',
					$block[0]
				),
				(string) $row->post_content
			);
			if ( null !== $content && $content !== $row->post_content ) {
				if ( false === $wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => (int) $row->ID ] ) ) {
					throw new \RuntimeException( 'Prefix migration could not update post ' . (int) $row->ID . ': ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- recorded in an option, escaped where shown.
				}
				clean_post_cache( (int) $row->ID );
				++$changed;
			}
		}
		return $changed;
	}

	/**
	 * Delete the transients kept under the names being moved away from. They
	 * are caches and rate limits, so the new code rebuilds what it needs.
	 *
	 * @param bool $reverse Direction.
	 * @return int
	 */
	private static function delete_transients( bool $reverse ): int {
		global $wpdb;
		$fixed    = $reverse ? array_map( [ self::class, 'new_name' ], self::TRANSIENTS ) : self::TRANSIENTS;
		$prefixes = $reverse ? array_map( [ self::class, 'new_name' ], self::TRANSIENT_PREFIXES ) : self::TRANSIENT_PREFIXES;

		$names = [];
		foreach ( $fixed as $name ) {
			$names[] = '_transient_' . $name;
			$names[] = '_transient_timeout_' . $name;
		}
		$deleted = self::query( "DELETE FROM {$wpdb->options} WHERE option_name IN (" . self::in_list( $names ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- in_list() escapes every value.

		// These carry an id or hash after a long, specific prefix.
		foreach ( $prefixes as $prefix ) {
			foreach ( [ '_transient_', '_transient_timeout_' ] as $kind ) {
				$deleted += self::query( $wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $kind . $prefix ) . '%'
				) );
			}
		}
		return $deleted;
	}

	/**
	 * Unschedule events under the old hook names; the plugin schedules the
	 * new ones itself.
	 *
	 * @param bool $reverse Direction.
	 * @return int
	 */
	private static function clear_cron( bool $reverse ): int {
		$cleared = 0;
		foreach ( self::CRON_HOOKS as $hook ) {
			$result   = wp_clear_scheduled_hook( $reverse ? self::new_name( $hook ) : $hook );
			$cleared += is_int( $result ) ? $result : 0;
		}
		return $cleared;
	}

	/**
	 * Drop `_sd_date`. 1.x wrote it as a copy of `_sd_donation_date`, nothing
	 * has read it since, and Data Integrity's date repair updated only the
	 * real key, so some copies are stale. A record that lacks the real key
	 * gets this value first.
	 *
	 * @return int Rows dropped.
	 */
	private static function merge_legacy_date(): int {
		global $wpdb;
		$types = self::in_list( array_map( [ self::class, 'new_name' ], [ 'sd_donation', 'sd_memorial' ] ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- in_list() escapes every value; the rest are constants.
		self::query( $wpdb->prepare(
			"INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value )
			 SELECT legacy.post_id, '_skdon_donation_date', legacy.meta_value
			 FROM {$wpdb->postmeta} legacy
			 INNER JOIN {$wpdb->posts} p ON p.ID = legacy.post_id AND p.post_type IN ({$types})
			 WHERE legacy.meta_key = %s AND legacy.meta_value <> ''
			   AND NOT EXISTS (
			     SELECT 1 FROM {$wpdb->postmeta} real_key
			     WHERE real_key.post_id = legacy.post_id AND real_key.meta_key = '_skdon_donation_date'
			   )",
			self::LEGACY_DATE_KEY
		) );

		return self::query( $wpdb->prepare(
			"DELETE legacy FROM {$wpdb->postmeta} legacy
			 INNER JOIN {$wpdb->posts} p ON p.ID = legacy.post_id AND p.post_type IN ({$types})
			 WHERE legacy.meta_key = %s",
			self::LEGACY_DATE_KEY
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Memorials from before 1.1.2 keep family-notification settings as one
	 * serialized array. Write the five flat keys for each such memorial that
	 * has none of them, then drop the array. A memorial that already has flat
	 * keys keeps them: they were written later.
	 *
	 * @return int Memorials converted or cleaned.
	 */
	private static function expand_nested_notify_family(): int {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.meta_id, pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_skdon_notify_family' AND p.post_type = %s",
			self::new_name( 'sd_memorial' )
		) );

		$flat_keys = array_map( static fn ( string $field ): string => '_skdon_notify_family_' . $field, self::NOTIFY_FAMILY_FIELDS );
		$done      = 0;
		foreach ( $rows as $row ) {
			$has_flat = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (" . self::in_list( $flat_keys ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- in_list() escapes every value.
				$row->post_id
			) );
			$nested = maybe_unserialize( $row->meta_value );

			if ( 0 === $has_flat && is_array( $nested ) ) {
				foreach ( self::NOTIFY_FAMILY_FIELDS as $field ) {
					$value = $nested[ $field ] ?? null;
					if ( null === $value || '' === $value || false === $value ) {
						continue;
					}
					$wpdb->insert(
						$wpdb->postmeta,
						[
							'post_id'    => (int) $row->post_id,
							'meta_key'   => '_skdon_notify_family_' . $field, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration.
							'meta_value' => is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-time migration.
						]
					);
				}
			}

			$wpdb->delete( $wpdb->postmeta, [ 'meta_id' => (int) $row->meta_id ] );
			++$done;
		}
		return $done;
	}

	// ─── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Rename one row's key in options or usermeta, keeping an existing
	 * target row.
	 *
	 * @param string      $table        'options' or 'usermeta'.
	 * @param string      $column       The key column.
	 * @param string      $from         Source name.
	 * @param string      $to           Target name.
	 * @param string|null $owner_column Per-owner key (usermeta's user_id), if any.
	 * @return int Rows renamed.
	 */
	private static function rename_row( string $table, string $column, string $from, string $to, ?string $owner_column = null ): int {
		global $wpdb;
		$tbl = $wpdb->{$table};

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column names are fixed by this class.
		if ( null === $owner_column ) {
			$target_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE {$column} = %s", $to ) );
			if ( $target_exists ) {
				self::query( $wpdb->prepare( "DELETE FROM {$tbl} WHERE {$column} = %s", $from ) );
				return 0;
			}
			return self::query( $wpdb->prepare( "UPDATE {$tbl} SET {$column} = %s WHERE {$column} = %s", $to, $from ) );
		}

		self::query( $wpdb->prepare(
			"DELETE src FROM {$tbl} src INNER JOIN {$tbl} dst ON dst.{$owner_column} = src.{$owner_column} AND dst.{$column} = %s
			 WHERE src.{$column} = %s",
			$to,
			$from
		) );
		return self::query( $wpdb->prepare( "UPDATE {$tbl} SET {$column} = %s WHERE {$column} = %s", $to, $from ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Where each list of meta keys may be renamed: table, object column, keys,
	 * a JOIN to the owning object (alias `owner`, meta row alias `src`), and
	 * the condition that the owner is the plugin's.
	 *
	 * Order and order-item meta take the full key list unscoped. The plugin
	 * writes into WooCommerce orders by several routes (checkout fields from
	 * the manifests, cart keys, legacy sync), and a key missed there would be
	 * orphaned silently; another plugin's identical key on an order line is
	 * the far smaller risk. The HPOS table only exists once WooCommerce has
	 * created it.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string[], 3: string, 4: string}>
	 */
	private static function meta_scopes(): array {
		global $wpdb;
		$order_types = [ 'shop_order', 'shop_order_placehold', 'shop_order_refund' ];
		$post_types  = self::in_list( array_merge( self::POST_TYPES, array_map( [ self::class, 'new_name' ], self::POST_TYPES ), $order_types ) );
		$taxonomies  = self::in_list( array_merge( self::TAXONOMIES, array_map( [ self::class, 'new_name' ], self::TAXONOMIES ) ) );
		$by_post     = "INNER JOIN {$wpdb->posts} owner ON owner.ID = src.post_id";

		$scopes = [
			[ $wpdb->postmeta, 'post_id', self::META_KEYS, $by_post, "owner.post_type IN ({$post_types})" ],
			[ $wpdb->postmeta, 'post_id', self::ATTACHMENT_KEYS, $by_post, "owner.post_type = 'attachment'" ],
			[ $wpdb->postmeta, 'post_id', self::PRODUCT_KEYS, $by_post, "owner.post_type IN ('product','product_variation')" ],
			[ $wpdb->termmeta, 'term_id', self::META_KEYS, "INNER JOIN {$wpdb->term_taxonomy} owner ON owner.term_id = src.term_id", "owner.taxonomy IN ({$taxonomies})" ],
			[ $wpdb->usermeta, 'user_id', self::USER_KEYS, '', '1 = 1' ],
		];
		foreach ( [ 'woocommerce_order_itemmeta' => 'order_item_id', 'wc_orders_meta' => 'order_id' ] as $table => $object_column ) {
			if ( self::table_exists( $wpdb->prefix . $table ) ) {
				$scopes[] = [ $wpdb->prefix . $table, $object_column, self::META_KEYS, '', '1 = 1' ];
			}
		}
		return $scopes;
	}

	/**
	 * Map each name to its counterpart in the chosen direction.
	 *
	 * @param string[] $old_names Old names.
	 * @param bool     $reverse   Direction.
	 * @return array<string, string> Source => target.
	 */
	private static function pairs( array $old_names, bool $reverse ): array {
		$pairs = [];
		foreach ( $old_names as $old ) {
			$new = self::new_name( $old );
			if ( $reverse ) {
				$pairs[ $new ] = $old;
			} else {
				$pairs[ $old ] = $new;
			}
		}
		return $pairs;
	}

	/**
	 * A quoted, escaped SQL IN list.
	 *
	 * @param string[] $values Values.
	 * @return string
	 */
	private static function in_list( array $values ): string {
		return implode( ',', array_map( static fn ( string $v ): string => "'" . esc_sql( $v ) . "'", $values ) );
	}

	/**
	 * Whether a table exists. Probes it rather than using SHOW TABLES, which
	 * does not list TEMPORARY tables.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix.
		$wpdb->suppress_errors( $suppress );
		$wpdb->last_error = '';
		return false !== $result;
	}

	/**
	 * Run a write and fail loudly: a half-applied migration must stop, not
	 * carry on.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int Rows affected.
	 * @throws \RuntimeException On a database error.
	 */
	private static function query( string $sql ): int {
		global $wpdb;
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers prepare or escape.
		if ( false === $result || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Prefix migration query failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- recorded in an option, escaped where shown.
		}
		return (int) $result;
	}

	/**
	 * Advisory lock name: one per site, so multisite sites migrate independently.
	 *
	 * @return string
	 */
	private static function lock_name(): string {
		global $wpdb;
		$db = defined( 'DB_NAME' ) ? DB_NAME : 'wp';
		return substr( $db . '.' . $wpdb->prefix . 'skdon_prefix', 0, 64 );
	}
}
