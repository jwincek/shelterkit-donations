<?php
/**
 * Candles REST Controller — Light a candle for a memorial.
 *
 * Public-facing toggle endpoint. Candle counts are stored on the
 * memorial post (visible to all). Per-user candle lists are stored
 * in user_meta (logged-in) or a 30-day cookie (anonymous).
 *
 * @package Starter_Shelter
 * @subpackage REST
 * @since 2.1.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\REST;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

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

	$candles = get_user_candles();
	$was_lit = in_array( $memorial_id, $candles, true );

	if ( $was_lit ) {
		// Unlight.
		$candles = array_values( array_diff( $candles, [ $memorial_id ] ) );
		$delta   = -1;
	} else {
		// Light.
		$candles[] = $memorial_id;
		$delta     = 1;
	}

	// Save user candles.
	save_user_candles( $candles );

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
 * Logged-in: user_meta. Anonymous: cookie.
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

	// Anonymous: read from cookie.
	if ( ! empty( $_COOKIE['sd_candles'] ) ) {
		$decoded = json_decode( wp_unslash( $_COOKIE['sd_candles'] ), true );
		return is_array( $decoded ) ? array_map( 'intval', $decoded ) : [];
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

	// Always set cookie (cross-session persistence, even for logged-in users).
	$expires = time() + ( 30 * DAY_IN_SECONDS );
	setcookie( 'sd_candles', wp_json_encode( $candles ), $expires, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
}
