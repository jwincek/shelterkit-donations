<?php
/**
 * Refunds and cancellations reach the gift records (#34).
 *
 * @package Starter_Shelter
 * @subpackage WooCommerce
 */

declare( strict_types = 1 );

namespace Starter_Shelter\WooCommerce;

use Starter_Shelter\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps donations, memberships and memorials in step with their order's
 * refunds and status.
 *
 * The plugin used to listen only for orders being paid, so a refunded gift
 * kept counting in Reports, Donor Statistics, the donor's lifetime giving
 * and the annual giving statement donors use for their taxes.
 *
 * - A record whose whole amount is refunded, or whose order is fully refunded
 *   or cancelled, gets the status "Refunded". It stays in the admin lists,
 *   and every total leaves it out: they all count published records only.
 * - A record refunded in part keeps counting at its net amount; its original
 *   amount and what was refunded are kept beside it.
 * - A refund that names no order lines is applied only when the order has a
 *   single line. Otherwise the order is flagged under Data Tools → Order
 *   Records, where an admin says which gift it returns.
 *
 * Each record's refund state is rebuilt from its order every time, never
 * adjusted step by step, so a deleted refund or an order moved back out of
 * Cancelled undoes exactly what it did, and running it over old orders
 * applies refunds made before this release.
 */
final class Refund_Handler {

	/** Post status of a refunded donation, membership or memorial. */
	public const STATUS = 'skdon_refunded';

	/** Record meta: what each refund returned of this gift, by refund ID. */
	public const REFUNDS_META = '_skdon_refunds';

	/** Record meta: total refunded, at most the original amount. */
	public const REFUNDED_META = '_skdon_refunded_amount';

	/** Record meta: the gift's amount before any refund. */
	public const ORIGINAL_AMOUNT_META = '_skdon_amount_original';

	/** Order meta: refunds waiting for an admin to say which gift they return. */
	public const REVIEW_META = '_skdon_refund_review';

	/** Order meta: the admin's answers, refund ID => record ID (0: not a gift). */
	public const ASSIGNMENTS_META = '_skdon_refund_assignments';

	/** Order statuses in which none of the order's gifts stands. */
	private const VOID_STATUSES = [ 'cancelled', 'refunded' ];

	/** Entry key for "the whole order is void". */
	private const WHOLE_ORDER = 'order';

	private const POST_TYPES = [ 'skdon_donation', 'skdon_membership', 'skdon_memorial' ];

	/**
	 * The status and how records in it display. Registered whether or not
	 * WooCommerce is active, so refunded records always read correctly.
	 */
	public static function register(): void {
		add_action( 'init', [ self::class, 'register_status' ] );
		add_filter( 'wp_insert_post_data', [ self::class, 'keep_refunded_status' ], 10, 2 );
		add_filter( 'display_post_states', [ self::class, 'post_state' ], 10, 2 );
	}

	/**
	 * Follow the order's refunds and status.
	 */
	public static function init(): void {
		add_action( 'woocommerce_order_refunded', [ self::class, 'sync_order' ] );
		add_action( 'woocommerce_order_status_changed', [ self::class, 'sync_order' ] );
		add_action( 'woocommerce_refund_deleted', [ self::class, 'sync_after_refund_deleted' ], 10, 2 );
	}

	/**
	 * Register the Refunded status.
	 *
	 * Not excluded from search: WP_Query's `post_status => 'any'` leaves out
	 * excluded statuses, and the lookups that find "the record this order line
	 * produced" use it. A refunded record they missed would be created again.
	 * Not public, so it never shows on the site; protected, because WP_Query
	 * lists a non-public status under the admin's "All" only when it is.
	 */
	public static function register_status(): void {
		register_post_status( self::STATUS, [
			'label'                     => _x( 'Refunded', 'gift status', 'shelterkit-donations' ),
			'public'                    => false,
			'protected'                 => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of refunded records. */
			'label_count'               => _n_noop( 'Refunded <span class="count">(%s)</span>', 'Refunded <span class="count">(%s)</span>', 'shelterkit-donations' ),
		] );
	}

	/**
	 * A refund was created, or the order's status changed.
	 *
	 * @param int $order_id The order.
	 */
	public static function sync_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::reconcile( $order );
		}
	}

	/**
	 * A refund was deleted from its order.
	 *
	 * @param int $refund_id The deleted refund.
	 * @param int $order_id  Its order.
	 */
	public static function sync_after_refund_deleted( $refund_id, $order_id ): void {
		self::sync_order( $order_id );
	}

	/**
	 * Rebuild the refund state of every record an order produced.
	 *
	 * @param \WC_Order $order The order.
	 * @return array{changed: int[], review: int[]} Records whose refunded
	 *         amount or status changed; refunds still waiting for review.
	 */
	public static function reconcile( \WC_Order $order ): array {
		$records = self::records_for_order( $order->get_id() );
		if ( ! $records && ! $order->get_meta( self::REVIEW_META ) && ! $order->get_meta( self::ASSIGNMENTS_META ) ) {
			// Most orders: no gifts, and nothing of ours to tidy up.
			return [
				'changed' => [],
				'review'  => [],
			];
		}

		$assignments = self::assignments( $order );
		$entries     = array_fill_keys( $records, [] );
		$review      = [];
		$refunded    = 0.0;
		$refund_ids  = [];

		foreach ( $order->get_refunds() as $refund ) {
			$refund_ids[] = $refund->get_id();
			$refunded    += abs( (float) $refund->get_amount() );
			if ( ! $records ) {
				continue;
			}

			$split = self::split_refund( $order, $refund, $records );
			if ( null === $split ) {
				if ( ! array_key_exists( $refund->get_id(), $assignments ) ) {
					$review[] = $refund->get_id();
					continue;
				}
				$record = $assignments[ $refund->get_id() ];
				$split  = isset( $entries[ $record ] ) ? [ $record => abs( (float) $refund->get_amount() ) ] : [];
			}
			foreach ( $split as $record => $amount ) {
				$entries[ $record ][ $refund->get_id() ] = round( $amount, 2 );
			}
		}

		// Cancelled, refunded, or every penny returned: no gift on it stands,
		// and there is nothing left to attribute.
		$total = (float) $order->get_total();
		if ( in_array( $order->get_status(), self::VOID_STATUSES, true ) || ( $total > 0 && $refunded + 0.005 >= $total ) ) {
			foreach ( $records as $record ) {
				$entries[ $record ][ self::WHOLE_ORDER ] = self::original_amount( $record );
			}
			$review = [];
		}

		$changed = [];
		foreach ( $records as $record ) {
			if ( self::settle( $record, $entries[ $record ], $order->get_id() ) ) {
				$changed[] = $record;
			}
		}

		self::save_review_state( $order, $review, array_intersect_key( $assignments, array_flip( $refund_ids ) ) );

		if ( $changed ) {
			/**
			 * Fires after refunds changed what an order's gifts count for.
			 *
			 * @param int   $order_id The order.
			 * @param int[] $changed  The records that changed.
			 */
			do_action( 'starter_shelter_refunds_changed', $order->get_id(), $changed );
		}

		return [
			'changed' => $changed,
			'review'  => $review,
		];
	}

	/**
	 * Say which gift a refund returns, for a refund that named no lines on an
	 * order with more than one. Record 0 means it returned no gift (shipping
	 * or another product, say).
	 *
	 * @param \WC_Order $order     The order.
	 * @param int       $refund_id A refund of that order.
	 * @param int       $record    One of the order's records, or 0.
	 * @return array{changed: int[], review: int[]}|\WP_Error
	 */
	public static function assign_refund( \WC_Order $order, int $refund_id, int $record ) {
		$refund_ids = array_map( static fn ( $refund ): int => $refund->get_id(), $order->get_refunds() );
		if ( ! in_array( $refund_id, $refund_ids, true ) ) {
			return new \WP_Error( 'not_a_refund', __( 'That refund does not belong to this order.', 'shelterkit-donations' ) );
		}
		if ( 0 !== $record && ! in_array( $record, self::records_for_order( $order->get_id() ), true ) ) {
			return new \WP_Error( 'not_a_record', __( 'That gift does not belong to this order.', 'shelterkit-donations' ) );
		}

		$assignments               = self::assignments( $order );
		$assignments[ $refund_id ] = $record;
		$order->update_meta_data( self::ASSIGNMENTS_META, $assignments );
		$order->save();

		return self::reconcile( $order );
	}

	/**
	 * Apply refunds and cancellations made before this release: rebuild every
	 * order that has a refund or is cancelled or refunded.
	 *
	 * @return array{orders: int, changed: int, review: int}
	 */
	public static function reconcile_all(): array {
		$order_ids = wc_get_orders( [
			'status' => self::VOID_STATUSES,
			'limit'  => -1,
			'return' => 'ids',
		] );
		foreach ( wc_get_orders( [ 'type' => 'shop_order_refund', 'limit' => -1 ] ) as $refund ) {
			$order_ids[] = $refund->get_parent_id();
		}

		$out = [ 'orders' => 0, 'changed' => 0, 'review' => 0 ];
		foreach ( array_unique( array_map( 'intval', $order_ids ) ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || ! self::records_for_order( $order_id ) ) {
				continue;
			}
			$result = self::reconcile( $order );
			++$out['orders'];
			$out['changed'] += count( $result['changed'] );
			$out['review']  += count( $result['review'] );
		}
		return $out;
	}

	/**
	 * What one refund returned of each record, or null when it cannot be
	 * told: no order lines named, and more than one line on the order.
	 *
	 * @param \WC_Order        $order   The order.
	 * @param \WC_Order_Refund $refund  The refund.
	 * @param int[]            $records The order's records.
	 * @return array<int, float>|null Record ID => amount.
	 */
	private static function split_refund( \WC_Order $order, \WC_Order_Refund $refund, array $records ): ?array {
		$lines = [];
		foreach ( $refund->get_items() as $refund_item ) {
			$item_id = (int) $refund_item->get_meta( '_refunded_item_id' );
			$amount  = abs( (float) $refund_item->get_total() );
			if ( $item_id > 0 && $amount > 0 ) {
				$lines[ $item_id ] = ( $lines[ $item_id ] ?? 0.0 ) + $amount;
			}
		}

		if ( $lines ) {
			// Lines that produced no record (another product) return no gift.
			$split = [];
			foreach ( $lines as $item_id => $amount ) {
				$item   = $order->get_item( $item_id );
				$record = $item ? Product_Mapper::line_record_id( $order, $item ) : 0;
				if ( in_array( $record, $records, true ) ) {
					$split[ $record ] = ( $split[ $record ] ?? 0.0 ) + $amount;
				}
			}
			return $split;
		}

		$amount = abs( (float) $refund->get_amount() );
		if ( $amount <= 0 ) {
			return [];
		}
		if ( 1 === count( $records ) && 1 === count( $order->get_items() ) ) {
			return [ $records[0] => $amount ];
		}
		return null;
	}

	/**
	 * Bring one record into line with its refund entries.
	 *
	 * @param int                      $record   Record post ID.
	 * @param array<int|string, float> $entries  What each refund returned.
	 * @param int                      $order_id Its order.
	 * @return bool Whether the refunded amount or the status changed.
	 */
	private static function settle( int $record, array $entries, int $order_id ): bool {
		$had_refunds = metadata_exists( 'post', $record, self::REFUNDS_META );
		if ( ! $entries && ! $had_refunds ) {
			return false;
		}

		$status_before   = (string) get_post_status( $record );
		$refunded_before = (float) get_post_meta( $record, self::REFUNDED_META, true );
		$original        = self::original_amount( $record );
		$refunded        = min( $original, (float) array_sum( $entries ) );
		$fully           = isset( $entries[ self::WHOLE_ORDER ] ) || ( $original > 0 && $refunded + 0.005 >= $original );

		if ( ! $entries ) {
			// Every refund against it is gone: it counts in full again.
			update_post_meta( $record, '_skdon_amount', $original );
			delete_post_meta( $record, self::REFUNDS_META );
			delete_post_meta( $record, self::REFUNDED_META );
			delete_post_meta( $record, self::ORIGINAL_AMOUNT_META );
			$refunded = 0.0;
		} else {
			update_post_meta( $record, self::REFUNDS_META, $entries );
			update_post_meta( $record, self::REFUNDED_META, $fully ? $original : $refunded );
			update_post_meta( $record, self::ORIGINAL_AMOUNT_META, $original );
			// A refunded record keeps its amount for the history; a partly
			// refunded one counts for what the shelter kept.
			update_post_meta( $record, '_skdon_amount', $fully ? $original : round( $original - $refunded, 2 ) );
			$refunded = $fully ? $original : $refunded;
		}

		self::set_status( $record, $fully ? self::STATUS : 'publish' );

		$changed = (string) get_post_status( $record ) !== $status_before || abs( $refunded - $refunded_before ) > 0.004;
		if ( ! $changed ) {
			return false;
		}

		$donor = (int) get_post_meta( $record, '_skdon_donor_id', true );
		if ( $donor ) {
			Helpers\recalculate_donor_lifetime_giving( $donor );
		}

		/**
		 * Fires after the refunded part of a donation, membership or memorial
		 * changed: refunded in part or in full, or a refund undone.
		 *
		 * @param int   $record   The record.
		 * @param int   $order_id Its order.
		 * @param float $refunded Refunded now, in total (0 when undone).
		 * @param float $original The gift's amount before any refund.
		 */
		do_action( 'starter_shelter_gift_refunded', $record, $order_id, $refunded, $original );

		return true;
	}

	/**
	 * Move a record between Published and Refunded. A direct update, so no
	 * save handler re-runs on a record that is only changing status: the
	 * memorial one would send its emails again. Other statuses are left be.
	 *
	 * @param int    $record Record post ID.
	 * @param string $status 'publish' or the Refunded status.
	 */
	private static function set_status( int $record, string $status ): void {
		$current = get_post_status( $record );
		if ( $current === $status || ! in_array( $current, [ 'publish', self::STATUS ], true ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_status' => $status ], [ 'ID' => $record ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the cache is cleared on the next line.
		clean_post_cache( $record );
	}

	/**
	 * Store which refunds wait for review, and note newly flagged ones on the
	 * order.
	 *
	 * @param \WC_Order       $order       The order.
	 * @param int[]           $review      Refunds waiting for review.
	 * @param array<int, int> $assignments Answers for refunds that still exist.
	 */
	private static function save_review_state( \WC_Order $order, array $review, array $assignments ): void {
		$before = array_map( 'intval', (array) $order->get_meta( self::REVIEW_META ) );
		$dirty  = false;

		if ( $review !== $before ) {
			if ( $review ) {
				$order->update_meta_data( self::REVIEW_META, $review );
			} else {
				$order->delete_meta_data( self::REVIEW_META );
			}
			foreach ( array_diff( $review, $before ) as $refund_id ) {
				$order->add_order_note( sprintf(
					/* translators: %d: refund ID. */
					__( 'ShelterKit Donations: refund #%d names no order lines, and this order has more than one, so it isn\'t clear which gift it returns. Choose one under ShelterKit Donations → Data Tools → Order Records.', 'shelterkit-donations' ),
					$refund_id
				) );
			}
			$dirty = true;
		}

		if ( $assignments !== self::assignments( $order ) ) {
			if ( $assignments ) {
				$order->update_meta_data( self::ASSIGNMENTS_META, $assignments );
			} else {
				$order->delete_meta_data( self::ASSIGNMENTS_META );
			}
			$dirty = true;
		}

		if ( $dirty ) {
			// Saving fires woocommerce_order_status_changed only on a status
			// change, which this is not, so this cannot recurse.
			$order->save();
			do_action( 'starter_shelter_refunds_changed', $order->get_id(), [] );
		}
	}

	/**
	 * The admin's answers for an order's unattributed refunds.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<int, int> Refund ID => record ID, or 0 for "not a gift".
	 */
	private static function assignments( \WC_Order $order ): array {
		$out = [];
		foreach ( (array) $order->get_meta( self::ASSIGNMENTS_META ) as $refund_id => $record ) {
			$out[ (int) $refund_id ] = (int) $record;
		}
		return $out;
	}

	/**
	 * A record's amount before any refund.
	 *
	 * @param int $record Record post ID.
	 * @return float
	 */
	public static function original_amount( int $record ): float {
		$original = get_post_meta( $record, self::ORIGINAL_AMOUNT_META, true );
		return '' !== $original ? (float) $original : (float) get_post_meta( $record, '_skdon_amount', true );
	}

	/**
	 * The records an order produced, refunded or not.
	 *
	 * @param int $order_id The order.
	 * @return int[]
	 */
	public static function records_for_order( int $order_id ): array {
		if ( $order_id <= 0 ) {
			return [];
		}
		return array_map( 'intval', get_posts( [
			'post_type'   => self::POST_TYPES,
			'post_status' => [ 'publish', self::STATUS ],
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'fields'      => 'ids',
			'meta_key'    => '_skdon_wc_order_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one order.
			'meta_value'  => $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one order.
		] ) );
	}

	/**
	 * Saving a refunded record in the editor must not quietly publish it
	 * again: the editor's only button for a record in a custom status is
	 * Publish. A refund is undone on its order, which brings the record back.
	 *
	 * @param array $data    Post data about to be saved.
	 * @param array $postarr Raw post data, with the ID.
	 * @return array
	 */
	public static function keep_refunded_status( $data, $postarr ) {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id && in_array( $data['post_type'] ?? '', self::POST_TYPES, true )
			&& 'publish' === ( $data['post_status'] ?? '' ) && self::STATUS === get_post_status( $id ) ) {
			$data['post_status'] = self::STATUS;
		}
		return $data;
	}

	/**
	 * Show "Refunded" beside the title in the record lists.
	 *
	 * @param array    $states Post states.
	 * @param \WP_Post $post   The post.
	 * @return array
	 */
	public static function post_state( $states, $post ) {
		if ( $post instanceof \WP_Post && self::STATUS === $post->post_status ) {
			$states[ self::STATUS ] = _x( 'Refunded', 'gift status', 'shelterkit-donations' );
		}
		return $states;
	}
}
