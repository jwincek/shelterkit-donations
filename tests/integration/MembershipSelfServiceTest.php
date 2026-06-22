<?php
/**
 * Integration tests for member self-service membership management.
 *
 * Covers the owner_or_admin permission fix (so a member can cancel their
 * OWN membership via the ability, but not someone else's), the My Account
 * ownership guard, and that a cancellation drops the member off the public
 * recognition wall.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\WooCommerce\My_Account;
use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memberships\create;
use function Starter_Shelter\Abilities\Memberships\recognition_wall;
use function Starter_Shelter\Helpers\get_or_create_donor;

final class MembershipSelfServiceTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memberships\\create' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memberships.php';
        }
    }

    /** @return array{0:int,1:int} [donor_id, membership_id] for a member linked to $user_id. */
    private function make_member_for_user( int $user_id ): array {
        $donor_id = get_or_create_donor( "u{$user_id}@example.test", "User {$user_id}" );
        update_post_meta( $donor_id, '_sd_user_id', $user_id );
        $res = create( [ 'donor_id' => $donor_id, 'tier' => 'single', 'amount' => 10, 'date' => '2026-01-15' ] );
        return [ $donor_id, $res['membership_id'] ];
    }

    public function test_owner_can_cancel_their_membership_via_ability(): void {
        $user = self::factory()->user->create();
        [ , $mid ] = $this->make_member_for_user( $user );
        wp_set_current_user( $user );

        $ability = wp_get_ability( 'shelter-memberships/cancel' );
        $this->assertNotNull( $ability );

        $result = $ability->execute( [ 'membership_id' => $mid ] );

        $this->assertIsArray( $result );
        $this->assertSame( 'cancelled', $result['status'] );
        $this->assertSame( 'cancelled', get_post_meta( $mid, '_sd_status', true ) );
    }

    public function test_non_owner_cannot_cancel_someone_elses_membership(): void {
        $owner = self::factory()->user->create();
        $other = self::factory()->user->create();
        [ , $mid ] = $this->make_member_for_user( $owner );
        wp_set_current_user( $other );

        $result = wp_get_ability( 'shelter-memberships/cancel' )->execute( [ 'membership_id' => $mid ] );

        $this->assertWPError( $result );
        $this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
        $this->assertNotSame( 'cancelled', (string) get_post_meta( $mid, '_sd_status', true ) );
    }

    public function test_ownership_guard(): void {
        $user = self::factory()->user->create();
        [ $donor_id, $mid ] = $this->make_member_for_user( $user );

        $this->assertTrue( My_Account::current_user_owns_membership( $mid, $donor_id ) );
        $this->assertFalse( My_Account::current_user_owns_membership( $mid, $donor_id + 999 ) );
        $this->assertFalse( My_Account::current_user_owns_membership( 0, $donor_id ) );
    }

    public function test_cancellation_drops_member_off_recognition_wall(): void {
        $user = self::factory()->user->create();
        [ , $mid ] = $this->make_member_for_user( $user );

        $this->assertContains( "User {$user}", array_column( recognition_wall()['items'], 'display_name' ) );

        wp_set_current_user( $user );
        wp_get_ability( 'shelter-memberships/cancel' )->execute( [ 'membership_id' => $mid ] );

        // cancel() clears end_date, so the member is no longer active.
        $this->assertNotContains( "User {$user}", array_column( recognition_wall()['items'], 'display_name' ) );
    }
}
