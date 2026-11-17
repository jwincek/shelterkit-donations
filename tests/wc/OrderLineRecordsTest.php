<?php
/**
 * Every paid order line becomes exactly one record (#35, #40, #13).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Admin\Legacy_Sync\Merge_Repair;
use Starter_Shelter\Admin\Legacy_Sync\Order_Processor;
use Starter_Shelter\Admin\Legacy_Sync\Order_Scanner;
use Starter_Shelter\WooCommerce\Order_Handler;
use Starter_Shelter\WooCommerce\Product_Mapper;
use WP_UnitTestCase;

final class OrderLineRecordsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The abilities accept internal callers during a paid-status
		// transition, a sync or import, or for an administrator. Calling
		// process_order() directly, as Reprocess does, needs the last.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function product( string $sku, float $price = 10.0 ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Product ' . $sku );
		$product->set_regular_price( (string) $price );
		$product->set_sku( $sku );
		$product->save();
		return $product;
	}

	/**
	 * @param array<int, array{0: \WC_Product, 1: float, 2?: array<string, string>}> $lines Product, amount, item meta.
	 */
	private function order( array $lines, string $email = 'pat.donor@example.test' ): \WC_Order {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Pat' );
		$order->set_billing_last_name( 'Donor' );
		$order->set_billing_email( $email );
		foreach ( $lines as $line ) {
			$item_id = $order->add_product( $line[0], 1, [ 'subtotal' => $line[1], 'total' => $line[1] ] );
			$item    = $order->get_item( $item_id );
			foreach ( $line[2] ?? [] as $key => $value ) {
				$item->add_meta_data( $key, $value, true );
			}
			$item->save();
		}
		$order->calculate_totals( false );
		$order->save();
		return $order;
	}

	/** @return int[] */
	private function records( int $order_id, string $post_type ): array {
		return get_posts( [
			'post_type'   => $post_type,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'meta_key'    => '_skdon_wc_order_id',
			'meta_value'  => $order_id,
		] );
	}

	private function line_ids( \WC_Order $order ): array {
		return array_map( 'intval', array_keys( $order->get_items() ) );
	}

	// ─── #35: checkout ──────────────────────────────────────────────

	public function test_a_plugin_product_whose_sku_maps_to_nothing_is_an_error_not_a_skip(): void {
		$renamed = $this->product( 'general-donation', 50 );
		update_option( 'skdon_donation_product_id', $renamed->get_id() );
		$order = $this->order( [ [ $this->product( 'shelter-donations', 25 ), 25 ], [ $renamed, 50 ] ] );

		Order_Handler::process_order( $order->get_id() );

		$fresh   = wc_get_order( $order->get_id() );
		$results = $fresh->get_meta( '_skdon_processing_results' );
		$failed  = $results[ $this->line_ids( $order )[1] ] ?? null;
		$this->assertInstanceOf( \WP_Error::class, $failed, 'The renamed line is an error result.' );
		$this->assertSame( 'unmapped_line', $failed->get_error_code() );
		$this->assertSame( 1, (int) $fresh->get_meta( Order_Handler::ERRORS_META_KEY ) );
		$notes = implode( "\n", wp_list_pluck( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ), 'content' ) );
		$this->assertStringContainsString( 'Not recorded', $notes );
	}

	public function test_an_order_whose_only_plugin_line_is_unmapped_is_still_processed(): void {
		$renamed = $this->product( 'general-donation', 50 );
		update_option( 'skdon_donation_product_id', $renamed->get_id() );
		$order = $this->order( [ [ $renamed, 50 ] ] );

		Order_Handler::process_order( $order->get_id() );

		$this->assertSame( 1, (int) wc_get_order( $order->get_id() )->get_meta( Order_Handler::ERRORS_META_KEY ), 'Used to be skipped entirely: nothing recorded, nothing said.' );
	}

	public function test_an_ordinary_shop_product_is_left_alone(): void {
		$order = $this->order( [ [ $this->product( 'tote-bag', 15 ), 15 ] ] );

		Order_Handler::process_order( $order->get_id() );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( '', $fresh->get_meta( '_skdon_processed' ) );
		$this->assertSame( '', $fresh->get_meta( Order_Handler::ERRORS_META_KEY ) );
	}

	public function test_records_carry_their_line_and_reprocessing_adds_no_duplicate(): void {
		$order = $this->order( [ [ $this->product( 'shelter-donations', 25 ), 25 ] ] );
		Order_Handler::process_order( $order->get_id() );

		$records = $this->records( $order->get_id(), 'skdon_donation' );
		$this->assertCount( 1, $records );
		$this->assertSame( (string) $this->line_ids( $order )[0], get_post_meta( $records[0], Product_Mapper::ORDER_ITEM_META, true ) );

		// What Reprocess does.
		$fresh = wc_get_order( $order->get_id() );
		$fresh->delete_meta_data( '_skdon_processed' );
		$fresh->delete_meta_data( '_skdon_processing_results' );
		$fresh->save();
		Order_Handler::process_order( $order->get_id() );

		$this->assertCount( 1, $this->records( $order->get_id(), 'skdon_donation' ), 'The line already has its record.' );
	}

	// ─── #40: Legacy Order Sync ─────────────────────────────────────

	private function two_membership_order(): \WC_Order {
		return $this->order( [
			[ $this->product( 'shelter-memberships-business', 250 ), 250, [ 'membership-level' => 'donor', '_skdon_business_name' => 'Biscuit Bakery' ] ],
			[ $this->product( 'shelter-memberships', 10 ), 10, [ 'membership-level' => 'single' ] ],
		] );
	}

	public function test_sync_gives_each_membership_line_its_own_record(): void {
		$order  = $this->two_membership_order();
		$result = Order_Processor::sync( $order->get_id() );

		$this->assertSame( [], $result['errors'] );
		$records = $this->records( $order->get_id(), 'skdon_membership' );
		$this->assertCount( 2, $records, 'Both memberships are recorded; the second no longer overwrites the first.' );

		$by_line = [];
		foreach ( $records as $record ) {
			$by_line[ (int) get_post_meta( $record, Product_Mapper::ORDER_ITEM_META, true ) ] = $record;
		}
		[ $business_line, $individual_line ] = $this->line_ids( $order );
		$this->assertSame( 'business', get_post_meta( $by_line[ $business_line ], '_skdon_membership_type', true ) );
		$this->assertSame( 'donor', get_post_meta( $by_line[ $business_line ], '_skdon_tier', true ) );
		$this->assertSame( 'single', get_post_meta( $by_line[ $individual_line ], '_skdon_tier', true ) );
	}

	public function test_sync_gives_each_donation_line_its_own_record(): void {
		$order = $this->order( [
			[ $this->product( 'shelter-donations', 100 ), 100, [ '_skdon_allocation' => 'general-fund' ] ],
			[ $this->product( 'shelter-donations-x', 50 ), 50, [ '_skdon_allocation' => 'medical-care' ] ],
		] );
		Order_Processor::sync( $order->get_id() );

		$records = $this->records( $order->get_id(), 'skdon_donation' );
		$this->assertCount( 2, $records );
		$this->assertEqualsCanonicalizing( [ '100', '50' ], array_map( static fn ( $id ) => (string) (float) get_post_meta( $id, '_skdon_amount', true ), $records ) );
	}

	public function test_a_memorial_from_another_order_is_never_taken_over(): void {
		$memorial = $this->product( 'shelter-donations-in-memoriam', 10 );
		$meta     = [ '_skdon_honoree_name' => 'Maggie', '_skdon_product_type' => 'memorial' ];
		$first    = $this->order( [ [ $memorial, 10, $meta ] ] );
		$second   = $this->order( [ [ $memorial, 10, $meta ] ] );

		Order_Processor::sync( $first->get_id() );
		Order_Processor::sync( $second->get_id() );

		$this->assertCount( 1, $this->records( $first->get_id(), 'skdon_memorial' ), 'The first gift keeps its record and its order link.' );
		$this->assertCount( 1, $this->records( $second->get_id(), 'skdon_memorial' ) );
	}

	public function test_a_forced_resync_updates_the_same_records(): void {
		$order = $this->two_membership_order();
		Order_Processor::sync( $order->get_id() );
		$before = $this->records( $order->get_id(), 'skdon_membership' );

		$result = Order_Processor::sync( $order->get_id(), false, true );

		$this->assertSame( $before, $this->records( $order->get_id(), 'skdon_membership' ) );
		$this->assertSame( 2, $result['created']['updated'] );
	}

	public function test_a_line_the_plugin_does_not_recognise_is_recorded_as_skipped(): void {
		$order  = $this->order( [ [ $this->product( 'tote-bag', 15 ), 15 ] ] );
		$result = Order_Processor::sync( $order->get_id() );

		$line = $result['item_results'][ $this->line_ids( $order )[0] ] ?? [];
		$this->assertSame( 'skipped', $line['action'] ?? null, 'Said, not silently dropped (#13).' );
	}

	// ─── #13: older, singular product names ─────────────────────────

	public function test_a_singular_membership_name_gives_its_tier(): void {
		$product = $this->product( 'shelter-memberships', 25 );
		$product->set_name( 'Shelter Membership - Family' );
		$product->save();
		$order = $this->order( [ [ $product, 25 ] ] );

		$result = Order_Processor::sync( $order->get_id() );

		$this->assertSame( [], $result['errors'], 'Used to fail with "tier is a required property".' );
		$records = $this->records( $order->get_id(), 'skdon_membership' );
		$this->assertSame( 'family', get_post_meta( $records[0], '_skdon_tier', true ) );
	}

	public function test_a_membership_type_attribute_is_read_only_when_it_names_a_tier(): void {
		$product = $this->product( 'shelter-memberships', 25 );
		$product->set_name( 'Shelter Membership' );
		$product->save();

		$family = $this->order( [ [ $product, 25, [ 'membership-type' => 'Family' ] ] ] );
		Order_Processor::sync( $family->get_id() );
		$this->assertSame( 'family', get_post_meta( $this->records( $family->get_id(), 'skdon_membership' )[0], '_skdon_tier', true ) );

		$kind   = $this->order( [ [ $product, 25, [ 'membership-type' => 'Individual' ] ] ] );
		$result = Order_Processor::sync( $kind->get_id() );
		$this->assertStringContainsString( 'tier', implode( ' ', $result['errors'] ), '"Individual" is a kind of membership, not a tier.' );
	}

	// ─── No email for history ──────────────────────────────────────

	public function test_records_created_from_history_send_no_email_but_checkout_does(): void {
		$sent = [];
		add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$sent ) {
			$sent[] = $atts['subject'];
			return true;
		}, 10, 2 );
		// The plugin's emails hook their triggers when they are built, which
		// WooCommerce does once per request through this factory. The suite
		// resets hooks between tests, so build them for this one.
		WC()->mailer(); // Loads WC_Email, which the plugin's emails extend.
		\Starter_Shelter\Emails\Email_Factory::register_emails( [] );

		// Legacy Order Sync (and CSV import, and the merge repair) run as internal processing.
		Order_Processor::sync( $this->two_membership_order()->get_id() );
		$this->assertSame( [], $sent, 'A sync of past orders emails nobody.' );

		// Checkout does not.
		$order = $this->order( [ [ $this->product( 'shelter-donations', 25 ), 25 ] ], 'new.donor@example.test' );
		$order->update_status( 'completed' );
		$this->assertNotEmpty( preg_grep( '/donation/i', $sent ), 'A real gift still gets the plugin\'s donation receipt.' );
	}

	// ─── #40: repairing what earlier syncs merged ──────────────────

	public function test_repair_restores_a_merged_record_and_gives_the_other_line_its_own(): void {
		$order = $this->two_membership_order();
		Order_Processor::sync( $order->get_id() );
		[ $business_line, $individual_line ] = $this->line_ids( $order );
		[ $business, $individual ] = $this->records( $order->get_id(), 'skdon_membership' );

		// Recreate what the old matching did: the individual line overwrote the
		// business record, had no record of its own, and the sync results say so.
		wp_delete_post( $individual, true );
		update_post_meta( $business, '_skdon_membership_type', 'individual' );
		update_post_meta( $business, '_skdon_tier', 'single' );
		update_post_meta( $business, '_skdon_amount', '10' );
		delete_post_meta( $business, Product_Mapper::ORDER_ITEM_META );
		$fresh = wc_get_order( $order->get_id() );
		$fresh->update_meta_data( Order_Scanner::RESULTS_META_KEY, [
			$business_line   => [ 'membership_id' => $business, 'action' => 'created' ],
			$individual_line => [ 'id' => $business, 'updated' => true, 'action' => 'updated' ],
		] );
		$fresh->save();

		$found = Merge_Repair::find();
		$this->assertCount( 1, $found );
		$this->assertSame( [ $business, $order->get_id(), $business_line, $order->get_id(), $individual_line ], [ $found[0]['record'], $found[0]['order_id'], $found[0]['item_id'], $found[0]['by_order'], $found[0]['by_item'] ] );

		$out = Merge_Repair::repair_all();
		$this->assertSame( [], $out['failed'] );

		$this->assertSame( 'business', get_post_meta( $business, '_skdon_membership_type', true ), 'The record is the business membership again.' );
		$this->assertSame( 'donor', get_post_meta( $business, '_skdon_tier', true ) );
		$this->assertSame( (string) $business_line, get_post_meta( $business, Product_Mapper::ORDER_ITEM_META, true ) );

		$new = $out['repaired'][0]['new_record'];
		$this->assertSame( 'single', get_post_meta( $new, '_skdon_tier', true ), 'The individual line has its own record.' );
		$this->assertSame( (string) $individual_line, get_post_meta( $new, Product_Mapper::ORDER_ITEM_META, true ) );

		$this->assertSame( [], Merge_Repair::find(), 'A second scan finds nothing.' );
	}
}
