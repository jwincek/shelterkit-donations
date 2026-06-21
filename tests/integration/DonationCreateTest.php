<?php
/**
 * Integration tests for the shelter-donations/create data flow.
 *
 * Exercises create() against a real WordPress + database: donor
 * resolution (and the pre-resolved donor_id path), allocation default,
 * the anonymous flag, campaign attachment, donor lifetime-giving
 * accumulation, and list filtering by allocation.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Donations\create;
use function Starter_Shelter\Abilities\Donations\list_donations;
use function Starter_Shelter\Helpers\get_or_create_donor;

final class DonationCreateTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Donations\\create' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/donations.php';
        }
    }

    public function test_create_persists_core_fields_and_default_allocation(): void {
        $result = create( [
            'donor_email' => 'donor@example.test',
            'donor_name'  => 'Gen Donor',
            'amount'      => 50,
        ] );

        $this->assertIsArray( $result );
        $id = $result['donation_id'];

        $this->assertSame( 'sd_donation', get_post_type( $id ) );
        $this->assertSame( 'publish', get_post_status( $id ) );
        $this->assertSame( $result['donor_id'], (int) get_post_meta( $id, '_sd_donor_id', true ) );
        $this->assertSame( 50.0, (float) get_post_meta( $id, '_sd_amount', true ) );
        $this->assertSame( 'general-fund', get_post_meta( $id, '_sd_allocation', true ) );
    }

    public function test_allocation_is_respected(): void {
        $result = create( [
            'donor_email' => 'donor@example.test',
            'donor_name'  => 'Gen Donor',
            'amount'      => 25,
            'allocation'  => 'medical-fund',
        ] );

        $this->assertSame( 'medical-fund', get_post_meta( $result['donation_id'], '_sd_allocation', true ) );
    }

    public function test_anonymous_flag_persisted(): void {
        $result = create( [
            'donor_email'  => 'donor@example.test',
            'donor_name'   => 'Gen Donor',
            'amount'       => 25,
            'is_anonymous' => true,
        ] );

        $this->assertTrue( (bool) get_post_meta( $result['donation_id'], '_sd_is_anonymous', true ) );
    }

    public function test_create_accumulates_donor_lifetime_giving(): void {
        $email = 'repeat@example.test';
        create( [ 'donor_email' => $email, 'donor_name' => 'Repeat', 'amount' => 30 ] );
        $second = create( [ 'donor_email' => $email, 'donor_name' => 'Repeat', 'amount' => 70 ] );

        $this->assertSame(
            100.0,
            (float) get_post_meta( $second['donor_id'], '_sd_lifetime_giving', true )
        );
    }

    public function test_pre_resolved_donor_id_skips_lookup(): void {
        $donor_id = get_or_create_donor( 'pre@example.test', 'Pre Resolved' );

        $result = create( [
            'donor_id' => $donor_id,
            'amount'   => 40,
        ] );

        $this->assertSame( $donor_id, $result['donor_id'] );
        // Only the one donor exists — no second record created.
        $donors = get_posts( [ 'post_type' => 'sd_donor', 'numberposts' => -1, 'fields' => 'ids' ] );
        $this->assertCount( 1, $donors );
        // Title falls back to the donor record's name (no donor_name/email
        // supplied) — guards the "Undefined array key" warning this path
        // triggered before the get_the_title() fallback.
        $this->assertStringContainsString(
            get_the_title( $donor_id ),
            get_post( $result['donation_id'] )->post_title
        );
    }

    public function test_campaign_is_attached(): void {
        $campaign_id = self::factory()->term->create( [ 'taxonomy' => 'sd_campaign', 'name' => 'Spring Drive' ] );

        $result = create( [
            'donor_email' => 'donor@example.test',
            'donor_name'  => 'Gen Donor',
            'amount'      => 60,
            'campaign_id' => $campaign_id,
        ] );

        $terms = wp_get_object_terms( $result['donation_id'], 'sd_campaign', [ 'fields' => 'ids' ] );
        $this->assertContains( $campaign_id, $terms );
    }

    public function test_list_filters_by_allocation(): void {
        create( [ 'donor_email' => 'a@example.test', 'donor_name' => 'A', 'amount' => 10, 'allocation' => 'medical-fund' ] );
        create( [ 'donor_email' => 'b@example.test', 'donor_name' => 'B', 'amount' => 10, 'allocation' => 'general-fund' ] );

        $medical = list_donations( [ 'allocation' => 'medical-fund', 'per_page' => 50 ] );

        $this->assertSame( 1, $medical['total'] );
        $this->assertSame( 'medical-fund', $medical['items'][0]['allocation'] );
    }
}
