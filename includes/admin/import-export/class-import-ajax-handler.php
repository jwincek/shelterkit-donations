<?php
/**
 * Import AJAX Handler - Thin layer for all import/export AJAX endpoints.
 *
 * Consolidates the following AJAX handlers from the old monolith:
 * - ajax_preview_import()
 * - ajax_process_import_donors()
 * - ajax_process_import_donations()
 * - ajax_process_import_memorials()
 * - ajax_process_import_memberships()
 * - ajax_preview_import_memorials_legacy()
 * - ajax_process_import_memorials_legacy()
 * - ajax_download_template()
 * - ajax_get_export_counts()
 *
 * The four separate process handlers (donors, donations, memorials, memberships)
 * collapse into a single generic import() method that extracts the entity type
 * from the AJAX action name.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Import_Export
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Import_Export;

use Starter_Shelter\Core\Config;

/**
 * Registers and handles all import/export AJAX endpoints.
 *
 * @since 2.0.0
 */
class Import_Ajax_Handler {

	/**
	 * Register all AJAX actions.
	 *
	 * @since 2.0.0
	 */
	public static function init(): void {
		// Generic preview (for standard CSV imports).
		add_action( 'wp_ajax_sd_preview_import', [ self::class, 'preview' ] );

		// Generic import handlers (entity type extracted from action name).
		add_action( 'wp_ajax_sd_process_import_donors', [ self::class, 'import' ] );
		add_action( 'wp_ajax_sd_process_import_donations', [ self::class, 'import' ] );
		add_action( 'wp_ajax_sd_process_import_memorials', [ self::class, 'import' ] );
		add_action( 'wp_ajax_sd_process_import_memberships', [ self::class, 'import' ] );

		// Legacy memorial format (separate handlers due to different parameters).
		add_action( 'wp_ajax_sd_preview_import_memorials_legacy', [ self::class, 'preview_legacy_memorials' ] );
		add_action( 'wp_ajax_sd_process_import_memorials_legacy', [ self::class, 'import_legacy_memorials' ] );

		// Batched import.
		add_action( 'wp_ajax_sd_start_import', [ self::class, 'start_batch_import' ] );
		add_action( 'wp_ajax_sd_process_import_batch', [ self::class, 'process_batch' ] );
		add_action( 'wp_ajax_sd_download_error_csv', [ self::class, 'download_error_csv' ] );

		// Templates and counts.
		add_action( 'wp_ajax_sd_download_template', [ self::class, 'download_template' ] );
		add_action( 'wp_ajax_sd_get_export_counts', [ self::class, 'get_export_counts' ] );
	}

	/**
	 * Preview a standard CSV import.
	 *
	 * @since 2.0.0
	 */
	public static function preview(): void {
		check_ajax_referer( 'sd_preview_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$import_type = sanitize_key( $_POST['import_type'] ?? '' );
		$file        = $_FILES['file'] ?? null;

		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		// Validate the entity type exists in config.
		$config = Config::get_path( 'import-export', "entity_types.{$import_type}" );
		if ( ! $config ) {
			wp_send_json_error( __( 'Invalid import type.', 'starter-shelter' ) );
		}

		$preview = CSV_Importer::preview( $import_type, $file['tmp_name'] );

		if ( isset( $preview['error'] ) ) {
			wp_send_json_error( $preview['error'] );
		}

		wp_send_json_success( $preview );
	}

	/**
	 * Process a standard CSV import.
	 *
	 * Generic handler for all four entity types. The entity type is
	 * extracted from the AJAX action name:
	 *   sd_process_import_donors      → donors
	 *   sd_process_import_donations   → donations
	 *   sd_process_import_memorials   → memorials
	 *   sd_process_import_memberships → memberships
	 *
	 * @since 2.0.0
	 */
	public static function import(): void {
		check_ajax_referer( 'sd_process_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		// Extract entity type from the AJAX action.
		$action      = sanitize_key( $_POST['action'] ?? '' );
		$entity_type = str_replace( 'sd_process_import_', '', $action );

		// Validate entity type exists.
		$config = Config::get_path( 'import-export', "entity_types.{$entity_type}" );
		if ( ! $config ) {
			wp_send_json_error( __( 'Invalid import type.', 'starter-shelter' ) );
		}

		// Collect options from POST data.
		$options = [
			'create_donors'   => ! empty( $_POST['create_donors'] ),
			'update_existing' => ! empty( $_POST['update_existing'] ),
			'skip_errors'     => ! empty( $_POST['skip_errors'] ),
			'skip_duplicates' => ! empty( $_POST['skip_duplicates'] )
		];

		$results = CSV_Importer::process( $entity_type, $file['tmp_name'], $options );

		wp_send_json_success( $results );
	}

	/**
	 * Preview a legacy memorial CSV import.
	 *
	 * @since 2.0.0
	 */
	public static function preview_legacy_memorials(): void {
		check_ajax_referer( 'sd_process_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		$year = absint( $_POST['year'] ?? date( 'Y' ) );

		$preview = Legacy_Memorial_Parser::preview( $file['tmp_name'], $year );

		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( $preview->get_error_message() );
		}

		wp_send_json_success( $preview );
	}

	/**
	 * Process a legacy memorial CSV import.
	 *
	 * @since 2.0.0
	 */
	public static function import_legacy_memorials(): void {
		check_ajax_referer( 'sd_process_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		$year            = absint( $_POST['year'] ?? date( 'Y' ) );
		$skip_duplicates = ! empty( $_POST['skip_duplicates'] );
		$default_amount  = (float) ( $_POST['default_amount'] ?? 0 );

		$results = Legacy_Memorial_Parser::import(
			$file['tmp_name'],
			$year,
			$skip_duplicates,
			$default_amount
		);

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( $results->get_error_message() );
		}

		wp_send_json_success( $results );
	}

	/**
	 * Start a batched import — uploads file and returns session key.
	 *
	 * @since 2.1.0
	 */
	public static function start_batch_import(): void {
		check_ajax_referer( 'sd_process_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$file = $_FILES['file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload failed.', 'starter-shelter' ) );
		}

		$entity_type = sanitize_key( $_POST['import_type'] ?? '' );
		$config = Config::get_path( 'import-export', "entity_types.{$entity_type}" );
		if ( ! $config ) {
			wp_send_json_error( __( 'Invalid import type.', 'starter-shelter' ) );
		}

		$options = [
			'create_donors'   => ! empty( $_POST['create_donors'] ),
			'update_existing' => ! empty( $_POST['update_existing'] ),
			'skip_errors'     => ! empty( $_POST['skip_errors'] ),
			'skip_duplicates' => ! empty( $_POST['skip_duplicates'] ),
		];

		$result = CSV_Importer::start_import( $entity_type, $file['tmp_name'], $options );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Process one batch of an ongoing import.
	 *
	 * @since 2.1.0
	 */
	public static function process_batch(): void {
		check_ajax_referer( 'sd_process_import', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$import_key = sanitize_key( $_POST['import_key'] ?? '' );
		if ( ! $import_key ) {
			wp_send_json_error( __( 'Missing import key.', 'starter-shelter' ) );
		}

		$result = CSV_Importer::process_batch( $import_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Download the error CSV from a completed import.
	 *
	 * @since 2.1.0
	 */
	public static function download_error_csv(): void {
		check_ajax_referer( 'sd_download_error_csv', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Permission denied.', 'starter-shelter' ) );
		}

		$import_key = sanitize_key( $_GET['import_key'] ?? '' );
		if ( ! $import_key ) {
			wp_die( __( 'Missing import key.', 'starter-shelter' ) );
		}

		$csv = CSV_Importer::get_error_csv( $import_key );
		if ( ! $csv ) {
			wp_die( __( 'No error data found or session expired.', 'starter-shelter' ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=import-errors-' . wp_date( 'Y-m-d-His' ) . '.csv' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Download a CSV import template.
	 *
	 * Generates templates dynamically from live config so they include
	 * current allocations, tier slugs, memorial types, and species
	 * (including admin overrides from the Data tab). Includes an
	 * instructions row and realistic shelter-specific example data.
	 *
	 * @since 2.0.0
	 * @since 2.1.0 Dynamic generation from live config.
	 */
	public static function download_template(): void {
		check_ajax_referer( 'sd_download_template', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'starter-shelter' ) );
		}

		$type = sanitize_key( $_GET['type'] ?? '' );

		$import_config = Config::get_path( 'import-export', "entity_types.{$type}.import" );
		if ( ! $import_config ) {
			wp_die( esc_html__( 'Invalid template type.', 'starter-shelter' ) );
		}

		$headers = array_merge(
			$import_config['required'] ?? [],
			$import_config['optional'] ?? []
		);

		$filename = 'shelter-' . $type . '-import-template.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" );

		// Row 1: Column headers.
		fputcsv( $output, $headers );

		// Row 2: Instructions row — describes what each column expects.
		$instructions = self::build_instructions_row( $type, $headers, $import_config );
		fputcsv( $output, $instructions );

		// Rows 3+: Realistic example data from live config.
		$examples = self::build_example_rows( $type, $headers );
		foreach ( $examples as $example ) {
			fputcsv( $output, $example );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Build an instructions row for the template.
	 *
	 * Each cell describes the column's requirements: type, whether
	 * required, allowed values (from live config), and format hints.
	 *
	 * @since 2.1.0
	 *
	 * @param string $type      Entity type.
	 * @param array  $headers   Column headers.
	 * @param array  $config    Import config.
	 * @return array Instructions row.
	 */
	private static function build_instructions_row( string $type, array $headers, array $config ): array {
		$required  = $config['required'] ?? [];
		$field_map = $config['field_map'] ?? [];

		// Pull live enum values from config.
		$allocations   = array_keys( Config::get_item( 'settings', 'allocations', [] ) );
		$all_tiers     = Config::get_item( 'tiers', 'tiers', [] );
		$ind_tiers     = array_keys( $all_tiers['individual'] ?? [] );
		$biz_tiers     = array_keys( $all_tiers['business'] ?? [] );
		$all_tier_slugs = array_unique( array_merge( $ind_tiers, $biz_tiers ) );
		$species       = array_keys( Config::get_item( 'settings', 'pet_species', [] ) );
		$memorial_types = array_keys( Config::get_item( 'settings', 'memorial_types', [] ) );

		$instructions = [];
		foreach ( $headers as $col ) {
			$is_required = in_array( $col, $required, true );
			$prefix      = $is_required ? 'REQUIRED. ' : 'Optional. ';
			$mapping     = $field_map[ $col ] ?? [];
			$validate    = $mapping['validate'] ?? '';

			switch ( $col ) {
				case 'email':
					$instructions[] = $prefix . 'Valid email address.';
					break;
				case 'amount':
					$instructions[] = $prefix . 'Number (e.g. 50.00). Minimum $10.';
					break;
				case 'date':
				case 'start_date':
				case 'end_date':
					$instructions[] = $prefix . 'Date in YYYY-MM-DD format.';
					break;
				case 'allocation':
					$instructions[] = $prefix . 'One of: ' . implode( ', ', $allocations ) . '.';
					break;
				case 'tier':
					$instructions[] = $prefix . 'One of: ' . implode( ', ', $all_tier_slugs ) . '.';
					break;
				case 'membership_type':
					$instructions[] = $prefix . 'One of: individual, business.';
					break;
				case 'memorial_type':
					$instructions[] = $prefix . 'One of: pet, human, honor. (Matches memorial dashboard types.)';
					break;
				case 'pet_species':
					$instructions[] = 'Optional. One of: ' . implode( ', ', $species ) . '. Only for pet memorials.';
					break;
				case 'is_anonymous':
					$instructions[] = 'Optional. yes or no.';
					break;
				case 'honoree_name':
					$instructions[] = $prefix . 'Name of person or pet being honored.';
					break;
				case 'tribute_message':
					$instructions[] = 'Optional. Up to 500 characters.';
					break;
				case 'donor_display_name':
					$instructions[] = 'Optional. Name shown on the memorial wall (e.g. "The Smith Family"). Defaults to donor name.';
					break;
				case 'notify_family_name':
					$instructions[] = 'Optional. Family contact name for notification.';
					break;
				case 'notify_family_email':
					$instructions[] = 'Optional. Email to notify family of the tribute. Providing this enables family notification.';
					break;
				case 'notify_family_address':
					$instructions[] = 'Optional. Family mailing address for physical sympathy/tribute card.';
					break;
				case 'first_name':
					$instructions[] = $prefix . 'Donor first name.';
					break;
				case 'last_name':
					$instructions[] = $prefix . 'Donor last name.';
					break;
				case 'phone':
					$instructions[] = 'Optional. Phone number.';
					break;
				case 'business_name':
					$instructions[] = 'Optional. Required for business memberships.';
					break;
				case 'business_website':
					$instructions[] = 'Optional. Full URL (https://...).';
					break;
				case 'business_description':
					$instructions[] = 'Optional. Brief business description.';
					break;
				case 'dedication':
					$instructions[] = 'Optional. Dedication message.';
					break;
				default:
					if ( str_contains( $col, 'address' ) || in_array( $col, [ 'city', 'state', 'postal_code', 'country' ], true ) ) {
						$instructions[] = 'Optional. Mailing address field.';
					} else {
						$instructions[] = $prefix . 'Text.';
					}
					break;
			}
		}

		return $instructions;
	}

	/**
	 * Build realistic example rows from live config.
	 *
	 * Uses actual allocation slugs, tier slugs, and species from
	 * the current config (including admin overrides).
	 *
	 * @since 2.1.0
	 *
	 * @param string $type    Entity type.
	 * @param array  $headers Column headers.
	 * @return array Array of example rows.
	 */
	private static function build_example_rows( string $type, array $headers ): array {
		$allocations = array_keys( Config::get_item( 'settings', 'allocations', [] ) );
		$all_tiers   = Config::get_item( 'tiers', 'tiers', [] );
		$ind_tiers   = $all_tiers['individual'] ?? [];
		$species     = array_keys( Config::get_item( 'settings', 'pet_species', [] ) );

		$first_allocation = $allocations[0] ?? 'general-fund';
		$second_allocation = $allocations[1] ?? $first_allocation;
		$first_tier = array_key_first( $ind_tiers ) ?: 'single';
		$second_tier = array_keys( $ind_tiers )[1] ?? $first_tier;
		$first_species = $species[0] ?? 'dog';

		switch ( $type ) {
			case 'donations':
				return [
					self::map_example( $headers, [
						'email' => 'sarah.johnson@example.com', 'amount' => '50.00', 'date' => '2025-03-15',
						'first_name' => 'Sarah', 'last_name' => 'Johnson',
						'allocation' => $first_allocation, 'is_anonymous' => 'no', 'dedication' => '',
					] ),
					self::map_example( $headers, [
						'email' => 'mike.chen@example.com', 'amount' => '150.00', 'date' => '2025-03-20',
						'first_name' => 'Mike', 'last_name' => 'Chen',
						'allocation' => $second_allocation, 'is_anonymous' => 'no',
						'dedication' => 'In honor of all the wonderful animals you care for',
					] ),
					self::map_example( $headers, [
						'email' => 'anonymous@example.com', 'amount' => '500.00', 'date' => '2025-04-01',
						'first_name' => '', 'last_name' => '',
						'allocation' => $first_allocation, 'is_anonymous' => 'yes', 'dedication' => '',
					] ),
				];

			case 'memberships':
				$first_tier_data = $ind_tiers[ $first_tier ] ?? [];
				$second_tier_data = $ind_tiers[ $second_tier ] ?? [];
				$biz_tiers = $all_tiers['business'] ?? [];
				$biz_tier = array_key_first( $biz_tiers ) ?: 'contributing';
				$biz_tier_data = $biz_tiers[ $biz_tier ] ?? [];

				return [
					self::map_example( $headers, [
						'email' => 'jane.doe@example.com', 'membership_type' => 'individual',
						'tier' => $first_tier, 'amount' => (string) ( $first_tier_data['amount'] ?? 10 ),
						'start_date' => '2025-01-01', 'end_date' => '2026-01-01',
						'first_name' => 'Jane', 'last_name' => 'Doe',
					] ),
					self::map_example( $headers, [
						'email' => 'bob.smith@example.com', 'membership_type' => 'individual',
						'tier' => $second_tier, 'amount' => (string) ( $second_tier_data['amount'] ?? 25 ),
						'start_date' => '2025-02-15', 'end_date' => '2026-02-15',
						'first_name' => 'Bob', 'last_name' => 'Smith',
					] ),
					self::map_example( $headers, [
						'email' => 'info@petshop.com', 'membership_type' => 'business',
						'tier' => $biz_tier, 'amount' => (string) ( $biz_tier_data['amount'] ?? 50 ),
						'start_date' => '2025-03-01', 'end_date' => '2026-03-01',
						'first_name' => 'Lisa', 'last_name' => 'Park',
						'business_name' => 'Happy Tails Pet Shop',
						'business_website' => 'https://happytails.example.com',
						'business_description' => 'Local pet supplies and grooming',
					] ),
				];

			case 'memorials':
				return [
					// Person memorial — with donor display name, no family notification.
					self::map_example( $headers, [
						'email' => 'sarah.johnson@example.com', 'honoree_name' => 'Margaret Johnson',
						'amount' => '100.00', 'date' => '2025-03-10',
						'first_name' => 'Sarah', 'last_name' => 'Johnson',
						'memorial_type' => 'human',
						'tribute_message' => 'In loving memory of my mother who always loved animals.',
						'pet_species' => '',
						'is_anonymous' => 'no', 'donor_display_name' => 'Sarah Johnson',
						'notify_family_name' => '', 'notify_family_email' => '',
					] ),
					// Pet memorial — with species, family notification.
					self::map_example( $headers, [
						'email' => 'mike.chen@example.com', 'honoree_name' => 'Buddy',
						'amount' => '50.00', 'date' => '2025-03-15',
						'first_name' => 'Mike', 'last_name' => 'Chen',
						'memorial_type' => 'pet', 'pet_species' => $first_species,
						'tribute_message' => 'Our beloved Buddy brought us 12 years of joy.',
						'is_anonymous' => 'no', 'donor_display_name' => 'The Chen Family',
						'notify_family_name' => 'Chen Family', 'notify_family_email' => 'family.chen@example.com',
						'notify_family_address' => '789 Pine Street, Portland, OR 97201',
					] ),
					// In Honor Of — living person, anonymous.
					self::map_example( $headers, [
						'email' => 'lisa.park@example.com', 'honoree_name' => 'Dr. James Wilson',
						'amount' => '75.00', 'date' => '2025-04-01',
						'first_name' => 'Lisa', 'last_name' => 'Park',
						'memorial_type' => 'honor',
						'tribute_message' => 'Thank you for your years of dedicated veterinary care.',
						'pet_species' => '',
						'is_anonymous' => 'yes', 'donor_display_name' => '',
						'notify_family_name' => '', 'notify_family_email' => '',
					] ),
				];

			case 'donors':
				return [
					self::map_example( $headers, [
						'email' => 'sarah.johnson@example.com', 'first_name' => 'Sarah', 'last_name' => 'Johnson',
						'phone' => '555-123-4567', 'address_line_1' => '123 Oak Street', 'address_line_2' => '',
						'city' => 'Springfield', 'state' => 'IL', 'postal_code' => '62701', 'country' => 'US',
					] ),
					self::map_example( $headers, [
						'email' => 'mike.chen@example.com', 'first_name' => 'Mike', 'last_name' => 'Chen',
						'phone' => '555-987-6543', 'address_line_1' => '456 Elm Avenue', 'address_line_2' => 'Suite 200',
						'city' => 'Portland', 'state' => 'OR', 'postal_code' => '97201', 'country' => 'US',
					] ),
					self::map_example( $headers, [
						'email' => 'lisa.park@example.com', 'first_name' => 'Lisa', 'last_name' => 'Park',
						'phone' => '', 'address_line_1' => '789 Maple Drive', 'address_line_2' => '',
						'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US',
					] ),
				];

			default:
				return [];
		}
	}

	/**
	 * Map an example data array to match the header column order.
	 *
	 * @since 2.1.0
	 *
	 * @param array $headers Column headers.
	 * @param array $data    Example data keyed by column name.
	 * @return array Values in header order.
	 */
	private static function map_example( array $headers, array $data ): array {
		return array_map( function( $col ) use ( $data ) {
			return $data[ $col ] ?? '';
		}, $headers );
	}

	/**
	 * Get export record counts for the dashboard cards.
	 *
	 * @since 2.0.0
	 */
	public static function get_export_counts(): void {
		check_ajax_referer( 'sd_export_counts', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( self::get_record_counts() );
	}

	/**
	 * Get counts of all entity types for the export dashboard.
	 *
	 * @since 2.0.0
	 *
	 * @return array Entity counts.
	 */
	public static function get_record_counts(): array {
		global $wpdb;

		$today = wp_date( 'Y-m-d' );

		return [
			'donations' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'sd_donation' AND post_status = 'publish'"
			),
			'memberships' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'sd_membership' AND post_status = 'publish'"
			),
			'memberships_active' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_end_date'
				 WHERE p.post_type = 'sd_membership'
				   AND p.post_status = 'publish'
				   AND pm.meta_value >= %s",
				$today
			) ),
			'memberships_expired' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_end_date'
				 WHERE p.post_type = 'sd_membership'
				   AND p.post_status = 'publish'
				   AND pm.meta_value < %s",
				$today
			) ),
			'donors' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'sd_donor' AND post_status = 'publish'"
			),
			'memorials' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'sd_memorial' AND post_status = 'publish'"
			),
		];
	}
}
