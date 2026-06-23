<?php
/**
 * Integration tests for the consolidated My Account menu.
 *
 * The five donor sections are collapsed into a single "My Giving" menu
 * item (the sub-sections move to an in-page sub-nav). Verifies the menu
 * filter for donors and that non-donors are left untouched.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\WooCommerce\My_Account;
use WP_UnitTestCase;

use function Starter_Shelter\Helpers\get_or_create_donor;

final class MyAccountMenuTest extends WP_UnitTestCase {

    private const WC_MENU = [
        'dashboard'       => 'Dashboard',
        'orders'          => 'Orders',
        'edit-account'    => 'Account details',
        'customer-logout' => 'Log out',
    ];

    public function test_donor_sees_single_my_giving_item(): void {
        $user = self::factory()->user->create();
        $donor = get_or_create_donor( "u{$user}@example.test", 'Member' );
        update_post_meta( $donor, '_sd_user_id', $user );
        wp_set_current_user( $user );

        $items = My_Account::add_menu_items( self::WC_MENU );

        // One consolidated entry...
        $this->assertArrayHasKey( 'donor-dashboard', $items );
        $this->assertSame( 'My Giving', $items['donor-dashboard'] );

        // ...and the old per-section items are gone.
        foreach ( [ 'giving-history', 'my-memberships', 'my-memorials', 'annual-statement' ] as $slug ) {
            $this->assertArrayNotHasKey( $slug, $items );
        }

        // Inserted before logout, preserving the rest of the WC menu.
        $keys = array_keys( $items );
        $this->assertLessThan( array_search( 'customer-logout', $keys, true ), array_search( 'donor-dashboard', $keys, true ) );
        $this->assertArrayHasKey( 'orders', $items );
    }

    public function test_non_donor_menu_is_unchanged(): void {
        $user = self::factory()->user->create(); // No linked donor record.
        wp_set_current_user( $user );

        $items = My_Account::add_menu_items( self::WC_MENU );

        $this->assertSame( self::WC_MENU, $items );
    }

    public function test_current_donor_id_is_memoized_per_request(): void {
        $user  = self::factory()->user->create();
        $donor = get_or_create_donor( 'memo@example.test', 'Memo Donor' );
        update_post_meta( $donor, '_sd_user_id', $user );
        wp_set_current_user( $user );

        $this->assertSame( $donor, My_Account::get_current_donor_id() );

        // Drop the underlying link; the memoized result still stands this request.
        delete_post_meta( $donor, '_sd_user_id' );
        $this->assertSame( $donor, My_Account::get_current_donor_id(), 'Resolved donor id is cached for the request.' );

        // A fresh request (cache flush) re-resolves — and now finds nothing,
        // since the user's email does not match the donor's.
        wp_cache_flush();
        $this->assertNull( My_Account::get_current_donor_id() );
    }
}
