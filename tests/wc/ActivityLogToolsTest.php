<?php
/**
 * Reprocessing an order and the daily renewal reminders are one entry each
 * in the activity log (#36).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Admin\Activity_Log;
use Starter_Shelter\Admin\Record_Edit_Tracker;
use Starter_Shelter\Cron\Renewal_Reminder;
use Starter_Shelter\WooCommerce\Order_Handler;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

final class ActivityLogToolsTest extends WP_Ajax_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Record_Edit_Tracker::reset();
	}

	public function tear_down(): void {
		Activity_Log::leave_run();
		$_POST = [];
		parent::tear_down();
	}

	/** @return object[] */
	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}skdon_activity_log ORDER BY id ASC" );
	}

	private function clear_log(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}skdon_activity_log" );
	}

	public function test_reprocessing_an_order_is_one_entry(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Gift' );
		$product->set_regular_price( '25' );
		$product->set_sku( 'shelter-donations' );
		$product->save();
		$order = wc_create_order();
		$order->set_billing_email( 'pat.donor@example.test' );
		$order->add_product( $product, 1, [ 'subtotal' => 25, 'total' => 25 ] );
		$order->calculate_totals( false );
		$order->save();
		Order_Handler::process_order( $order->get_id() );
		$this->clear_log();

		$_POST = [ 'nonce' => wp_create_nonce( 'sd_reprocess_order' ), 'order_id' => $order->get_id() ];
		$_REQUEST = $_POST;
		$this->_last_response = '';
		ob_start();
		try {
			Order_Handler::ajax_reprocess_order();
		} catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) {
			// wp_send_json_success() ends here.
		}

		$visible = array_values( array_filter( $this->rows(), static fn ( $r ) => null === $r->run_id || str_starts_with( $r->event_type, 'tool_' ) ) );
		$this->assertCount( 1, $visible, 'One entry; the order_processed entry is attached to it.' );
		$this->assertSame(
			sprintf( 'Reprocessed order #%d: 0 recorded, 1 already recorded, 0 not recorded', $order->get_id() ),
			Activity_Log::render_message( $visible[0] )
		);
	}

	public function test_the_days_renewal_reminders_are_one_entry(): void {
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish' ] );
		update_post_meta( $donor, '_skdon_email', 'member@example.test' );
		$ends = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
		foreach ( [ 1, 2 ] as $i ) {
			$membership = self::factory()->post->create( [ 'post_type' => 'skdon_membership', 'post_status' => 'publish' ] );
			update_post_meta( $membership, '_skdon_donor_id', $donor );
			update_post_meta( $membership, '_skdon_end_date', $ends );
			update_post_meta( $membership, '_skdon_status', 'active' );
		}
		$this->clear_log();

		Renewal_Reminder::process_reminders();

		$rows    = $this->rows();
		$visible = array_values( array_filter( $rows, static fn ( $r ) => null === $r->run_id || str_starts_with( $r->event_type, 'tool_' ) ) );
		$this->assertCount( 1, $visible );
		$this->assertSame( 'cron', $visible[0]->source, 'A scheduled task.' );
		$this->assertSame( "Renewal reminders: 2 sent, 0 skipped (memberships ending {$ends})", Activity_Log::render_message( $visible[0] ) );
		$this->assertCount( 2, array_filter( $rows, static fn ( $r ) => 'renewal_reminder_sent' === $r->event_type && $r->run_id === $visible[0]->run_id ) );

		// A day with nothing to send writes nothing.
		$this->clear_log();
		Renewal_Reminder::process_reminders();
		$this->assertSame( [], $this->rows() );
	}
}
