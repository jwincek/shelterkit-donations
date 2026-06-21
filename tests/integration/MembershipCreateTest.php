<?php
/**
 * Integration tests for the shelter-memberships/create and /renew flows.
 *
 * Exercises create() and renew() against a real WordPress + database:
 * tier/type/amount, the +1-year term dates, the tier-amount fallback,
 * business-only fields, donor lifetime-giving accumulation, and that
 * renewal extends an active membership's end date and records the order.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memberships\create;
use function Starter_Shelter\Abilities\Memberships\renew;
use function Starter_Shelter\Helpers\get_tier_amount;

final class MembershipCreateTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memberships\\create' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memberships.php';
        }
    }

    public function test_create_persists_fields_and_one_year_term(): void {
        $result = create( [
            'donor_email'     => 'member@example.test',
            'donor_name'      => 'New Member',
            'tier'            => 'single',
            'membership_type' => 'individual',
            'amount'          => 45,
            'date'            => '2026-01-15',
        ] );

        $this->assertIsArray( $result );
        $id = $result['membership_id'];

        $this->assertSame( 'sd_membership', get_post_type( $id ) );
        $this->assertSame( 'single', get_post_meta( $id, '_sd_tier', true ) );
        $this->assertSame( 'individual', get_post_meta( $id, '_sd_membership_type', true ) );
        $this->assertSame( 45.0, (float) get_post_meta( $id, '_sd_amount', true ) );
        $this->assertSame( '2026-01-15', get_post_meta( $id, '_sd_start_date', true ) );
        $this->assertSame( '2027-01-15', get_post_meta( $id, '_sd_end_date', true ) );
        $this->assertSame( '2027-01-15', $result['end_date'] );
    }

    public function test_amount_falls_back_to_tier_amount_when_omitted(): void {
        $result = create( [
            'donor_email'     => 'member@example.test',
            'donor_name'      => 'New Member',
            'tier'            => 'single',
            'membership_type' => 'individual',
            // amount intentionally omitted
        ] );

        $this->assertSame(
            get_tier_amount( 'single', 'individual' ),
            (float) get_post_meta( $result['membership_id'], '_sd_amount', true )
        );
    }

    public function test_business_fields_persisted_for_business_type(): void {
        $result = create( [
            'donor_email'     => 'biz@example.test',
            'donor_name'      => 'Biz Owner',
            'tier'            => 'donor',
            'membership_type' => 'business',
            'amount'          => 250,
            'business_name'   => 'Acme Pet Supplies',
        ] );

        $this->assertSame(
            'Acme Pet Supplies',
            get_post_meta( $result['membership_id'], '_sd_business_name', true )
        );
    }

    public function test_create_increments_donor_lifetime_giving(): void {
        $result = create( [
            'donor_email' => 'member@example.test',
            'donor_name'  => 'New Member',
            'tier'        => 'single',
            'amount'      => 45,
        ] );

        $this->assertSame(
            45.0,
            (float) get_post_meta( $result['donor_id'], '_sd_lifetime_giving', true )
        );
    }

    public function test_renew_extends_active_membership_and_records_order(): void {
        $created = create( [
            'donor_email' => 'member@example.test',
            'donor_name'  => 'New Member',
            'tier'        => 'single',
            'amount'      => 45,
            'date'        => '2026-01-15',
        ] );
        $id = $created['membership_id'];

        // End date is 2027-01-15 (future) → active → extend from there.
        $renewal = renew( [
            'membership_id' => $id,
            'amount'        => 45,
            'order_id'      => 9876,
        ] );

        $this->assertIsArray( $renewal );
        $this->assertSame( '2028-01-15', $renewal['new_end_date'] );
        $this->assertSame( '2028-01-15', get_post_meta( $id, '_sd_end_date', true ) );

        $orders = get_post_meta( $id, '_sd_renewal_orders', true );
        $this->assertIsArray( $orders );
        $this->assertCount( 1, $orders );
        $this->assertSame( 9876, $orders[0]['order_id'] );

        // Lifetime giving = initial 45 + renewal 45.
        $this->assertSame(
            90.0,
            (float) get_post_meta( $created['donor_id'], '_sd_lifetime_giving', true )
        );
    }
}
