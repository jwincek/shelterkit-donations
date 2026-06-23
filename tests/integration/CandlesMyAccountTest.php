<?php
/**
 * Integration tests for the "My Candles" My Account area and the
 * login-time merge of anonymously-lit candles.
 *
 * Candles are stored per user (user_meta `_sd_candles`) and are a permanent
 * tribute. render_my_candles() lists the lit memorials (skipping any that
 * have since been removed); merge_anonymous_candles_on_login() folds a
 * logged-out visitor's cookie candles into their account on login.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;
use Starter_Shelter\WooCommerce\My_Account;

use function Starter_Shelter\Abilities\Memorials\create;
use function Starter_Shelter\REST\merge_anonymous_candles_on_login;

final class CandlesMyAccountTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        require_once dirname( __DIR__, 2 ) . '/includes/abilities/memorials.php';
        require_once dirname( __DIR__, 2 ) . '/includes/rest/class-candles-controller.php';
        unset( $_COOKIE['sd_candles'] );
    }

    public function tear_down(): void {
        unset( $_COOKIE['sd_candles'] );
        parent::tear_down();
    }

    public function test_login_merges_cookie_candles_into_user_meta(): void {
        $user = self::factory()->user->create_and_get();
        update_user_meta( $user->ID, '_sd_candles', [ 101 ] ); // Already owned.

        $_COOKIE['sd_candles'] = wp_json_encode( [ 101, 202, 303 ] ); // Lit while logged out.
        merge_anonymous_candles_on_login( $user->user_login, $user );

        $merged = get_user_meta( $user->ID, '_sd_candles', true );
        sort( $merged );

        $this->assertSame( [ 101, 202, 303 ], $merged, 'Cookie candles merge in, deduped against existing.' );
    }

    public function test_login_merge_is_noop_without_cookie(): void {
        $user = self::factory()->user->create_and_get();
        update_user_meta( $user->ID, '_sd_candles', [ 5 ] );

        merge_anonymous_candles_on_login( $user->user_login, $user );

        $this->assertSame( [ 5 ], get_user_meta( $user->ID, '_sd_candles', true ) );
    }

    public function test_render_lists_lit_memorials_and_skips_removed(): void {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );

        $result = create( [
            'donor_email'  => 'candle@example.test',
            'honoree_name' => 'Old Shep',
            'amount'       => 10,
        ] );
        $memorial_id = $result['memorial_id'];

        // Lit list = one real memorial + one that no longer exists.
        update_user_meta( $user, '_sd_candles', [ $memorial_id, 999000 ] );

        ob_start();
        My_Account::render_my_candles();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'Old Shep', $html, 'Lit memorial is listed.' );
        $this->assertSame( 1, substr_count( $html, 'sd-candle-row' ), 'The removed memorial is skipped.' );
    }

    public function test_render_shows_empty_state_with_no_candles(): void {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        update_user_meta( $user, '_sd_candles', [] );

        ob_start();
        My_Account::render_my_candles();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'sd-candles-empty', $html );
        $this->assertStringNotContainsString( 'sd-candle-row', $html );
    }
}
