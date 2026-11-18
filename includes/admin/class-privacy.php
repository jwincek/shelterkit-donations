<?php
/**
 * Tools → Export / Erase Personal Data for donors (#38).
 *
 * @package Starter_Shelter
 * @subpackage Admin
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin with WordPress's personal-data tools.
 *
 * - Export: a donor's profile and gifts, and the activity log entries about
 *   them or addressed to them.
 * - Erase: the activity log's copy of their details. The entries stay, with
 *   the donor and the address removed, so the log still says what happened.
 *   Donor and gift records are financial records and are kept; the eraser
 *   says so, and an admin can delete the donor record if it must go.
 *
 * A person is found by email: donor records with that address, and the donor
 * record linked to a WordPress user with that address.
 */
final class Privacy {

	/** Entries handled per call; WordPress calls again until done. */
	private const PAGE = 100;

	/** Hook into the personal-data tools. */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_erasers' ] );
	}

	/**
	 * Add the donor and activity-log exporters.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporters( $exporters ) {
		$exporters['shelterkit-donations-donor']    = [
			'exporter_friendly_name' => __( 'ShelterKit Donations: donor profile and gifts', 'shelterkit-donations' ),
			'callback'               => [ self::class, 'export_donor' ],
		];
		$exporters['shelterkit-donations-activity'] = [
			'exporter_friendly_name' => __( 'ShelterKit Donations: activity log', 'shelterkit-donations' ),
			'callback'               => [ self::class, 'export_activity' ],
		];
		return $exporters;
	}

	/**
	 * Add the activity-log eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_erasers( $erasers ) {
		$erasers['shelterkit-donations-activity'] = [
			'eraser_friendly_name' => __( 'ShelterKit Donations: activity log', 'shelterkit-donations' ),
			'callback'             => [ self::class, 'erase_activity' ],
		];
		return $erasers;
	}

	/**
	 * The donor records for an email address.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	public static function donor_ids( string $email ): array {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return [];
		}
		$ids = get_posts( [
			'post_type'   => 'skdon_donor',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_skdon_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one address, on an admin request.
			'meta_value'  => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one address, on an admin request.
		] );
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$ids = array_merge( $ids, get_posts( [
				'post_type'   => 'skdon_donor',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_skdon_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one user, on an admin request.
				'meta_value'  => $user->ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one user, on an admin request.
			] ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Export a donor's profile and gifts.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public static function export_donor( $email, $page = 1 ): array {
		$donors = self::donor_ids( (string) $email );
		$page   = max( 1, (int) $page );
		$items  = [];

		if ( 1 === $page ) {
			foreach ( $donors as $donor ) {
				$meta    = static fn ( string $key ): string => (string) get_post_meta( $donor, '_skdon_' . $key, true );
				$address = implode( ', ', array_filter( [ $meta( 'address_line_1' ), $meta( 'address_line_2' ), $meta( 'city' ), $meta( 'state' ), $meta( 'postal_code' ), $meta( 'country' ) ] ) );
				$items[] = [
					'group_id'    => 'shelterkit-donor',
					'group_label' => __( 'Donor profile', 'shelterkit-donations' ),
					'item_id'     => 'skdon-donor-' . $donor,
					'data'        => self::pairs( [
						__( 'Name', 'shelterkit-donations' )            => $meta( 'display_name' ) ?: get_the_title( $donor ),
						__( 'First name', 'shelterkit-donations' )      => $meta( 'first_name' ),
						__( 'Last name', 'shelterkit-donations' )       => $meta( 'last_name' ),
						__( 'Email', 'shelterkit-donations' )           => $meta( 'email' ),
						__( 'Phone', 'shelterkit-donations' )           => $meta( 'phone' ),
						__( 'Address', 'shelterkit-donations' )         => $address,
						__( 'Lifetime giving', 'shelterkit-donations' ) => $meta( 'lifetime_giving' ),
					] ),
				];
			}
		}

		$gifts = $donors ? get_posts( [
			'post_type'      => [ 'skdon_donation', 'skdon_membership', 'skdon_memorial' ],
			'post_status'    => 'any',
			'posts_per_page' => self::PAGE,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => [ [ 'key' => '_skdon_donor_id', 'value' => $donors, 'compare' => 'IN' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one person's records, on an admin request.
		] ) : [];

		foreach ( $gifts as $gift ) {
			$meta    = static fn ( string $key ): string => (string) get_post_meta( $gift->ID, '_skdon_' . $key, true );
			$items[] = [
				'group_id'    => 'shelterkit-gifts',
				'group_label' => __( 'Gifts', 'shelterkit-donations' ),
				'item_id'     => 'skdon-gift-' . $gift->ID,
				'data'        => self::pairs( [
					__( 'Type', 'shelterkit-donations' )   => get_post_type_object( $gift->post_type )->labels->singular_name ?? $gift->post_type,
					__( 'Date', 'shelterkit-donations' )   => $meta( 'donation_date' ) ?: $meta( 'start_date' ) ?: $gift->post_date,
					__( 'Amount', 'shelterkit-donations' ) => $meta( 'amount' ),
					__( 'Fund', 'shelterkit-donations' )   => $meta( 'allocation' ),
					__( 'Tier', 'shelterkit-donations' )   => $meta( 'tier' ),
					__( 'In memory of', 'shelterkit-donations' ) => $meta( 'honoree_name' ),
					__( 'Tribute', 'shelterkit-donations' ) => $meta( 'tribute_message' ),
					__( 'Order', 'shelterkit-donations' )  => $meta( 'wc_order_id' ) ?: '',
					__( 'Status', 'shelterkit-donations' ) => $gift->post_status,
				] ),
			];
		}

		return [
			'data' => $items,
			'done' => count( $gifts ) < self::PAGE,
		];
	}

	/**
	 * Export the activity log entries about a person.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public static function export_activity( $email, $page = 1 ): array {
		$rows  = self::activity_rows( (string) $email, max( 1, (int) $page ) );
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'group_id'    => 'shelterkit-activity',
				'group_label' => __( 'Activity log', 'shelterkit-donations' ),
				'item_id'     => 'skdon-activity-' . $row->id,
				'data'        => self::pairs( [
					__( 'Date', 'shelterkit-donations' )     => (string) $row->created_at,
					__( 'Activity', 'shelterkit-donations' ) => Activity_Log::render_message( $row ),
					__( 'Source', 'shelterkit-donations' )   => (string) $row->source,
				] ),
			];
		}
		return [
			'data' => $items,
			'done' => count( $rows ) < self::PAGE,
		];
	}

	/**
	 * Remove a person's details from the activity log. Each entry stays, so
	 * the log still says what happened; it no longer says to whom.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Unused: anonymised entries stop matching.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_activity( $email, $page = 1 ): array {
		global $wpdb;
		$email  = (string) $email;
		$donors = self::donor_ids( $email );
		$names  = self::names( $donors );
		// Always the first page of what is left: an anonymised entry no
		// longer matches, so WordPress's next call ($page + 1) moves on.
		$page = 1;
		$rows = self::activity_rows( $email, $page, true );

		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row->meta, true ) ?: [];
			if ( isset( $data['donor_id'] ) && in_array( (int) $data['donor_id'], $donors, true ) ) {
				$data['donor_id'] = 0;
			}
			foreach ( [ 'recipient', 'family_email' ] as $key ) {
				if ( isset( $data[ $key ] ) && 0 === strcasecmp( (string) $data[ $key ], $email ) ) {
					$data[ $key ] = '';
				}
			}
			$data['anonymized'] = true;

			// Entries written before #37 kept a finished sentence with the
			// person's name or address in it.
			$message = (string) $row->message;
			if ( '' !== $message ) {
				$message = str_ireplace( array_merge( [ $email ], $names ), __( '[removed]', 'shelterkit-donations' ), $message );
			}

			$wpdb->update(
				$wpdb->prefix . 'skdon_activity_log',
				[ 'meta' => wp_json_encode( $data ), 'message' => $message, 'ip_address' => null ],
				[ 'id' => (int) $row->id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
		}

		return [
			'items_removed'  => (bool) $rows,
			'items_retained' => (bool) $donors,
			'messages'       => $donors ? [
				__( 'ShelterKit Donations keeps donor and gift records as financial records. To remove them, delete the donor under ShelterKit Donations → All Donors.', 'shelterkit-donations' ),
			] : [],
			'done'           => count( $rows ) < self::PAGE,
		];
	}

	/**
	 * Activity log entries naming a person's donor records or their address.
	 *
	 * @param string $email              Email address.
	 * @param int    $page               Page, from 1.
	 * @param bool   $exclude_anonymized Leave out entries already anonymised.
	 * @return object[]
	 */
	private static function activity_rows( string $email, int $page, bool $exclude_anonymized = false ): array {
		global $wpdb;
		$table   = $wpdb->prefix . 'skdon_activity_log';
		$clauses = [];
		$params  = [];
		foreach ( self::donor_ids( $email ) as $donor ) {
			$clauses[] = '(object_type = %s AND object_id = %d)';
			array_push( $params, 'skdon_donor', $donor );
			foreach ( [ ',', '}' ] as $end ) {
				$clauses[] = 'meta LIKE %s';
				$params[]  = '%' . $wpdb->esc_like( '"donor_id":' . $donor . $end ) . '%';
			}
		}
		$email = sanitize_email( $email );
		if ( '' !== $email ) {
			$clauses[] = 'meta LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( '"' . $email . '"' ) . '%';
			$clauses[] = 'message LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( $email ) . '%';
		}
		if ( ! $clauses ) {
			return [];
		}
		$where = '(' . implode( ' OR ', $clauses ) . ')';
		if ( $exclude_anonymized ) {
			$where   .= ' AND ( meta IS NULL OR meta NOT LIKE %s )';
			$params[] = '%' . $wpdb->esc_like( '"anonymized":true' ) . '%';
		}
		$params[] = self::PAGE;
		$params[] = ( $page - 1 ) * self::PAGE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the table name is $wpdb->prefix plus a constant; $where is built from fixed clauses whose placeholders $params fills, in order.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY id ASC LIMIT %d OFFSET %d", $params ) );
	}

	/**
	 * The names a person's donor records carry, longest first, so "Pat Donor"
	 * is replaced before "Pat".
	 *
	 * @param int[] $donors Donor IDs.
	 * @return string[]
	 */
	private static function names( array $donors ): array {
		$names = [];
		foreach ( $donors as $donor ) {
			$first   = (string) get_post_meta( $donor, '_skdon_first_name', true );
			$last    = (string) get_post_meta( $donor, '_skdon_last_name', true );
			$names[] = (string) get_post_meta( $donor, '_skdon_display_name', true );
			$names[] = get_the_title( $donor );
			$names[] = trim( $first . ' ' . $last );
		}
		$names = array_values( array_unique( array_filter( array_map( 'trim', $names ), static fn ( $n ) => mb_strlen( $n ) >= 3 ) ) );
		usort( $names, static fn ( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		return $names;
	}

	/**
	 * Name/value pairs for an export item, leaving out empty values.
	 *
	 * @param array<string, string> $values Label => value.
	 * @return array<int, array{name: string, value: string}>
	 */
	private static function pairs( array $values ): array {
		$pairs = [];
		foreach ( $values as $name => $value ) {
			if ( '' !== (string) $value ) {
				$pairs[] = [ 'name' => (string) $name, 'value' => (string) $value ];
			}
		}
		return $pairs;
	}
}
