<?php
/**
 * Integration tests for shelter-memberships/recognition-wall.
 *
 * Powers the public Members Wall block. Must include active members who
 * haven't opted out (resolving a display name — business name for business
 * members, donor display name otherwise), exclude anonymous and expired
 * members, expose business logos only, and sort highest tier first then
 * alphabetically.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memberships\create;
use function Starter_Shelter\Abilities\Memberships\recognition_wall;

final class MembersWallTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memberships\\recognition_wall' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memberships.php';
        }
    }

    private function make_logo(): int {
        $att = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
        update_attached_file( $att, '2026/01/logo.jpg' );
        wp_update_attachment_metadata( $att, [
            'file'  => '2026/01/logo.jpg',
            'sizes' => [ 'medium' => [ 'file' => 'logo-300x300.jpg', 'width' => 300, 'height' => 300, 'mime-type' => 'image/jpeg' ] ],
        ] );
        return $att;
    }

    /**
     * @param array{name?:string,type?:string,tier?:string,active?:bool,anonymous?:bool,with_logo?:bool} $args
     */
    private function make_member( array $args = [] ): int {
        $a = array_merge( [
            'name'      => 'Member',
            'type'      => 'individual',
            'tier'      => 'single',
            'active'    => true,
            'anonymous' => false,
            'with_logo' => false,
        ], $args );

        $is_business = 'business' === $a['type'];
        $logo_id     = ( $is_business && $a['with_logo'] ) ? $this->make_logo() : 0;

        $res = create( [
            'donor_email'        => strtolower( preg_replace( '/\s+/', '', $a['name'] ) ) . '@example.test',
            'donor_name'         => $a['name'],
            'membership_type'    => $a['type'],
            'tier'               => $a['tier'],
            'amount'             => 100,
            'date'               => $a['active'] ? '2026-01-15' : '2020-01-01',
            'is_anonymous'       => $a['anonymous'],
            'business_name'      => $is_business ? $a['name'] : null,
            'logo_attachment_id' => $logo_id ?: null,
        ] );
        $id = $res['membership_id'];
        if ( $logo_id ) {
            update_post_meta( $id, '_sd_logo_attachment_id', $logo_id );
        }
        return $id;
    }

    public function test_includes_active_members_and_resolves_display_names(): void {
        $this->make_member( [ 'name' => 'Acme Co', 'type' => 'business', 'tier' => 'donor', 'with_logo' => true ] );
        $this->make_member( [ 'name' => 'Jane Smith', 'type' => 'individual', 'tier' => 'single' ] );

        $wall  = recognition_wall();
        $names = array_column( $wall['items'], 'display_name' );

        $this->assertSame( 2, $wall['total'] );
        $this->assertContains( 'Acme Co', $names );
        $this->assertContains( 'Jane Smith', $names );
    }

    public function test_excludes_anonymous_members(): void {
        $this->make_member( [ 'name' => 'Public Pat' ] );
        $this->make_member( [ 'name' => 'Private Pat', 'anonymous' => true ] );

        $names = array_column( recognition_wall()['items'], 'display_name' );

        $this->assertSame( [ 'Public Pat' ], $names );
    }

    public function test_excludes_expired_members(): void {
        $this->make_member( [ 'name' => 'Current Member', 'active' => true ] );
        $this->make_member( [ 'name' => 'Lapsed Member', 'active' => false ] );

        $names = array_column( recognition_wall()['items'], 'display_name' );

        $this->assertSame( [ 'Current Member' ], $names );
    }

    public function test_logo_url_only_for_business_members(): void {
        $this->make_member( [ 'name' => 'Biz Co', 'type' => 'business', 'tier' => 'donor', 'with_logo' => true ] );
        $this->make_member( [ 'name' => 'Indie Person', 'type' => 'individual' ] );

        $by_name = [];
        foreach ( recognition_wall()['items'] as $item ) {
            $by_name[ $item['display_name'] ] = $item;
        }

        $this->assertNotEmpty( $by_name['Biz Co']['logo_url'] );
        $this->assertSame( '', $by_name['Indie Person']['logo_url'] );
    }

    public function test_sorted_by_tier_then_name(): void {
        // Individual 'single' tier = $10; business 'donor' tier = $250.
        $this->make_member( [ 'name' => 'Zoe Individual', 'type' => 'individual', 'tier' => 'single' ] );
        $this->make_member( [ 'name' => 'Amy Individual', 'type' => 'individual', 'tier' => 'single' ] );
        $this->make_member( [ 'name' => 'Beta Business', 'type' => 'business', 'tier' => 'donor', 'with_logo' => true ] );

        $names = array_column( recognition_wall()['items'], 'display_name' );

        // Highest tier first (Beta, $250), then the $10 tier alphabetically.
        $this->assertSame( [ 'Beta Business', 'Amy Individual', 'Zoe Individual' ], $names );
    }
}
