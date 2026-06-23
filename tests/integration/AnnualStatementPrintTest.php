<?php
/**
 * Integration tests for the printable contribution statement.
 *
 * My_Account::build_print_statement_html() turns the annual-summary output
 * into a standalone, print-optimized HTML receipt (browser "Save as PDF").
 * Exercises the builder against real summary data.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\WooCommerce\My_Account;
use WP_UnitTestCase;

use function Starter_Shelter\Helpers\get_or_create_donor;

final class AnnualStatementPrintTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        foreach ( [ 'donations', 'memorials', 'memberships', 'reports', 'donors' ] as $f ) {
            require_once dirname( __DIR__, 2 ) . "/includes/abilities/{$f}.php";
        }
    }

    private function summary_for_year_with_gifts(): array {
        $donor_id = get_or_create_donor( 'jamie@example.test', 'Jamie Rivera' );
        \Starter_Shelter\Abilities\Donors\update_address( [
            'donor_id' => $donor_id,
            'address'  => [ 'address_1' => '123 Shelter Way', 'city' => 'Petville', 'state' => 'OH', 'postcode' => '44001' ],
        ] );

        \Starter_Shelter\Abilities\Donations\create( [ 'donor_id' => $donor_id, 'amount' => 100, 'allocation' => 'general-fund', 'donation_date' => '2024-03-01' ] );
        \Starter_Shelter\Abilities\Memorials\create( [ 'donor_id' => $donor_id, 'honoree_name' => 'Old Rex', 'amount' => 50, 'donation_date' => '2024-04-01' ] );
        \Starter_Shelter\Abilities\Memberships\create( [ 'donor_id' => $donor_id, 'tier' => 'single', 'amount' => 45, 'date' => '2024-01-15' ] );

        return \Starter_Shelter\Abilities\Reports\annual_summary( [ 'donor_id' => $donor_id, 'year' => 2024 ] );
    }

    public function test_statement_html_includes_donor_totals_and_tax_note(): void {
        $summary = $this->summary_for_year_with_gifts();
        $html    = My_Account::build_print_statement_html( $summary, 2024 );

        $this->assertStringContainsString( '<!DOCTYPE html>', $html );
        $this->assertStringContainsString( 'Jamie Rivera', $html );
        $this->assertStringContainsString( '123 Shelter Way', $html, 'Donor address (hydrated) appears.' );
        $this->assertStringContainsString( '2024', $html );
        $this->assertStringContainsString( '$195.00', $html, 'Grand total of 100 + 50 + 45.' );
        $this->assertStringContainsString( 'Old Rex', $html, 'Memorial line item is itemized.' );
        $this->assertStringContainsString( 'No goods or services', $html, 'Deductibility note present.' );
        $this->assertStringContainsString( get_bloginfo( 'name' ), $html, 'Organization name present.' );
        $this->assertStringContainsString( 'window.print()', $html, 'Auto-print + button.' );
    }

    public function test_tax_note_is_filterable(): void {
        $summary = $this->summary_for_year_with_gifts();

        add_filter( 'starter_shelter_receipt_tax_note', static fn () => 'EIN 12-3456789 — 501(c)(3).' );
        $html = My_Account::build_print_statement_html( $summary, 2024 );
        remove_all_filters( 'starter_shelter_receipt_tax_note' );

        $this->assertStringContainsString( 'EIN 12-3456789', $html );
    }

    public function test_empty_year_renders_no_contributions(): void {
        $donor_id = get_or_create_donor( 'empty@example.test', 'No Gifts' );
        $summary  = \Starter_Shelter\Abilities\Reports\annual_summary( [ 'donor_id' => $donor_id, 'year' => 2019 ] );

        $html = My_Account::build_print_statement_html( $summary, 2019 );

        $this->assertStringContainsString( 'No contributions recorded', $html );
    }

    /**
     * Invoke the private default-year resolver.
     */
    private function default_year( int $donor_id ): int {
        $m = new \ReflectionMethod( My_Account::class, 'default_statement_year' );
        $m->setAccessible( true );
        return (int) $m->invoke( null, $donor_id );
    }

    public function test_default_year_falls_back_to_most_recent_gift_year(): void {
        // All gifts are in the *current* year, so the prior-year default has
        // no data and the dropdown would never offer it. The resolver should
        // fall back to the most recent year that actually has contributions,
        // so the selector and the statement stay in sync (the reported bug).
        $current  = (int) wp_date( 'Y' );
        $donor_id = get_or_create_donor( 'current@example.test', 'Current Giver' );
        \Starter_Shelter\Abilities\Donations\create( [ 'donor_id' => $donor_id, 'amount' => 25, 'allocation' => 'general-fund', 'donation_date' => "{$current}-02-07" ] );

        $this->assertSame( $current, $this->default_year( $donor_id ) );
    }

    public function test_default_year_prefers_prior_completed_year_when_it_has_gifts(): void {
        $prior    = (int) wp_date( 'Y' ) - 1;
        $current  = (int) wp_date( 'Y' );
        $donor_id = get_or_create_donor( 'both@example.test', 'Both Years' );
        \Starter_Shelter\Abilities\Donations\create( [ 'donor_id' => $donor_id, 'amount' => 25, 'allocation' => 'general-fund', 'donation_date' => "{$prior}-06-01" ] );
        \Starter_Shelter\Abilities\Donations\create( [ 'donor_id' => $donor_id, 'amount' => 25, 'allocation' => 'general-fund', 'donation_date' => "{$current}-06-01" ] );

        $this->assertSame( $prior, $this->default_year( $donor_id ) );
    }

    public function test_default_year_is_prior_year_when_no_gifts(): void {
        $donor_id = get_or_create_donor( 'none@example.test', 'No Gifts At All' );

        $this->assertSame( (int) wp_date( 'Y' ) - 1, $this->default_year( $donor_id ) );
    }
}
