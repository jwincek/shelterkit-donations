<?php
/**
 * Integration tests for the SQL aggregation reports.
 *
 * Covers shelter-donations/get-stats and shelter-reports/dashboard-stats
 * — raw SUM/COUNT/COUNT(DISTINCT) aggregates the Query builder can't
 * express, so they're hand-written SQL and exactly where a silent
 * miscount would hide. Fixtures are dated in the past and queried with
 * the `all_time` period (2000-01-01 → today) for deterministic inclusion
 * regardless of when the suite runs.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Donations\create as create_donation;
use function Starter_Shelter\Abilities\Donations\get_stats;
use function Starter_Shelter\Abilities\Memberships\create as create_membership;
use function Starter_Shelter\Abilities\Memorials\create as create_memorial;
use function Starter_Shelter\Abilities\Reports\dashboard_stats;

final class StatsAggregationTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        foreach ( [ 'donations', 'memberships', 'memorials', 'reports' ] as $f ) {
            require_once dirname( __DIR__, 2 ) . "/includes/abilities/{$f}.php";
        }
    }

    /**
     * Seed donor A (two gifts) + donor B (one) — total 175 across 3 gifts,
     * 2 distinct donors, two allocations.
     */
    private function seed_donations(): void {
        create_donation( [ 'donor_email' => 'a@example.test', 'donor_name' => 'A', 'amount' => 100, 'allocation' => 'general-fund', 'donation_date' => '2022-01-10' ] );
        create_donation( [ 'donor_email' => 'a@example.test', 'donor_name' => 'A', 'amount' => 50,  'allocation' => 'medical-fund', 'donation_date' => '2022-02-10' ] );
        create_donation( [ 'donor_email' => 'b@example.test', 'donor_name' => 'B', 'amount' => 25,  'allocation' => 'general-fund', 'donation_date' => '2022-03-10' ] );
    }

    public function test_get_stats_totals_count_and_distinct_donors(): void {
        $this->seed_donations();

        $stats = get_stats( [ 'period' => 'all_time' ] );

        $this->assertSame( 175.0, $stats['total_amount'] );
        $this->assertSame( 3, $stats['donation_count'] );
        $this->assertSame( 2, $stats['donor_count'] );
        $this->assertSame( 58.33, $stats['average_amount'] );
    }

    public function test_get_stats_by_allocation_breakdown(): void {
        $this->seed_donations();

        $by = get_stats( [ 'period' => 'all_time' ] )['by_allocation'];

        $this->assertSame( 2, $by['general-fund']['count'] );
        $this->assertSame( 125.0, $by['general-fund']['total'] );
        $this->assertSame( 1, $by['medical-fund']['count'] );
        $this->assertSame( 50.0, $by['medical-fund']['total'] );
    }

    public function test_get_stats_scopes_to_campaign(): void {
        $cid = self::factory()->term->create( [ 'taxonomy' => 'sd_campaign', 'name' => 'Drive' ] );

        create_donation( [ 'donor_email' => 'a@example.test', 'donor_name' => 'A', 'amount' => 100, 'campaign_id' => $cid, 'donation_date' => '2022-01-10' ] );
        create_donation( [ 'donor_email' => 'b@example.test', 'donor_name' => 'B', 'amount' => 999, 'donation_date' => '2022-01-10' ] ); // untagged

        $stats = get_stats( [ 'period' => 'all_time', 'campaign_id' => $cid ] );

        $this->assertSame( 1, $stats['donation_count'] );
        $this->assertSame( 100.0, $stats['total_amount'] );
    }

    public function test_dashboard_stats_rolls_up_all_entities(): void {
        // Donations: A x2 + B x1 → total 175, count 3, 2 unique donors.
        create_donation( [ 'donor_email' => 'a@example.test', 'donor_name' => 'A', 'amount' => 100, 'donation_date' => '2026-02-01' ] );
        create_donation( [ 'donor_email' => 'a@example.test', 'donor_name' => 'A', 'amount' => 50,  'donation_date' => '2026-02-15' ] );
        create_donation( [ 'donor_email' => 'b@example.test', 'donor_name' => 'B', 'amount' => 25,  'donation_date' => '2026-03-01' ] );

        // Membership starting this year → active (end +1yr is future) + new.
        create_membership( [ 'donor_email' => 'm@example.test', 'donor_name' => 'M', 'tier' => 'single', 'amount' => 45, 'date' => '2026-01-15' ] );

        // Memorial dated this year.
        create_memorial( [ 'donor_email' => 'mem@example.test', 'donor_name' => 'Mem', 'honoree_name' => 'Rex', 'memorial_type' => 'pet', 'amount' => 30, 'donation_date' => '2026-02-10' ] );

        $stats = dashboard_stats( [ 'period' => 'all_time' ] );

        $this->assertSame( 175.0, $stats['donations']['total'] );
        $this->assertSame( 3, $stats['donations']['count'] );
        $this->assertSame( 2, $stats['donations']['unique_donors'] );

        $this->assertSame( 1, $stats['memberships']['active'] );
        $this->assertSame( 1, $stats['memberships']['new'] );
        $this->assertSame( 45.0, $stats['memberships']['revenue'] );

        $this->assertSame( 1, $stats['memorials']['total'] );
        $this->assertSame( 30.0, $stats['memorials']['revenue'] );

        // Four distinct donor emails created above.
        $this->assertSame( 4, $stats['donors']['total'] );
    }
}
