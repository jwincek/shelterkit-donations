<?php
/**
 * Order Processor - Syncs a single WooCommerce order via abilities.
 *
 * Extracted from Legacy_Order_Sync methods:
 * - sync_single_order() (lines 1331-1512)
 * - find_existing_record() (lines 1646-1733)
 * - update_existing_record() (lines 1774-1849)
 * - find_donor_by_email() (lines 1743-1762) → replaced by Donor_Lookup
 *
 * This is the only class in the legacy sync system that has side effects.
 * It creates/updates posts via abilities and marks orders as synced.
 *
 * Like the revised CSV_Importer, this calls abilities directly (not through
 * AJAX) and uses Donor_Lookup to resolve donors once, passing donor_id
 * to the ability.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Legacy_Sync
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Legacy_Sync;

use Starter_Shelter\Core\Config;
use Starter_Shelter\Helpers;
use Starter_Shelter\WooCommerce\Product_Mapper;
use Starter_Shelter\WooCommerce\Refund_Handler;
use Starter_Shelter\Admin\Shared\Donor_Lookup;
use WP_Error;

/**
 * Processes a single WooCommerce order into shelter records.
 *
 * @since 2.0.0
 */
class Order_Processor {

	/**
	 * Sync a single order, creating shelter records for each line item.
	 *
	 * @since 2.0.0
	 *
	 * @param int  $order_id     The WooCommerce order ID.
	 * @param bool $dry_run      If true, count what would be created but don't create.
	 * @param bool $force_resync If true, resync even if already marked synced.
	 * @return array|WP_Error Sync results or error.
	 */
	public static function sync( int $order_id, bool $dry_run = false, bool $force_resync = false ): array|WP_Error {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'order_not_found', __( 'Order not found.', 'shelterkit-donations' ) );
		}

		// Skip if already synced (unless forced).
		if ( ! $force_resync ) {
			if ( $order->get_meta( Order_Scanner::SYNCED_META_KEY ) ) {
				return [ 'skipped' => true, 'reason' => __( 'Already synced.', 'shelterkit-donations' ) ];
			}
			if ( $order->get_meta( '_skdon_processed' ) ) {
				return [ 'skipped' => true, 'reason' => __( 'Already processed by order handler.', 'shelterkit-donations' ) ];
			}
		}

		$created = [
			'donations'   => 0,
			'memberships' => 0,
			'memorials'   => 0,
			'donors'      => 0,
			'updated'     => 0,
		];

		$item_results = [];
		$errors       = [];

		[ $donor_id, $donor_created ] = self::resolve_donor( $order );
		if ( $donor_created ) {
			++$created['donors'];
		}

		$lines = self::classify_lines( $order );

		// How many lines of each kind the order has. An older record with no
		// order-line link may only be matched by order when the order has a
		// single line of its kind (#40).
		$type_counts = [];
		foreach ( $lines as $line ) {
			if ( $line['config'] ) {
				$type = $line['config']['product_type'] ?? '';
				$type_counts[ $type ] = ( $type_counts[ $type ] ?? 0 ) + 1;
			}
		}

		// Enable internal processing for ability permission.
		Helpers\set_internal_processing( true );

		try {
			foreach ( $lines as $item_id => $line ) {
				$item      = $line['item'];
				$item_data = $line['data'];
				$config    = $line['config'];

				// A line this plugin doesn't recognise is not a gift. Record it
				// by name, so a sync never passes over part of an order without
				// saying so (#13).
				if ( ! $config ) {
					$item_results[ $item_id ] = [
						'action' => 'skipped',
						'name'   => $item_data['name'] ?? $item->get_name(),
					];
					continue;
				}

				$ability_name = $config['ability'] ?? '';

				if ( ! $ability_name || ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_name ) ) {
					$errors[] = sprintf(
						/* translators: 1: ability name; 2: order item name. */
						__( 'Ability "%1$s" not found for item "%2$s"', 'shelterkit-donations' ),
						$ability_name,
						$item_data['name']
					);
					continue;
				}

				if ( $dry_run ) {
					$type = $config['product_type'] ?? 'unknown';
					$key  = $type . 's';
					if ( isset( $created[ $key ] ) ) {
						++$created[ $key ];
					}
					continue;
				}

				$input = self::build_line_input( $order, $item, $line['product'], $item_data, $config, $donor_id );

				// Check for existing record.
				$product_type = $config['product_type'] ?? '';
				$existing_id  = self::find_existing_record( $order, $item_id, $product_type, $input, $donor_id, $type_counts[ $product_type ] ?? 0 );

				if ( $existing_id ) {
					$result      = self::update_existing_record( $existing_id, $product_type, $input );
					$action_type = 'updated';
				} else {
					$ability     = wp_get_ability( $ability_name );
					$result      = $ability ? $ability->execute( $input ) : new WP_Error(
						'ability_not_found',
						sprintf( /* translators: %s: ability name. */ __( 'Ability "%s" could not be loaded.', 'shelterkit-donations' ), $ability_name )
					);
					$action_type = 'created';
				}

				if ( is_wp_error( $result ) ) {
					$item_results[ $item_id ] = [ 'error' => $result->get_error_message() ];
					$errors[] = sprintf(
						/* translators: 1: order item name; 2: error message. */
						__( 'Error processing "%1$s": %2$s', 'shelterkit-donations' ),
						$item_data['name'],
						$result->get_error_message()
					);
				} else {
					Product_Mapper::stamp_order_line( $result, (int) $item_id );
					$item_results[ $item_id ] = array_merge(
						is_array( $result ) ? $result : [ 'id' => $result ],
						[ 'action' => $action_type ]
					);

					if ( 'updated' === $action_type ) {
						++$created['updated'];
					} else {
						$type = $config['product_type'] ?? 'unknown';
						$key  = $type . 's';
						if ( isset( $created[ $key ] ) ) {
							++$created[ $key ];
						}
					}
				}
			}
		} finally {
			Helpers\set_internal_processing( false );
		}

		// Mark order as synced and add note.
		if ( ! $dry_run ) {
			self::mark_synced( $order, $created, $item_results, $errors );
		}

		return [
			'created'      => $created,
			'item_results' => $item_results,
			'errors'       => $errors,
		];
	}

	/**
	 * Find (or create) the donor for an order, from its billing details.
	 *
	 * @param \WC_Order $order The order.
	 * @return array{0: int|null, 1: bool} Donor ID (null without an email) and whether it was created.
	 */
	public static function resolve_donor( \WC_Order $order ): array {
		$donor_email = $order->get_billing_email();
		if ( ! $donor_email ) {
			return [ null, false ];
		}

		$donor_result = Donor_Lookup::find_or_create( [
			'email'         => $donor_email,
			'first_name'    => $order->get_billing_first_name(),
			'last_name'     => $order->get_billing_last_name(),
			'display_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'phone'         => $order->get_billing_phone(),
			'address'       => [
				'address_1' => $order->get_billing_address_1(),
				'address_2' => $order->get_billing_address_2(),
				'city'      => $order->get_billing_city(),
				'state'     => $order->get_billing_state(),
				'postcode'  => $order->get_billing_postcode(),
				'country'   => $order->get_billing_country(),
			],
			'import_source' => 'legacy_sync',
		] );

		if ( is_wp_error( $donor_result ) ) {
			return [ null, false ];
		}
		return [ (int) $donor_result['id'], (bool) $donor_result['created'] ];
	}

	/**
	 * Classify each product line of an order.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<int, array{item: \WC_Order_Item_Product, product: \WC_Product|null, data: array, config: array|null}>
	 *         Keyed by order item ID. `config` is null for a line this plugin doesn't recognise.
	 */
	public static function classify_lines( \WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product   = $item->get_product() ?: null;
			$item_data = Item_Extractor::extract( $item, $product );
			if ( ! $item_data ) {
				continue;
			}
			$lines[ (int) $item_id ] = [
				'item'    => $item,
				'product' => $product,
				'data'    => $item_data,
				'config'  => Item_Matcher::find_config( $item, $product, $item_data ),
			];
		}
		return $lines;
	}

	/**
	 * Build the ability input for one order line.
	 *
	 * @param \WC_Order              $order     The order.
	 * @param \WC_Order_Item_Product $item      The line.
	 * @param \WC_Product|null       $product   Its product, if it still exists.
	 * @param array                  $item_data Extracted line data.
	 * @param array                  $config    The line's product config.
	 * @param int|null               $donor_id  The order's donor.
	 * @return array
	 */
	public static function build_line_input( \WC_Order $order, \WC_Order_Item_Product $item, ?\WC_Product $product, array $item_data, array $config, ?int $donor_id ): array {
		$input = $product
			? Product_Mapper::build_input( $order, $item, $config )
			: Legacy_Input_Builder::build( $order, $item, $item_data, $config );

		// For legacy orders where the product still exists, Product_Mapper
		// may leave required fields empty (e.g. honoree_name, memorial_type)
		// because those order_meta keys didn't exist before custom checkout
		// fields were added. Fall back to Legacy_Input_Builder which has
		// cascading extraction logic with sensible defaults.
		if ( $product ) {
			$needs_fallback = false;
			foreach ( self::get_required_fields( $config['product_type'] ?? '' ) as $field ) {
				if ( empty( $input[ $field ] ) ) {
					$needs_fallback = true;
					break;
				}
			}

			if ( $needs_fallback ) {
				$legacy_input = Legacy_Input_Builder::build( $order, $item, $item_data, $config );
				foreach ( self::get_required_fields( $config['product_type'] ?? '' ) as $field ) {
					if ( empty( $input[ $field ] ) && ! empty( $legacy_input[ $field ] ) ) {
						$input[ $field ] = $legacy_input[ $field ];
					}
				}
			}
		}

		// Pass donor_id directly to avoid double resolution. Remove
		// donor_email so that only one oneOf branch matches in the ability's
		// input schema (requires exactly one of donor_id or donor_email).
		if ( $donor_id ) {
			$input['donor_id'] = $donor_id;
			unset( $input['donor_email'] );
		}

		return $input;
	}

	/**
	 * Get required ability input fields by product type.
	 *
	 * These are the fields that must be present for the ability's
	 * input schema validation to pass. Used to detect when
	 * Product_Mapper output needs Legacy_Input_Builder fallback.
	 *
	 * @since 2.1.0
	 *
	 * @param string $product_type The product type.
	 * @return string[] Required field names.
	 */
	private static function get_required_fields( string $product_type ): array {
		return match ( $product_type ) {
			'memorial'   => [ 'honoree_name', 'memorial_type', 'amount' ],
			'membership' => [ 'tier', 'amount' ],
			'donation'   => [ 'amount' ],
			default      => [],
		};
	}

	/**
	 * Find the record this order line already produced, if any.
	 *
	 * Matched by the order line itself first (#40). Records created before
	 * lines were linked fall back to an order-level match, but only when the
	 * order has a single line of this kind: with two membership lines, "the
	 * membership linked to this order" is ambiguous, and used to make the
	 * second line overwrite the first line's record. A memorial with no order
	 * link at all may still be matched by donor and honoree.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order $order        The order.
	 * @param int       $item_id      The order item ID.
	 * @param string    $product_type The product type.
	 * @param array     $input        The input data.
	 * @param int|null  $donor_id     The resolved donor ID.
	 * @param int       $type_count   How many lines of this product type the order has.
	 * @return int|null Existing post ID or null.
	 */
	private static function find_existing_record(
		\WC_Order $order,
		int $item_id,
		string $product_type,
		array $input,
		?int $donor_id,
		int $type_count = 1
	): ?int {
		$post_type = match ( $product_type ) {
			'donation'   => 'skdon_donation',
			'membership' => 'skdon_membership',
			'memorial'   => 'skdon_memorial',
			default      => null,
		};

		if ( ! $post_type ) {
			return null;
		}

		$find = static function ( array $meta_query ) use ( $post_type ): ?int {
			$posts = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin sync, one order at a time.
				'fields'         => 'ids',
			] );
			return $posts ? (int) $posts[0] : null;
		};

		// 1. The record this exact line produced.
		$found = $find( [ [ 'key' => Product_Mapper::ORDER_ITEM_META, 'value' => $item_id, 'type' => 'NUMERIC' ] ] );
		if ( $found ) {
			return $found;
		}

		// 2. An older record linked to the order but to no line yet: only
		// unambiguous when the order has one line of this kind.
		if ( 1 === $type_count ) {
			$meta_query = [
				[ 'key' => '_skdon_wc_order_id', 'value' => $order->get_id(), 'type' => 'NUMERIC' ],
				[ 'key' => Product_Mapper::ORDER_ITEM_META, 'compare' => 'NOT EXISTS' ],
			];
			if ( 'memorial' === $product_type && ! empty( $input['honoree_name'] ) ) {
				$meta_query[] = [ 'key' => '_skdon_honoree_name', 'value' => $input['honoree_name'] ];
			}
			if ( 'membership' === $product_type && $donor_id ) {
				$meta_query[] = [ 'key' => '_skdon_donor_id', 'value' => $donor_id, 'type' => 'NUMERIC' ];
			}
			$found = $find( $meta_query );
			if ( $found ) {
				return $found;
			}
		}

		// 3. A memorial from the same donor for the same honoree that belongs
		// to no order (a CSV import, say). Never one linked to another order:
		// that is a different gift.
		if ( 'memorial' === $product_type && ! empty( $input['honoree_name'] ) && $donor_id ) {
			return $find( [
				[ 'key' => '_skdon_donor_id', 'value' => $donor_id, 'type' => 'NUMERIC' ],
				[ 'key' => '_skdon_honoree_name', 'value' => $input['honoree_name'] ],
				[
					'relation' => 'OR',
					[ 'key' => '_skdon_wc_order_id', 'compare' => 'NOT EXISTS' ],
					[ 'key' => '_skdon_wc_order_id', 'value' => [ '', '0' ], 'compare' => 'IN' ],
				],
			] );
		}

		return null;
	}

	/**
	 * Update an existing record with new data from the sync.
	 *
	 * Only updates fields that are empty or different from current values.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $post_id      The existing post ID.
	 * @param string $product_type The product type.
	 * @param array  $input        The input data.
	 * @return array|WP_Error Update result.
	 */
	public static function update_existing_record( int $post_id, string $product_type, array $input ): array|WP_Error {
		$prefix = '_skdon_';

		$field_map = match ( $product_type ) {
			'donation' => [
				'amount' => 'amount', 'allocation' => 'allocation',
				'dedication' => 'dedication', 'order_id' => 'wc_order_id', 'date' => 'donation_date',
			],
			'membership' => [
				'amount' => 'amount', 'tier' => 'tier',
				'membership_type' => 'membership_type', 'business_name' => 'business_name',
				'order_id' => 'wc_order_id', 'date' => 'start_date',
			],
			'memorial' => [
				'amount' => 'amount', 'honoree_name' => 'honoree_name',
				'memorial_type' => 'memorial_type', 'tribute_message' => 'tribute_message',
				'pet_species' => 'pet_species', 'order_id' => 'wc_order_id', 'date' => 'donation_date',
			],
			default => [],
		};

		// Once refunds reached a record its amount is theirs to set (#34):
		// the order line's amount would count a refunded gift in full again.
		if ( metadata_exists( 'post', $post_id, Refund_Handler::REFUNDS_META ) ) {
			unset( $field_map['amount'] );
		}

		$updated_fields = [];

		foreach ( $field_map as $input_key => $meta_key ) {
			if ( ! isset( $input[ $input_key ] ) || '' === $input[ $input_key ] ) {
				continue;
			}

			$current = get_post_meta( $post_id, $prefix . $meta_key, true );
			$new     = $input[ $input_key ];

			if ( $current !== $new && ( empty( $current ) || $new ) ) {
				update_post_meta( $post_id, $prefix . $meta_key, $new );
				$updated_fields[] = $meta_key;
			}
		}

		// Update memorial title if honoree name changed.
		if ( 'memorial' === $product_type && ! empty( $input['honoree_name'] ) ) {
			$post = get_post( $post_id );
			if ( $post && $post->post_title !== $input['honoree_name'] ) {
				wp_update_post( [ 'ID' => $post_id, 'post_title' => $input['honoree_name'] ] );
				$updated_fields[] = 'title';
			}
		}

		/**
		 * Fires after a legacy sync record has been updated.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id        The post ID.
		 * @param string $product_type   The product type.
		 * @param array  $input          The input data.
		 * @param array  $updated_fields Fields that were updated.
		 */
		do_action( 'starter_shelter_legacy_record_updated', $post_id, $product_type, $input, $updated_fields );

		return [
			'id'             => $post_id,
			'updated'        => true,
			'updated_fields' => $updated_fields,
		];
	}

	/**
	 * Mark order as synced and add an order note.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Order $order        The order.
	 * @param array     $created      Counts of created records.
	 * @param array     $item_results Per-item results.
	 * @param array     $errors       Error messages.
	 */
	private static function mark_synced( \WC_Order $order, array $created, array $item_results, array $errors ): void {
		$order->update_meta_data( Order_Scanner::SYNCED_META_KEY, current_time( 'mysql' ) );
		$order->update_meta_data( '_skdon_legacy_sync_results', $item_results );
		$order->save();

		$note = sprintf(
			/* translators: 1: donations, 2: memberships, 3: memorials, 4: updated */
			__( 'ShelterKit Donations legacy sync completed: %1$d donation(s), %2$d membership(s), %3$d memorial(s), %4$d updated', 'shelterkit-donations' ),
			$created['donations'],
			$created['memberships'],
			$created['memorials'],
			$created['updated']
		);

		if ( ! empty( $errors ) ) {
			$note .= "\n" . __( 'Errors:', 'shelterkit-donations' ) . "\n- " . implode( "\n- ", $errors );
		}

		$order->add_order_note( $note );

		/**
		 * Fires after an order has been synced via legacy sync.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $order_id     The order ID.
		 * @param array $created      Counts of created records.
		 * @param array $item_results Results for each item.
		 */
		do_action( 'starter_shelter_legacy_order_synced', $order->get_id(), $created, $item_results );
	}
}
