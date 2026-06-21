<?php
/**
 * Integration tests for shelter-reports/campaign-progress.
 *
 * This ability is the single source of truth for campaign progress (the
 * campaign-card block, the Campaigns admin tab, and the per-campaign CSV
 * all read it). It is type-aware: donation drives report `raised`,
 * membership drives report `member_count`. These tests verify the
 * aggregation math (raised/progress/remaining, member counts, tier
 * filtering) and the is_active flag against real data.
 *
 * Campaign config lives in term meta (written by Campaign_Admin in the
 * real flow); the tests set it directly since it's the ability's input,
 * and tag donations/memberships via the entities' own create() abilities.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Reports\campaign_progress;
use function Starter_Shelter\Abilities\Donations\create as create_donation;
use function Starter_Shelter\Abilities\Memberships\create as create_membership;

final class CampaignProgressTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        foreach ( [ 'reports', 'donations', 'memberships' ] as $f ) {
            require_once dirname( __DIR__, 2 ) . "/includes/abilities/{$f}.php";
        }
    }

    private function make_campaign( string $type, float $goal, string $end_date = '', string $tier = '' ): int {
        $term_id = self::factory()->term->create( [ 'taxonomy' => 'sd_campaign', 'name' => "Campaign {$type}" ] );
        update_term_meta( $term_id, '_sd_campaign_type', $type );
        update_term_meta( $term_id, '_sd_goal', $goal );
        if ( '' !== $end_date ) {
            update_term_meta( $term_id, '_sd_end_date', $end_date );
        }
        if ( '' !== $tier ) {
            update_term_meta( $term_id, '_sd_membership_tier_filter', $tier );
        }
        return $term_id;
    }

    public function test_donation_drive_sums_tagged_donations(): void {
        $cid = $this->make_campaign( 'donation_drive', 1000, '2030-12-31' );

        create_donation( [ 'donor_email' => 'a@example.test', 'donor_name' => 'A', 'amount' => 100, 'campaign_id' => $cid ] );
        create_donation( [ 'donor_email' => 'b@example.test', 'donor_name' => 'B', 'amount' => 250, 'campaign_id' => $cid ] );
        // Untagged donation must NOT count toward the campaign.
        create_donation( [ 'donor_email' => 'c@example.test', 'donor_name' => 'C', 'amount' => 999 ] );

        $p = campaign_progress( [ 'campaign_id' => $cid ] );

        $this->assertIsArray( $p );
        $this->assertSame( 'donation_drive', $p['type'] );
        $this->assertSame( 'currency', $p['goal_unit'] );
        $this->assertSame( 350.0, $p['raised'] );
        $this->assertSame( 1000.0, $p['goal'] );
        $this->assertSame( 35.0, $p['progress'] );
        $this->assertSame( 650.0, $p['remaining'] );
        $this->assertTrue( $p['is_active'] );
    }

    public function test_progress_caps_at_100_when_goal_exceeded(): void {
        $cid = $this->make_campaign( 'donation_drive', 100, '2030-12-31' );
        create_donation( [ 'donor_email' => 'a@example.test', 'donor_name' => 'A', 'amount' => 250, 'campaign_id' => $cid ] );

        $p = campaign_progress( [ 'campaign_id' => $cid ] );

        $this->assertSame( 250.0, $p['raised'] );
        $this->assertSame( 100.0, $p['progress'], 'Progress is capped at 100%.' );
        $this->assertSame( 0.0, $p['remaining'], 'Remaining floors at 0.' );
    }

    public function test_membership_drive_counts_tagged_members(): void {
        $cid = $this->make_campaign( 'membership_drive', 10, '2030-12-31' );

        foreach ( [ 'm1', 'm2', 'm3' ] as $i => $slug ) {
            create_membership( [
                'donor_email' => "{$slug}@example.test",
                'donor_name'  => "Member {$i}",
                'tier'        => 'single',
                'amount'      => 10,
                'campaign_id' => $cid,
            ] );
        }

        $p = campaign_progress( [ 'campaign_id' => $cid ] );

        $this->assertSame( 'membership_drive', $p['type'] );
        $this->assertSame( 'members', $p['goal_unit'] );
        $this->assertSame( 3, $p['member_count'] );
        $this->assertSame( 30.0, $p['progress'] );
        $this->assertSame( 7.0, $p['remaining'] );
    }

    public function test_membership_drive_respects_tier_filter(): void {
        $cid = $this->make_campaign( 'membership_drive', 10, '2030-12-31', 'single' );

        // Two matching the tier filter, one not.
        create_membership( [ 'donor_email' => 's1@example.test', 'donor_name' => 'S1', 'tier' => 'single', 'amount' => 10, 'campaign_id' => $cid ] );
        create_membership( [ 'donor_email' => 's2@example.test', 'donor_name' => 'S2', 'tier' => 'single', 'amount' => 10, 'campaign_id' => $cid ] );
        create_membership( [ 'donor_email' => 'f1@example.test', 'donor_name' => 'F1', 'tier' => 'family', 'amount' => 25, 'campaign_id' => $cid ] );

        $p = campaign_progress( [ 'campaign_id' => $cid ] );

        $this->assertSame( 2, $p['member_count'], 'Only memberships matching the tier filter count.' );
    }

    public function test_is_active_false_when_end_date_passed(): void {
        $cid = $this->make_campaign( 'donation_drive', 1000, '2020-01-01' );

        $p = campaign_progress( [ 'campaign_id' => $cid ] );

        $this->assertFalse( $p['is_active'] );
    }

    public function test_invalid_campaign_id_returns_error(): void {
        $result = campaign_progress( [ 'campaign_id' => 0 ] );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'invalid_campaign_id', $result->get_error_code() );
    }
}
