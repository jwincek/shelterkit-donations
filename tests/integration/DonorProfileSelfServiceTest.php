<?php
/**
 * Integration tests for donor profile self-service (My Account edit).
 *
 * The My Account "Contact Details" form drives the owner-scoped
 * shelter-donors/update-profile and update-address abilities. These tests
 * confirm the owner can update their own name/phone/address through those
 * abilities and that a non-owner is rejected.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Core\Entity_Hydrator;
use WP_UnitTestCase;

use function Starter_Shelter\Helpers\get_or_create_donor;

final class DonorProfileSelfServiceTest extends WP_UnitTestCase {

    /** @return array{0:int,1:int} [donor_id, user_id] */
    private function donor_for_new_user(): array {
        $user_id  = self::factory()->user->create();
        $donor_id = get_or_create_donor( "u{$user_id}@example.test", 'Old Name' );
        update_post_meta( $donor_id, '_sd_user_id', $user_id );
        return [ $donor_id, $user_id ];
    }

    public function test_owner_can_update_profile_and_address(): void {
        [ $donor_id, $user_id ] = $this->donor_for_new_user();
        wp_set_current_user( $user_id );

        $profile = wp_get_ability( 'shelter-donors/update-profile' )->execute( [
            'donor_id'   => $donor_id,
            'first_name' => 'Jamie',
            'last_name'  => 'Rivera',
            'phone'      => '555-0100',
        ] );
        $address = wp_get_ability( 'shelter-donors/update-address' )->execute( [
            'donor_id' => $donor_id,
            'source'   => 'myaccount',
            'address'  => [
                'address_1' => '123 Shelter Way',
                'city'      => 'Petville',
                'state'     => 'OH',
                'postcode'  => '44001',
            ],
        ] );

        $this->assertNotWPError( $profile );
        $this->assertNotWPError( $address );

        $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
        $this->assertSame( 'Jamie', $donor['first_name'] );
        $this->assertSame( 'Rivera', $donor['last_name'] );
        $this->assertSame( '555-0100', $donor['phone'] );
        $this->assertSame( '123 Shelter Way', $donor['address']['line_1'] ?? null );
        $this->assertSame( 'Petville', $donor['address']['city'] ?? null );
        $this->assertSame( '44001', $donor['address']['postal_code'] ?? null );
        // Name change propagates to the post title.
        $this->assertSame( 'Jamie Rivera', get_post( $donor_id )->post_title );
    }

    public function test_non_owner_cannot_update_profile(): void {
        [ $donor_id ] = $this->donor_for_new_user();
        $other = self::factory()->user->create();
        wp_set_current_user( $other );

        $result = wp_get_ability( 'shelter-donors/update-profile' )->execute( [
            'donor_id'   => $donor_id,
            'first_name' => 'Hacker',
        ] );

        $this->assertWPError( $result );
        $this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
        // The rejected write must not have touched the record.
        $this->assertNotSame( 'Hacker', (string) get_post_meta( $donor_id, '_sd_first_name', true ) );
    }
}
