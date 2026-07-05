<?php
/**
 * Integration tests for the memorial candle toggle endpoint.
 *
 * Anonymous lit-state is governed by a server-side IP-hash transient, not
 * the forgeable `sd_candles` cookie — otherwise clearing the cookie between
 * toggles would let a visitor inflate `_sd_candle_count` without bound
 * (the rate limiter only throttles to 1 request per 2s). Guards that the
 * has_anonymous_candle() check is actually enforced.
 *
 * @package Starter_Shelter
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Tests\Integration;

use WP_UnitTestCase;
use WP_REST_Request;

use function Starter_Shelter\REST\toggle_candle;
use function Starter_Shelter\REST\get_rate_limit_key;

final class CandleToggleTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        require_once dirname( __DIR__, 2 ) . '/includes/rest/class-candles-controller.php';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        unset( $_COOKIE['sd_candles'] );
    }

    public function tear_down(): void {
        unset( $_COOKIE['sd_candles'] );
        parent::tear_down();
    }

    /** The 2s rate limiter would 429 a rapid second toggle; clear it to model an attacker pacing requests. */
    private function bypass_rate_limit(): void {
        delete_transient( 'sd_candle_rate_' . get_rate_limit_key() );
    }

    private function toggle( int $memorial_id ): array {
        $req = new WP_REST_Request( 'POST', '/shelter-donations/v1/candles/toggle' );
        $req->set_param( 'memorial_id', $memorial_id );
        return toggle_candle( $req )->get_data();
    }

    public function test_anonymous_cannot_inflate_count_by_clearing_cookie(): void {
        wp_set_current_user( 0 );
        $memorial = self::factory()->post->create( [ 'post_type' => 'sd_memorial' ] );

        // First light: count 1, IP transient records it.
        $first = $this->toggle( $memorial );
        $this->assertTrue( $first['lit'] );
        $this->assertSame( 1, $first['count'] );

        // Attacker clears the cookie and paces past the rate limiter, then
        // toggles again. IP tracking is authoritative, so this UNLIGHTS the
        // existing candle (count 0) rather than incrementing to 2.
        unset( $_COOKIE['sd_candles'] );
        $this->bypass_rate_limit();

        $second = $this->toggle( $memorial );
        $this->assertFalse( $second['lit'], 'Server-side IP state, not the cleared cookie, decides lit-state.' );
        $this->assertSame( 0, $second['count'], 'Count cannot be inflated past 1 by cookie clearing.' );
    }

    public function test_logged_in_toggle_round_trips_via_user_meta(): void {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $memorial = self::factory()->post->create( [ 'post_type' => 'sd_memorial' ] );

        $lit = $this->toggle( $memorial );
        $this->assertTrue( $lit['lit'] );
        $this->assertSame( 1, $lit['count'] );

        $this->bypass_rate_limit();

        $unlit = $this->toggle( $memorial );
        $this->assertFalse( $unlit['lit'] );
        $this->assertSame( 0, $unlit['count'] );
    }
}
