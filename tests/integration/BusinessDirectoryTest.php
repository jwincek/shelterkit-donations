<?php
/**
 * Integration tests for shelter-memberships/business-directory.
 *
 * The ability powers the public Business Member Slideshow block: it must
 * return only active business memberships that have an approved, resolvable
 * logo, sorted by business name — and exclude everything else (pending /
 * rejected logos, individual members, expired members, missing logos).
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;

use function Starter_Shelter\Abilities\Memberships\business_directory;
use function Starter_Shelter\Abilities\Memberships\create;

final class BusinessDirectoryTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        if ( ! function_exists( 'Starter_Shelter\\Abilities\\Memberships\\business_directory' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/abilities/memberships.php';
        }
    }

    /** Create an image attachment whose 'medium' size URL resolves. */
    private function make_logo(): int {
        $att = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
        update_attached_file( $att, '2026/01/logo.jpg' );
        wp_update_attachment_metadata( $att, [
            'file'  => '2026/01/logo.jpg',
            'sizes' => [
                'medium' => [ 'file' => 'logo-300x300.jpg', 'width' => 300, 'height' => 300, 'mime-type' => 'image/jpeg' ],
            ],
        ] );
        return $att;
    }

    /**
     * @param array{name?:string,type?:string,tier?:string,logo_status?:string,with_logo?:bool,active?:bool,website?:string} $args
     */
    private function make_member( array $args = [] ): int {
        $a = array_merge( [
            'name'        => 'Biz',
            'type'        => 'business',
            'tier'        => 'donor',
            'logo_status' => 'approved',
            'with_logo'   => true,
            'active'      => true,
            'website'     => '',
        ], $args );

        $logo_id = $a['with_logo'] ? $this->make_logo() : 0;

        $res = create( [
            'donor_email'        => strtolower( preg_replace( '/\s+/', '', $a['name'] ) ) . '@example.test',
            'donor_name'         => 'Owner',
            'membership_type'    => $a['type'],
            'tier'               => $a['tier'],
            'amount'             => 250,
            // Active → end_date (start +1yr) is in the future; inactive → expired.
            'date'               => $a['active'] ? '2026-01-15' : '2020-01-01',
            'business_name'      => $a['name'],
            'logo_attachment_id' => $logo_id ?: null,
        ] );
        $id = $res['membership_id'];

        // Moderation status + logo attachment aren't part of create()'s
        // contract for non-business or for the moderation flow, so set the
        // fixture meta directly.
        update_post_meta( $id, '_sd_logo_status', $a['logo_status'] );
        if ( $logo_id ) {
            update_post_meta( $id, '_sd_logo_attachment_id', $logo_id );
        }
        if ( '' !== $a['website'] ) {
            update_post_meta( $id, '_sd_business_website', $a['website'] );
        }
        return $id;
    }

    public function test_returns_active_business_with_approved_logo(): void {
        $id = $this->make_member( [ 'name' => 'Acme Pets', 'website' => 'https://acme.test' ] );

        $dir = business_directory();

        $this->assertSame( 1, $dir['total'] );
        $item = $dir['items'][0];
        $this->assertSame( $id, $item['id'] );
        $this->assertSame( 'Acme Pets', $item['business_name'] );
        $this->assertSame( 'https://acme.test', $item['business_website'] );
        $this->assertNotEmpty( $item['logo_url'], 'Resolved logo URL must be present.' );
    }

    public function test_excludes_pending_and_rejected_logos(): void {
        $this->make_member( [ 'name' => 'Approved Co', 'logo_status' => 'approved' ] );
        $this->make_member( [ 'name' => 'Pending Co', 'logo_status' => 'pending' ] );
        $this->make_member( [ 'name' => 'Rejected Co', 'logo_status' => 'rejected' ] );

        $dir = business_directory();

        $this->assertSame( 1, $dir['total'] );
        $this->assertSame( 'Approved Co', $dir['items'][0]['business_name'] );
    }

    public function test_excludes_individual_members(): void {
        $this->make_member( [ 'name' => 'Biz Co', 'type' => 'business' ] );
        $this->make_member( [ 'name' => 'Personal', 'type' => 'individual', 'tier' => 'single' ] );

        $dir = business_directory();

        $this->assertSame( 1, $dir['total'] );
        $this->assertSame( 'Biz Co', $dir['items'][0]['business_name'] );
    }

    public function test_excludes_expired_members(): void {
        $this->make_member( [ 'name' => 'Active Co', 'active' => true ] );
        $this->make_member( [ 'name' => 'Expired Co', 'active' => false ] );

        $dir = business_directory();

        $this->assertSame( 1, $dir['total'] );
        $this->assertSame( 'Active Co', $dir['items'][0]['business_name'] );
    }

    public function test_excludes_members_without_a_resolvable_logo(): void {
        // Approved status but no attachment → logo_url is empty → excluded.
        $this->make_member( [ 'name' => 'No Logo Co', 'with_logo' => false ] );

        $dir = business_directory();

        $this->assertSame( 0, $dir['total'] );
    }

    public function test_sorted_alphabetically_by_business_name(): void {
        $this->make_member( [ 'name' => 'Zebra Supplies' ] );
        $this->make_member( [ 'name' => 'Acme Pets' ] );
        $this->make_member( [ 'name' => 'Marigold Vet' ] );

        $names = array_column( business_directory()['items'], 'business_name' );

        $this->assertSame( [ 'Acme Pets', 'Marigold Vet', 'Zebra Supplies' ], $names );
    }
}
