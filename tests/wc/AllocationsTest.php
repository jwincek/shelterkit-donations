<?php
/**
 * The canonical fund list (#21).
 *
 * Before Core\Allocations, four layers each kept their own list of funds: the
 * ability's input enum (two slugs), the donation form (seven), the
 * activator's variations (five) and the legacy importer. A gift to any fund
 * one of them lacked was paid for and then either rejected or recorded
 * against another fund. These tests pin the single list and every layer that
 * now reads it.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\WC;

use Starter_Shelter\Core\Allocations;
use Starter_Shelter\Core\Config;
use Starter_Shelter\Core\Field_Manifest;
use WP_UnitTestCase;

final class AllocationsTest extends WP_UnitTestCase {

    /**
     * The shipped default slugs. They are stored on every donation, so they
     * are frozen: see CONTRIBUTING.
     */
    private const FROZEN_DEFAULTS = [
        'general-fund',
        'medical-care',
        'food-supplies',
        'facility',
        'rescue-operations',
        'emergency-fund',
        'spay-neuter-clinic',
    ];

    public function tear_down(): void {
        Config::delete_override( 'settings', 'allocations' );
        Config::delete_override( 'settings', 'retired_allocations' );
        delete_option( 'sd_allocations_version' );
        delete_option( 'sd_donation_product_id' );
        Field_Manifest::clear_cache();
        Config::clear_cache( 'entities' );
        parent::tear_down();
    }

    public function test_the_shipped_defaults_are_the_frozen_seven(): void {
        $this->assertSame( self::FROZEN_DEFAULTS, array_keys( Allocations::active() ) );
        $this->assertSame( [], Allocations::retired() );
    }

    /**
     * @dataProvider names_of_funds
     */
    public function test_canonical_resolves_every_way_a_fund_is_named( string $value, string $expected ): void {
        $this->assertSame( $expected, Allocations::canonical( $value ) );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function names_of_funds(): array {
        return [
            'slug'                         => [ 'medical-care', 'medical-care' ],
            'alias kept for old forms'     => [ 'spay-neuter', 'spay-neuter-clinic' ],
            'variation label, odd spacing' => [ 'Spay / Neuter Clinic', 'spay-neuter-clinic' ],
            'label unlike its slug'        => [ 'Facility Improvements', 'facility' ],
            '2.x activator label'          => [ 'Rescue Ops', 'rescue-operations' ],
            'label with a price suffix'    => [ 'General Fund - $10', 'general-fund' ],
            'unknown fund'                 => [ 'bedding', '' ],
            'empty'                        => [ '', '' ],
        ];
    }

    public function test_a_list_saved_under_an_alias_reads_as_the_canonical_slug(): void {
        // As stored on a site that saved the settings screen before the fund was renamed.
        Config::save_override( 'settings', 'allocations', [
            'general-fund' => 'General Fund',
            'spay-neuter'  => 'Spay/Neuter Program',
        ] );

        $this->assertSame(
            [ 'general-fund' => 'General Fund', 'spay-neuter-clinic' => 'Spay/Neuter Program' ],
            Allocations::active(),
            'The key is canonical; the shelter\'s own label is kept.'
        );
    }

    public function test_the_ability_accepts_every_fund_on_the_list(): void {
        Config::save_override( 'settings', 'allocations', [ 'general-fund' => 'General Fund', 'bedding' => 'Bedding' ] );
        Config::save_override( 'settings', 'retired_allocations', [ 'old-roof' => 'Roof Appeal' ] );
        Field_Manifest::clear_cache();

        $schema = Field_Manifest::get_abilities()['shelter-donations/create']['input_schema']['properties']['allocation'];

        $this->assertSame( [ 'general-fund', 'bedding', 'old-roof' ], $schema['enum'], 'Active then retired, and nothing else.' );
        $this->assertArrayNotHasKey( 'enum_source', $schema, 'The source key does not leak into the JSON Schema.' );
    }

    public function test_csv_import_validates_against_the_same_list(): void {
        Config::save_override( 'settings', 'allocations', [ 'general-fund' => 'General Fund', 'bedding' => 'Bedding' ] );
        Field_Manifest::clear_cache();
        Config::clear_cache( 'entities' );

        $this->assertSame( [ 'general-fund', 'bedding' ], Config::get_path( 'entities', 'entities.sd_donation.fields.allocation.enum' ) );
    }

    public function test_removing_a_fund_retires_it(): void {
        $this->assertTrue( Allocations::save( [ 'general-fund' => 'General Fund', 'medical-care' => 'Medical Care' ] ) );
        $this->assertTrue( Allocations::save( [ 'general-fund' => 'General Fund' ] ) );

        $this->assertFalse( Allocations::is_active( 'medical-care' ) );
        $this->assertSame( 'Medical Care', Allocations::retired()['medical-care'] ?? null );
        $this->assertSame( 'medical-care', Allocations::canonical( 'medical-care' ), 'Still a valid fund for records that carry it.' );
    }

    public function test_a_retired_fund_can_be_restored(): void {
        Allocations::save( [ 'general-fund' => 'General Fund', 'medical-care' => 'Vet Bills' ] );
        Allocations::save( [ 'general-fund' => 'General Fund' ] );

        Allocations::save( [ 'general-fund' => 'General Fund' ], [ 'medical-care' ] );

        $this->assertSame( 'Vet Bills', Allocations::active()['medical-care'] ?? null, 'Restored with the label it had.' );
        $this->assertArrayNotHasKey( 'medical-care', Allocations::retired() );
    }

    public function test_a_changed_slug_retires_the_old_fund_instead_of_renaming_it(): void {
        Allocations::save( [ 'general-fund' => 'General Fund', 'medical-care' => 'Medical Care' ] );

        Allocations::save( [ 'general-fund' => 'General Fund', 'vet-care' => 'Medical Care' ] );

        $this->assertArrayHasKey( 'vet-care', Allocations::active() );
        $this->assertArrayHasKey( 'medical-care', Allocations::retired(), 'Gifts filed under the old slug keep a valid fund.' );
    }

    public function test_the_last_fund_cannot_be_removed(): void {
        Allocations::save( [ 'general-fund' => 'General Fund' ] );

        $this->assertFalse( Allocations::save( [] ) );
        $this->assertSame( [ 'general-fund' => 'General Fund' ], Allocations::active() );
    }

    public function test_sync_links_existing_variations_and_creates_missing_ones(): void {
        // A donation product as 2.x and 3.0.0 created it, with labels worded
        // differently from the setting.
        $product_id = $this->donation_product( [ 'General Fund', 'Medical Care', 'Spay / Neuter Clinic', 'Rescue Ops', 'Facility Improvements' ] );

        Allocations::sync_variations();

        $product = wc_get_product( $product_id );
        $linked  = [];
        foreach ( $product->get_children() as $id ) {
            $linked[ wc_get_product( $id )->get_attribute( 'preferred-allocation' ) ] = get_post_meta( $id, Allocations::VARIATION_META, true );
        }
        ksort( $linked );

        $this->assertSame(
            [
                'Emergency Fund'        => 'emergency-fund',
                'Facility Improvements' => 'facility',
                'Food & Supplies'       => 'food-supplies',
                'General Fund'          => 'general-fund',
                'Medical Care'          => 'medical-care',
                'Rescue Ops'            => 'rescue-operations',
                'Spay / Neuter Clinic'  => 'spay-neuter-clinic',
            ],
            $linked,
            'Five linked by label or alias, two created; no fund left without a variation.'
        );

        $variation_id = Allocations::find_variation_id( $product, 'emergency-fund' );
        $this->assertGreaterThan( 0, $variation_id );
        $this->assertTrue( wc_get_product( $variation_id )->is_purchasable(), 'A created variation can be sold.' );
    }

    public function test_a_retired_funds_variation_is_not_reported_as_a_problem(): void {
        $this->donation_product( [ 'General Fund', 'Medical Care' ] );
        Allocations::save( [ 'general-fund' => 'General Fund', 'medical-care' => 'Medical Care' ] );

        Allocations::save( [ 'general-fund' => 'General Fund' ] );

        $messages = array_column(
            array_filter( \Starter_Shelter\Admin\Product_Sync_Checker::check()['issues'], static fn( $i ) => 'shelter-donations' === $i['context'] ),
            'message'
        );
        $this->assertSame( [], preg_grep( '/variations, config expects|Medical Care/', $messages ), 'Kept on purpose, for the orders that point at it.' );
    }

    public function test_repair_keeps_its_other_fixes_to_the_donation_product(): void {
        $product_id = $this->donation_product( [ 'General Fund' ] );
        $product    = wc_get_product( $product_id );
        $product->set_catalog_visibility( 'visible' );
        $product->set_tax_status( 'taxable' );
        $product->save();

        \Starter_Shelter\Admin\Product_Sync_Checker::repair();

        $product = wc_get_product( $product_id );
        $this->assertSame( 'hidden', $product->get_catalog_visibility(), 'Saved, not just reported.' );
        $this->assertSame( 'none', $product->get_tax_status() );
    }

    public function test_migration_keeps_funds_already_recorded_on_donations(): void {
        Config::save_override( 'settings', 'allocations', [ 'general-fund' => 'General Fund', 'spay-neuter' => 'Spay/Neuter Program' ] );
        $this->donation_with_allocation( 'spay-neuter-clinic' );
        $this->donation_with_allocation( 'old-roof' );

        Allocations::maybe_migrate();

        $this->assertSame(
            [ 'general-fund' => 'General Fund', 'spay-neuter-clinic' => 'Spay/Neuter Program' ],
            get_option( 'sd_config_settings_allocations' ),
            'The stored key is rewritten to the canonical slug.'
        );
        $this->assertSame( [ 'old-roof' ], array_keys( Allocations::retired() ), 'A recorded fund missing from the list is kept, retired.' );
        $this->assertSame( '1', get_option( 'sd_allocations_version' ) );
    }

    public function test_memorial_types_match_the_memorial_enum(): void {
        // "honor" moved to dedication_type; the subject is only person or pet.
        $this->assertSame(
            Config::get_path( 'entities', 'entities.sd_memorial.fields.memorial_type.enum' ),
            array_keys( (array) Config::get_item( 'settings', 'memorial_types', [] ) )
        );
    }

    /**
     * @param string[] $labels Variation labels.
     * @return int Product ID, also stored where the plugin looks for it.
     */
    private function donation_product( array $labels ): int {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name( 'Preferred Allocation' );
        $attribute->set_options( $labels );
        $attribute->set_visible( true );
        $attribute->set_variation( true );

        $product = new \WC_Product_Variable();
        $product->set_name( 'Shelter Donations' );
        $product->set_sku( 'shelter-donations' );
        $product->set_attributes( [ $attribute ] );
        $product_id = $product->save();

        foreach ( $labels as $label ) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id( $product_id );
            $variation->set_regular_price( '10' );
            $variation->set_attributes( [ 'preferred-allocation' => $label ] );
            $variation->save();
        }

        update_option( 'sd_donation_product_id', $product_id );

        return $product_id;
    }

    private function donation_with_allocation( string $allocation ): void {
        $id = self::factory()->post->create( [ 'post_type' => 'sd_donation' ] );
        update_post_meta( $id, '_sd_allocation', $allocation );
    }
}
