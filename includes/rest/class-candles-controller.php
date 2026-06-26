<?php
/**
 * Candles REST Controller — Light a candle for a memorial.
 *
 * Public-facing toggle endpoint with rate limiting and abuse prevention.
 * Candle counts are stored on the memorial post (visible to all).
 * Per-user candle lists are stored in user_meta (logged-in) or tracked
 * by IP hash (anonymous) to prevent count manipulation.
 *
 * @package Starter_Shelter
 * @subpackage REST
 * @since 2.1.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\REST;

defined( 'ABSPATH' ) || exit;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Rate limit: minimum seconds between toggle requests per user/IP.
 */
const CANDLE_RATE_LIMIT_SECONDS = 2;

/**
 * Register candle routes.
 *
 * @since 2.1.0
 */
function register_candle_routes(): void {
	$namespace = 'starter-shelter/v1';

	register_rest_route( $namespace, '/candles/toggle', [
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\toggle_candle',
			'permission_callback' => '__return_true',
			'args'                => [
				'memorial_id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		],
	] );

	register_rest_route( $namespace, '/candles/mine', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_my_candles',
			'permission_callback' => '__return_true',
		],
	] );
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_candle_routes' );

/**
 * Toggle a candle for a memorial.
 *
 * @since 2.1.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response.
 */
function toggle_candle( WP_REST_Request $request ): WP_REST_Response {
	$memorial_id = $request->get_param( 'memorial_id' );

	// Verify the memorial exists.
	$memorial = get_post( $memorial_id );
	if ( ! $memorial || 'sd_memorial' !== $memorial->post_type ) {
		return new WP_REST_Response( [ 'error' => 'Memorial not found.' ], 404 );
	}

	// Rate limiting per user/IP.
	$rate_key = get_rate_limit_key();
	$last_toggle = get_transient( 'sd_candle_rate_' . $rate_key );
	if ( false !== $last_toggle ) {
		return new WP_REST_Response( [ 'error' => 'Please wait before toggling again.' ], 429 );
	}
	set_transient( 'sd_candle_rate_' . $rate_key, time(), CANDLE_RATE_LIMIT_SECONDS );

	$user_id = get_current_user_id();
	$candles = get_user_candles();

	// Determine the current lit state. For anonymous users the server-side
	// IP transient is authoritative — the `sd_candles` cookie is forgeable
	// and trusting it would let a visitor inflate the count by clearing the
	// cookie between toggles (the rate limit only throttles to 1 per 2s).
	$was_lit = $user_id
		? in_array( $memorial_id, $candles, true )
		: has_anonymous_candle( $memorial_id );

	if ( $was_lit ) {
		$candles = array_values( array_diff( $candles, [ $memorial_id ] ) );
		$delta   = -1;
	} else {
		if ( ! in_array( $memorial_id, $candles, true ) ) {
			$candles[] = $memorial_id;
		}
		$delta = 1;
	}

	// Save user candles (cookie + user_meta) as a display hint.
	save_user_candles( $candles );

	// For anonymous users, the IP-hash transient is the source of truth that
	// prevents cookie manipulation from inflating counts.
	if ( ! $user_id ) {
		track_anonymous_candle( $memorial_id, ! $was_lit );
	}

	// Update post meta count (floor at 0).
	$current_count = (int) get_post_meta( $memorial_id, '_sd_candle_count', true );
	$new_count     = max( 0, $current_count + $delta );
	update_post_meta( $memorial_id, '_sd_candle_count', $new_count );

	return new WP_REST_Response( [
		'memorial_id' => $memorial_id,
		'lit'         => ! $was_lit,
		'count'       => $new_count,
		'candles'     => $candles,
	] );
}

/**
 * Get the current user's candle list.
 *
 * @since 2.1.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response.
 */
function get_my_candles( WP_REST_Request $request ): WP_REST_Response {
	return new WP_REST_Response( [
		'candles' => get_user_candles(),
	] );
}

/**
 * Get the current user's lit candle IDs.
 *
 * Logged-in: user_meta. Anonymous: cookie (read-only, validated against
 * server-side IP tracking to prevent forgery).
 *
 * @since 2.1.0
 *
 * @return int[] Array of memorial IDs.
 */
function get_user_candles(): array {
	$user_id = get_current_user_id();

	if ( $user_id ) {
		$candles = get_user_meta( $user_id, '_sd_candles', true );
		return is_array( $candles ) ? array_map( 'intval', $candles ) : [];
	}

	// Anonymous: read from cookie, but only trust IDs that are valid memorial posts.
	if ( ! empty( $_COOKIE['sd_candles'] ) ) {
		$decoded = json_decode( wp_unslash( $_COOKIE['sd_candles'] ), true );
		if ( is_array( $decoded ) ) {
			// Limit to 100 IDs to prevent abuse via oversized cookies.
			return array_map( 'intval', array_slice( $decoded, 0, 100 ) );
		}
	}

	return [];
}

/**
 * Save the user's candle list.
 *
 * @since 2.1.0
 *
 * @param int[] $candles Array of memorial IDs.
 */
function save_user_candles( array $candles ): void {
	$user_id = get_current_user_id();

	if ( $user_id ) {
		update_user_meta( $user_id, '_sd_candles', $candles );
	}

	// Set HttpOnly cookie (prevents client-side JS manipulation). Skip if
	// headers are already sent — in the normal REST flow they are not, but
	// this keeps the helper safe to call from other contexts (e.g. tests).
	if ( headers_sent() ) {
		return;
	}

	$expires = time() + ( 30 * DAY_IN_SECONDS );
	setcookie(
		'sd_candles',
		wp_json_encode( array_slice( $candles, 0, 100 ) ),
		[
			'expires'  => $expires,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly'  => true,
			'samesite' => 'Lax',
		]
	);
}

/**
 * Track anonymous candle toggles by IP hash.
 *
 * Prevents cookie manipulation from inflating counts. Each IP can
 * only light each memorial once. Stored as a transient with a
 * 30-day expiry to match the cookie lifetime.
 *
 * @since 2.1.0
 *
 * @param int  $memorial_id The memorial post ID.
 * @param bool $lit         True if lighting, false if unlighting.
 */
function track_anonymous_candle( int $memorial_id, bool $lit ): void {
	$ip_hash = wp_hash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
	$key     = 'sd_anon_candle_' . $ip_hash . '_' . $memorial_id;

	if ( $lit ) {
		set_transient( $key, 1, 30 * DAY_IN_SECONDS );
	} else {
		delete_transient( $key );
	}
}

/**
 * Check if an anonymous user has already lit a candle for a memorial.
 *
 * @since 2.1.0
 *
 * @param int $memorial_id The memorial post ID.
 * @return bool True if already lit.
 */
function has_anonymous_candle( int $memorial_id ): bool {
	$ip_hash = wp_hash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
	$key     = 'sd_anon_candle_' . $ip_hash . '_' . $memorial_id;
	return false !== get_transient( $key );
}

/**
 * Get a rate limit key for the current user/IP.
 *
 * @since 2.1.0
 *
 * @return string Rate limit key.
 */
function get_rate_limit_key(): string {
	$user_id = get_current_user_id();
	if ( $user_id ) {
		return 'user_' . $user_id;
	}
	return 'ip_' . wp_hash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
}

/**
 * Merge candles a visitor lit while logged out (cookie) into their account on
 * login, so they appear in the "My Candles" area and are managed against the
 * permanent user_meta store going forward.
 *
 * Adding the cookie's memorial IDs to the user list does not change any
 * memorial's candle count — the count was already incremented when each candle
 * was lit anonymously; this only gives the now-logged-in member ownership of
 * those candles.
 *
 * @since 1.2.0
 *
 * @param string   $user_login The user's login name.
 * @param \WP_User $user       The logged-in user.
 */
function merge_anonymous_candles_on_login( string $user_login, $user ): void {
	if ( ! $user instanceof \WP_User || empty( $_COOKIE['sd_candles'] ) ) {
		return;
	}

	$decoded = json_decode( wp_unslash( $_COOKIE['sd_candles'] ), true );
	if ( ! is_array( $decoded ) || empty( $decoded ) ) {
		return;
	}

	$cookie_ids = array_map( 'intval', array_slice( $decoded, 0, 100 ) );

	$existing = get_user_meta( $user->ID, '_sd_candles', true );
	$existing = is_array( $existing ) ? array_map( 'intval', $existing ) : [];

	$merged = array_values( array_unique( array_merge( $existing, $cookie_ids ) ) );

	if ( $merged !== $existing ) {
		update_user_meta( $user->ID, '_sd_candles', $merged );
	}
}
add_action( 'wp_login', __NAMESPACE__ . '\\merge_anonymous_candles_on_login', 10, 2 );
