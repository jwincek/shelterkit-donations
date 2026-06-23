<?php
/**
 * Integration test for the memorial admin meta boxes.
 *
 * The front-end memorial form collects a family mailing address (and a
 * "send physical card" flag) under the notify-family section. Those flat
 * `notify_family_*` keys are the canonical storage, so the admin create/
 * edit screen must expose the same fields — otherwise an admin can't see
 * or correct what a donor entered. Guards against the front-end/admin
 * field divergence.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use Starter_Shelter\Core\Field_Manifest;
use WP_UnitTestCase;

final class MemorialAdminFieldsTest extends WP_UnitTestCase {

    private function family_box_fields(): array {
        $boxes = Field_Manifest::get_meta_boxes();
        return $boxes['sd_memorial']['boxes']['family_notification']['fields'] ?? [];
    }

    public function test_family_notification_box_exposes_mailing_address_and_card_flag(): void {
        $fields = $this->family_box_fields();

        $this->assertArrayHasKey( 'notify_family_address', $fields, 'Mailing address (front-end field) must be editable in admin.' );
        $this->assertSame( 'textarea', $fields['notify_family_address']['type'] );

        $this->assertArrayHasKey( 'notify_family_send_card', $fields );
        $this->assertSame( 'checkbox', $fields['notify_family_send_card']['type'] );
    }

    public function test_address_and_card_reveal_with_the_notify_toggle(): void {
        $fields = $this->family_box_fields();

        // Like name/email, the extra fields only show once notify is enabled.
        foreach ( [ 'notify_family_address', 'notify_family_send_card' ] as $id ) {
            $this->assertSame(
                [ 'notify_family_enabled' => true ],
                $fields[ $id ]['show_when'] ?? null,
                "{$id} should be gated on the notify-family toggle."
            );
        }
    }
}
