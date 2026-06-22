<?php
/**
 * Integration tests for the My Account public-recognition self-service.
 *
 * A logged-in member can opt out of the public Members Wall from their
 * account. This exercises the WooCommerce-free logic methods
 * (My_Account::set_recognition_preference / is_listed_publicly) and
 * confirms the recognition-wall ability reflects the change.
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

final class MyAccountRecognitionTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memberships\\recognition_wall' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memberships.php';
        }
    }

    /** @return array{0:int,1:int[]} [donor_id, membership_ids] */
    private function donor_with_memberships( int $n ): array {
        $donor_id = get_or_create_donor( 'jane@example.test', 'Jane Doe' );
        $ids      = [];
        for ( $i = 0; $i < $n; $i++ ) {
            $res   = create( [
                'donor_id' => $donor_id,
                'tier'     => 'single',
                'amount'   => 10,
                'date'     => '2026-01-15',
            ] );
            $ids[] = $res['membership_id'];
        }
        return [ $donor_id, $ids ];
    }

    public function test_member_listed_publicly_by_default(): void {
        [ $donor_id ] = $this->donor_with_memberships( 1 );

        $this->assertTrue( My_Account::is_listed_publicly( $donor_id ) );
        $this->assertContains( 'Jane Doe', array_column( recognition_wall()['items'], 'display_name' ) );
    }

    public function test_opt_out_hides_member_and_updates_all_memberships(): void {
        [ $donor_id, $ids ] = $this->donor_with_memberships( 2 );

        $updated = My_Account::set_recognition_preference( $donor_id, false );

        $this->assertSame( 2, $updated, 'All of the donor\'s memberships are updated.' );
        $this->assertFalse( My_Account::is_listed_publicly( $donor_id ) );
        foreach ( $ids as $id ) {
            $this->assertSame( '1', (string) get_post_meta( $id, '_sd_is_anonymous', true ) );
        }
        $this->assertNotContains( 'Jane Doe', array_column( recognition_wall()['items'], 'display_name' ) );
    }

    public function test_opt_back_in_restores_listing(): void {
        [ $donor_id ] = $this->donor_with_memberships( 1 );

        My_Account::set_recognition_preference( $donor_id, false );
        My_Account::set_recognition_preference( $donor_id, true );

        $this->assertTrue( My_Account::is_listed_publicly( $donor_id ) );
        $this->assertContains( 'Jane Doe', array_column( recognition_wall()['items'], 'display_name' ) );
    }
}
