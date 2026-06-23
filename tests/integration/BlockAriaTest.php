<?php
/**
 * Accessibility (ARIA) regression tests for the SSR recognition blocks.
 *
 * Renders the blocks via do_blocks() and asserts the accessibility contract:
 * - Members Wall tier sections are labelled by their heading (named regions).
 * - Business Slideshow's slide picker is a labelled group using aria-current,
 *   not a half-built tablist (aria-selected is invalid on a plain button).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memberships\create;

final class BlockAriaTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memberships\\create' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memberships.php';
        }
    }

    /** Create an image attachment whose 'medium' size URL resolves. */
    private function make_logo(): int {
        $att = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
        update_attached_file( $att, '2026/01/logo.jpg' );
        wp_update_attachment_metadata( $att, [
            'file'  => '2026/01/logo.jpg',
            'sizes' => [ 'medium' => [ 'file' => 'logo-300x300.jpg', 'width' => 300, 'height' => 300, 'mime-type' => 'image/jpeg' ] ],
        ] );
        return $att;
    }

    private function make_business( string $name ): int {
        $logo_id = $this->make_logo();
        $res     = create( [
            'donor_email'        => strtolower( preg_replace( '/\s+/', '', $name ) ) . '@example.test',
            'donor_name'         => 'Owner',
            'membership_type'    => 'business',
            'tier'               => 'donor',
            'amount'             => 250,
            'date'               => '2026-01-15', // Active (end_date a year out).
            'business_name'      => $name,
            'logo_attachment_id' => $logo_id,
        ] );
        $id = $res['membership_id'];
        update_post_meta( $id, '_sd_logo_status', 'approved' );
        update_post_meta( $id, '_sd_logo_attachment_id', $logo_id );
        return $id;
    }

    public function test_members_wall_tier_sections_are_labelled(): void {
        create( [
            'donor_email'     => 'mw@example.test',
            'donor_name'      => 'Wall Member',
            'membership_type' => 'individual',
            'tier'            => 'donor',
            'amount'          => 250,
            'date'            => '2026-01-15',
        ] );

        $html = do_blocks( '<!-- wp:starter-shelter/members-wall /-->' );

        $this->assertStringContainsString( 'role="list"', $html );
        $this->assertStringContainsString( 'aria-labelledby="sd-mw-tier-', $html, 'Tier section is labelled by its heading.' );
        $this->assertMatchesRegularExpression( '/<h3[^>]*\bid="sd-mw-tier-/', $html, 'Heading carries the referenced id.' );
    }

    public function test_business_slideshow_slide_picker_uses_group_and_aria_current(): void {
        // Dots only render with 2+ slides ($has_controls = count > 1).
        $this->make_business( 'Acme Pets' );
        $this->make_business( 'Marigold Vet' );

        $html = do_blocks( '<!-- wp:starter-shelter/business-slideshow /-->' );

        $this->assertStringContainsString( 'role="group"', $html );
        $this->assertStringContainsString( 'aria-current', $html, 'Active slide marked with aria-current.' );
        $this->assertStringNotContainsString( 'role="tablist"', $html, 'Not a tablist — slides are carousel groups, not tabpanels.' );
        $this->assertStringNotContainsString( 'aria-selected', $html, 'aria-selected is invalid on a plain button.' );
    }
}
