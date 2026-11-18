<?php
/**
 * The Donor Dashboard shows the donor's dashboard (#42).
 *
 * It stayed on "Loading your dashboard…" for good: its start-up callback
 * didn't exist, and the client store it loaded would have replaced this
 * render's data with the results of requests to routes that don't exist.
 * It is now rendered in full on the server, with no script.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use WP_UnitTestCase;

final class DonorDashboardTest extends WP_UnitTestCase {

	public function test_a_donor_sees_their_dashboard_with_nothing_left_to_load(): void {
		$user  = self::factory()->user->create( [ 'display_name' => 'Robin Giver' ] );
		$donor = self::factory()->post->create( [ 'post_type' => 'skdon_donor', 'post_status' => 'publish', 'post_title' => 'Robin Giver' ] );
		update_post_meta( $donor, '_skdon_user_id', $user );
		update_post_meta( $donor, '_skdon_first_name', 'Robin' );
		update_post_meta( $donor, '_skdon_lifetime_giving', 125 );
		wp_set_current_user( $user );

		// Three gifts; the earliest is the membership. A draft doesn't count.
		$gift = static function ( string $type, string $date_key, string $date, string $status = 'publish' ) use ( $donor ): void {
			$id = self::factory()->post->create( [ 'post_type' => $type, 'post_status' => $status ] );
			update_post_meta( $id, '_skdon_donor_id', $donor );
			update_post_meta( $id, '_skdon_amount', 25 );
			update_post_meta( $id, $date_key, $date );
		};
		$gift( 'skdon_donation', '_skdon_donation_date', '2025-03-10' );
		$gift( 'skdon_donation', '_skdon_donation_date', '2026-01-05' );
		$gift( 'skdon_membership', '_skdon_start_date', '2024-11-02' );
		$gift( 'skdon_memorial', '_skdon_donation_date', '2023-01-01', 'draft' );

		$html = do_blocks( '<!-- wp:shelter-donations/donor-dashboard /-->' );
		$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) );

		$this->assertStringContainsString( 'sd-dashboard-content', $html );
		$this->assertStringContainsString( '$125.00', $html, 'Lifetime giving, formatted on the server.' );
		$this->assertStringContainsString( '3 Total Gifts', $text, 'Counted from the records; the meta it read was never written.' );
		$this->assertStringContainsString( 'Nov 2024 Donor Since', $text );
		$this->assertStringNotContainsString( 'sd-dashboard-loading', $html, 'No loading state: nothing loads after the page.' );
		$this->assertStringNotContainsString( 'data-wp-', $html, 'No directives left for a script to get wrong.' );
	}

	public function test_the_block_loads_no_script(): void {
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( 'shelter-donations/donor-dashboard' );

		$this->assertNotNull( $block );
		$this->assertSame( [], $block->view_script_module_ids );
	}
}
