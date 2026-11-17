<?php
/**
 * Find and repair records Legacy Order Sync merged (#40).
 *
 * @package Starter_Shelter
 * @subpackage Admin\Legacy_Sync
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Legacy_Sync;

use Starter_Shelter\Helpers;
use Starter_Shelter\WooCommerce\Product_Mapper;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Until #40, Legacy Order Sync matched "the record this line already
 * produced" by order rather than by line. An order with two lines of the same
 * kind (an individual and a business membership, two gifts to different
 * funds) had its second line overwrite the record the first line had just
 * created, and a memorial could be taken over by another order from the same
 * donor for the same honoree.
 *
 * The sync stored every line's outcome on its order, so the damage is
 * recorded: a line reported as `updated` into a record that a different line
 * reported as `created`. Repairing one gives the record back to the line that
 * created it and gives the other line its own record.
 */
final class Merge_Repair {

	/**
	 * Every merged line found in the stored sync results.
	 *
	 * @return array<int, array{record: int, type: string, order_id: int, item_id: int, by_order: int, by_item: int}>
	 *         `order_id`/`item_id` is the line that created the record (its
	 *         owner); `by_order`/`by_item` the line that overwrote it and has
	 *         no record of its own.
	 */
	public static function find(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$order_ids = Helpers\order_ids_with_meta( Order_Scanner::RESULTS_META_KEY );

		$creators = [];
		$updates  = [];
		foreach ( $order_ids as $order_id ) {
			$order   = wc_get_order( $order_id );
			$results = $order ? $order->get_meta( Order_Scanner::RESULTS_META_KEY ) : [];
			foreach ( is_array( $results ) ? $results : [] as $item_id => $result ) {
				$record = is_array( $result ) ? Product_Mapper::record_id( $result ) : 0;
				if ( ! $record ) {
					continue;
				}
				$action = $result['action'] ?? '';
				if ( 'created' === $action ) {
					$creators[ $record ] = [ (int) $order_id, (int) $item_id ];
				} elseif ( 'updated' === $action ) {
					$updates[] = [ $record, (int) $order_id, (int) $item_id ];
				}
			}
		}

		$merged = [];
		foreach ( $updates as [ $record, $order_id, $item_id ] ) {
			$owner = $creators[ $record ] ?? null;
			// A line updating the record it created itself (a re-sync) is fine.
			if ( ! $owner || [ $order_id, $item_id ] === $owner ) {
				continue;
			}
			$merged[] = [
				'record'   => $record,
				'type'     => (string) get_post_type( $record ),
				'order_id' => $owner[0],
				'item_id'  => $owner[1],
				'by_order' => $order_id,
				'by_item'  => $item_id,
			];
		}

		return $merged;
	}

	/**
	 * Repair every merged line.
	 *
	 * @return array{repaired: array<int, array{record: int, new_record: int}>, failed: array<int, string>}
	 */
	public static function repair_all(): array {
		$out = [ 'repaired' => [], 'failed' => [] ];
		foreach ( self::find() as $merge ) {
			$result = self::repair( $merge );
			if ( is_wp_error( $result ) ) {
				$out['failed'][] = sprintf( '#%d: %s', $merge['record'], $result->get_error_message() );
			} else {
				$out['repaired'][] = $result;
			}
		}
		return $out;
	}

	/**
	 * Give a merged record back to the line that created it, and give the
	 * line that overwrote it a record of its own.
	 *
	 * Runs as internal processing, like the sync itself, so no donor is
	 * emailed about a gift made long ago.
	 *
	 * @param array $merge One entry from find().
	 * @return array{record: int, new_record: int}|WP_Error
	 */
	public static function repair( array $merge ) {
		$owner_order = wc_get_order( $merge['order_id'] );
		$by_order    = wc_get_order( $merge['by_order'] );
		if ( ! $owner_order || ! $by_order ) {
			return new WP_Error( 'order_missing', __( 'One of the orders no longer exists.', 'shelterkit-donations' ) );
		}

		$owner_line = Order_Processor::classify_lines( $owner_order )[ $merge['item_id'] ] ?? null;
		$by_line    = Order_Processor::classify_lines( $by_order )[ $merge['by_item'] ] ?? null;
		if ( ! $owner_line || ! $owner_line['config'] || ! $by_line || ! $by_line['config'] ) {
			return new WP_Error( 'line_missing', __( 'One of the order lines no longer exists or is no longer recognised.', 'shelterkit-donations' ) );
		}

		$product_type = (string) ( $owner_line['config']['product_type'] ?? '' );
		$ability      = function_exists( 'wp_get_ability' ) ? wp_get_ability( (string) ( $by_line['config']['ability'] ?? '' ) ) : null;
		if ( ! $ability ) {
			return new WP_Error( 'ability_missing', __( 'The ability that creates this record could not be loaded.', 'shelterkit-donations' ) );
		}

		Helpers\set_internal_processing( true );
		try {
			// 1. The record goes back to the line that created it.
			[ $owner_donor ] = Order_Processor::resolve_donor( $owner_order );
			$owner_input     = Order_Processor::build_line_input( $owner_order, $owner_line['item'], $owner_line['product'], $owner_line['data'], $owner_line['config'], $owner_donor );
			$restored        = Order_Processor::update_existing_record( $merge['record'], $product_type, $owner_input );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
			update_post_meta( $merge['record'], Product_Mapper::ORDER_ITEM_META, $merge['item_id'] );

			// 2. The line that overwrote it gets its own record.
			[ $by_donor ] = Order_Processor::resolve_donor( $by_order );
			$by_input     = Order_Processor::build_line_input( $by_order, $by_line['item'], $by_line['product'], $by_line['data'], $by_line['config'], $by_donor );
			$created      = $ability->execute( $by_input );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			Product_Mapper::stamp_order_line( $created, $merge['by_item'] );
		} finally {
			Helpers\set_internal_processing( false );
		}

		$new_record = Product_Mapper::record_id( $created );

		// 3. Record the outcome where the sync recorded the merge, so a second
		// scan finds nothing, and leave a note on the order.
		$results                       = (array) $by_order->get_meta( Order_Scanner::RESULTS_META_KEY );
		$results[ $merge['by_item'] ] = array_merge(
			is_array( $created ) ? $created : [],
			[ 'action' => 'created', 'repaired_from' => $merge['record'] ]
		);
		$by_order->update_meta_data( Order_Scanner::RESULTS_META_KEY, $results );
		$by_order->add_order_note( sprintf(
			/* translators: 1: order line name; 2: the record it had overwritten; 3: its new record. */
			__( 'ShelterKit Donations: Legacy Order Sync had recorded "%1$s" over record #%2$d, which belongs to another line. #%2$d is restored, and this line now has its own record, #%3$d.', 'shelterkit-donations' ),
			$by_line['item']->get_name(),
			$merge['record'],
			$new_record
		) );
		$by_order->save();

		return [
			'record'     => $merge['record'],
			'new_record' => $new_record,
		];
	}
}
