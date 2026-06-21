<?php
/**
 * Integration tests for the shelter-memorials/create data flow.
 *
 * Exercises create() against a real WordPress + database: donor
 * resolution, the per-tribute display-name preference (the e2c32d3 fix),
 * dedication persistence, and the anonymous path. These are the data-flow
 * paths the manifest validator cannot see.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memorials\create;
use function Starter_Shelter\Helpers\get_or_create_donor;

final class MemorialCreateTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        // The Provider require_once's this on wp_abilities_api_init during
        // boot; guard in case of ordering so create() is always callable.
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memorials\\create' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memorials.php';
        }
    }

    /**
     * The e2c32d3 fix: a returning donor email must not overwrite the name
     * typed on THIS tribute. get_or_create_donor doesn't rename an
     * existing donor, so the memorial's denormalized name must come from
     * the supplied donor_name, not the stored donor record.
     */
    public function test_memorial_uses_typed_name_not_stored_donor_name(): void {
        $email   = 'returning@example.test';
        $donor_id = get_or_create_donor( $email, 'Stored Donor Name' );
        $this->assertIsInt( $donor_id );
        $this->assertSame( 'Stored Donor Name', get_post_meta( $donor_id, '_sd_display_name', true ) );

        $result = create( [
            'donor_email'   => $email,
            'donor_name'    => 'Typed Different Name',
            'honoree_name'  => 'Beloved Rex',
            'memorial_type' => 'person',
            'amount'        => 25,
        ] );

        $this->assertIsArray( $result );
        $this->assertSame( $donor_id, $result['donor_id'], 'Returning email should reuse the donor record.' );

        $memorial_id = $result['memorial_id'];
        $this->assertSame(
            'Typed Different Name',
            get_post_meta( $memorial_id, '_sd_donor_display_name', true ),
            'Memorial "From" must reflect the typed name.'
        );
        $this->assertSame(
            'Stored Donor Name',
            get_post_meta( $donor_id, '_sd_display_name', true ),
            'Canonical donor record must be left untouched.'
        );
    }

    /**
     * Dedication type round-trips into _sd_dedication_type for both values.
     *
     * @dataProvider dedicationProvider
     */
    public function test_dedication_type_is_persisted( string $input_value, string $expected ): void {
        $result = create( [
            'donor_email'     => 'ded@example.test',
            'donor_name'      => 'Ded Tester',
            'honoree_name'    => 'Honoree',
            'memorial_type'   => 'person',
            'dedication_type' => $input_value,
            'amount'          => 10,
        ] );

        $this->assertIsArray( $result );
        $this->assertSame(
            $expected,
            get_post_meta( $result['memorial_id'], '_sd_dedication_type', true )
        );
    }

    public static function dedicationProvider(): array {
        return [
            'memory persists'        => [ 'memory', 'memory' ],
            'honor persists'         => [ 'honor', 'honor' ],
            'unknown coerces memory' => [ 'whatever', 'memory' ],
        ];
    }

    public function test_dedication_defaults_to_memory_when_omitted(): void {
        $result = create( [
            'donor_email'  => 'nodelegation@example.test',
            'donor_name'   => 'No Dedication',
            'honoree_name' => 'Honoree',
            'amount'       => 5,
        ] );

        $this->assertIsArray( $result );
        $this->assertSame(
            'memory',
            get_post_meta( $result['memorial_id'], '_sd_dedication_type', true )
        );
    }

    public function test_anonymous_memorial_has_empty_display_name(): void {
        $result = create( [
            'donor_email'   => 'anon@example.test',
            'donor_name'    => 'Should Be Hidden',
            'honoree_name'  => 'Honoree',
            'memorial_type' => 'person',
            'is_anonymous'  => true,
            'amount'        => 15,
        ] );

        $this->assertIsArray( $result );
        $this->assertSame(
            '',
            get_post_meta( $result['memorial_id'], '_sd_donor_display_name', true ),
            'Anonymous tributes must not denormalize a donor name.'
        );
    }
}
