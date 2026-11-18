<?php
/**
 * Field-level changes to donations, memberships, memorials and donors (#36).
 *
 * @package Starter_Shelter
 * @subpackage Admin
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Records what changed on an existing record, by whom, from where.
 *
 * Three memberships on the development site changed type, tier and amount
 * with no trace: only their "created" entries showed what they had been.
 * This watches the plugin's own meta on its own records and writes one
 * entry per record per request (or per record per tool run), listing each
 * field with its old and new value.
 *
 * Left out, because they are not edits:
 * - records created in the same request (their "created" entry covers them);
 * - totals, dates and flags the plugin maintains itself (AUTOMATIC);
 * - a record another entry already describes in the same request, such as
 *   a refund, a cancellation or an approved logo (DESCRIBING_EVENTS).
 *
 * A donor's values are personal data, so a donor entry names the fields
 * that changed without their values.
 */
final class Record_Edit_Tracker {

	/** Post types whose meta is watched. */
	public const POST_TYPES = [ 'skdon_donation', 'skdon_membership', 'skdon_memorial', 'skdon_donor' ];

	/**
	 * Fields the plugin writes on its own: running totals, processing
	 * flags, import and sync bookkeeping, moderation state that has its own
	 * entries, and refund state (#34).
	 */
	private const AUTOMATIC = [
		'ability_result', 'address_last_updated', 'address_update_source', 'amount_original', 'candle_count',
		'candles', 'created_date', 'current_membership_id', 'donation_count', 'donor_level',
		'family_notified_date', 'first_donation_date', 'first_gift_date', 'import_hash', 'import_line',
		'import_source', 'is_member', 'last_donation_date', 'last_gift_date', 'legacy_sync_results',
		'legacy_synced', 'lifetime_giving', 'logo_approved_by', 'logo_approved_date', 'logo_rejected_by',
		'logo_rejected_date', 'logo_rejection_reason', 'logo_status', 'processed', 'processing_results',
		'refunded_amount', 'refunds', 'reminder_sent_date', 'renewal_orders', 'renewal_reminder_sent',
		'wc_order_item_id',
	];

	/** Entries that describe a change to their record themselves. */
	public const DESCRIBING_EVENTS = [
		'membership_cancelled', 'membership_extended', 'membership_renewed', 'logo_approved', 'logo_rejected',
		'family_notified', 'gift_refunded', 'gift_partly_refunded', 'gift_refund_undone',
		'donor_profile_updated', 'donor_address_updated',
	];

	/** Longest value kept, in characters. */
	private const MAX_VALUE = 100;

	/**
	 * Changes waiting to be written, by record.
	 *
	 * @var array<int, array{run: ?string, changes: array<string, array{0: mixed, 1: mixed}>}>
	 */
	private static array $pending = [];

	/**
	 * Records created in this request.
	 *
	 * @var array<int, true>
	 */
	private static array $created = [];

	/**
	 * Records another entry has described.
	 *
	 * @var array<int, true>
	 */
	private static array $described = [];

	/**
	 * Watch meta writes, and write what changed when the request ends.
	 */
	public static function init(): void {
		// Last, so a filter that short-circuits the write runs first and a
		// write that never happens is never recorded.
		add_filter( 'add_post_metadata', [ self::class, 'on_add' ], PHP_INT_MAX, 4 );
		add_filter( 'update_post_metadata', [ self::class, 'on_update' ], PHP_INT_MAX, 4 );
		add_filter( 'delete_post_metadata', [ self::class, 'on_delete' ], PHP_INT_MAX, 3 );
		add_action( 'wp_insert_post', [ self::class, 'on_insert' ], 10, 3 );
		add_action( 'shutdown', [ self::class, 'flush' ] );
	}

	/**
	 * A record created now is not edited by the meta written for it.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Whether this updated an existing post.
	 */
	public static function on_insert( $post_id, $post, $update ): void {
		if ( ! $update && $post instanceof \WP_Post && in_array( $post->post_type, self::POST_TYPES, true ) ) {
			self::$created[ (int) $post_id ] = true;
		}
	}

	/**
	 * A field being added.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value being added.
	 * @return mixed
	 */
	public static function on_add( $check, $object_id, $meta_key, $meta_value ) {
		if ( null === $check ) {
			self::note( (int) $object_id, (string) $meta_key, $meta_value, true );
		}
		return $check;
	}

	/**
	 * A field being changed.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return mixed
	 */
	public static function on_update( $check, $object_id, $meta_key, $meta_value ) {
		if ( null === $check ) {
			self::note( (int) $object_id, (string) $meta_key, $meta_value );
		}
		return $check;
	}

	/**
	 * A field being removed.
	 *
	 * @param mixed  $check     Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return mixed
	 */
	public static function on_delete( $check, $object_id, $meta_key ) {
		if ( null === $check ) {
			self::note( (int) $object_id, (string) $meta_key, null, false, true );
		}
		return $check;
	}

	/**
	 * Note one field's change on a watched record.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $new     New value (null when deleted).
	 * @param bool   $added   Whether the key is being added.
	 * @param bool   $deleted Whether the key is being deleted.
	 */
	private static function note( int $post_id, string $key, $new, bool $added = false, bool $deleted = false ): void {
		if ( ! str_starts_with( $key, '_skdon_' ) || isset( self::$created[ $post_id ] ) || isset( self::$described[ $post_id ] ) ) {
			return;
		}
		$field = substr( $key, 7 );
		if ( in_array( $field, self::AUTOMATIC, true ) || ! in_array( get_post_type( $post_id ), self::POST_TYPES, true ) ) {
			return;
		}

		$old = ( $added && ! metadata_exists( 'post', $post_id, $key ) ) ? null : get_post_meta( $post_id, $key, true );
		$new = $deleted ? null : $new;

		if ( ! isset( self::$pending[ $post_id ] ) ) {
			self::$pending[ $post_id ] = [
				'run'     => Activity_Log::current_run(),
				'changes' => [],
			];
		}
		// Keep the value from before the first change and after the last.
		$first = self::$pending[ $post_id ]['changes'][ $field ][0] ?? $old;
		self::$pending[ $post_id ]['changes'][ $field ] = [ $first, $new ];
	}

	/**
	 * Another entry described this record: don't also write an edit for it.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function describe( int $post_id ): void {
		self::$described[ $post_id ] = true;
		unset( self::$pending[ $post_id ] );
	}

	/**
	 * Write the pending changes, one entry per record. Called when a run is
	 * left or finished (only that run's changes) and at shutdown (the rest).
	 *
	 * @param string|null $run Only the changes made in this run, or every change when omitted.
	 */
	public static function flush( $run = null ): void {
		$only_run = is_string( $run ) && '' !== $run ? $run : null;

		foreach ( self::$pending as $post_id => $entry ) {
			if ( null !== $only_run && $entry['run'] !== $only_run ) {
				continue;
			}
			unset( self::$pending[ $post_id ] );

			// wp_insert_post() writes meta_input before any hook knows the
			// new ID, so a new record's first fields are noted before it is
			// known to be new. By now it is.
			if ( isset( self::$created[ $post_id ] ) ) {
				continue;
			}

			$type = get_post_type( $post_id );
			if ( ! $type ) {
				continue; // Deleted since; its deletion has an entry of its own.
			}

			$changes = [];
			foreach ( $entry['changes'] as $field => [ $old, $new ] ) {
				if ( self::same( $old, $new ) ) {
					continue;
				}
				$changes[ $field ] = 'skdon_donor' === $type
					? null // Personal data: which field, not its value.
					: [ self::shorten( $old ), self::shorten( $new ) ];
			}
			if ( ! $changes ) {
				continue;
			}

			Activity_Log::record_in_run(
				$entry['run'],
				'record_edited',
				'skdon_membership' === $type ? 'membership' : ( 'skdon_donor' === $type ? 'admin' : 'donation' ),
				[ 'changes' => $changes ],
				$type,
				(int) $post_id
			);
		}
	}

	/**
	 * Forget everything noted. For tests, which run many "requests" in one.
	 */
	public static function reset(): void {
		self::$pending   = [];
		self::$created   = [];
		self::$described = [];
	}

	/**
	 * Whether two stored values are the same once stored.
	 *
	 * @param mixed $a A value.
	 * @param mixed $b Another.
	 */
	private static function same( $a, $b ): bool {
		// Empty, "0", false and null all mean "nothing here": an editor
		// saving 0 into a field that had no value is not an edit.
		$norm = static function ( $v ): string {
			if ( null === $v || false === $v || '' === $v || '0' === $v || 0 === $v ) {
				return '';
			}
			return is_scalar( $v ) ? (string) $v : (string) maybe_serialize( $v );
		};
		return $norm( $a ) === $norm( $b );
	}

	/**
	 * A value as the log keeps it: scalars as text, cut to MAX_VALUE.
	 *
	 * @param mixed $value A value.
	 * @return string|null
	 */
	private static function shorten( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		return mb_strlen( $text ) > self::MAX_VALUE ? mb_substr( $text, 0, self::MAX_VALUE - 1 ) . '…' : $text;
	}
}
