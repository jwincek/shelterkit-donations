<?php
/**
 * Memorial ability callbacks.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Abilities\Memorials;

use Starter_Shelter\Core\{ Query, Entity_Hydrator };
use Starter_Shelter\Helpers;
use WP_Error;

/**
 * Create a new memorial tribute.
 *
 * @since 1.0.0
 *
 * @param array $input Memorial input data.
 * @return array|WP_Error Created memorial data or error.
 */
function create( array $input ): array|WP_Error {
    // Get or create donor.
    // Accept pre-resolved donor_id (from importers, sync) or resolve from email.
    if ( ! empty( $input['donor_id'] ) ) {
        $donor_id = (int) $input['donor_id'];
    } else {
        $donor_id = Helpers\get_or_create_donor(
            $input['donor_email'] ?? '',
            $input['donor_name'] ?? ''
        );
        if ( is_wp_error( $donor_id ) ) {
            return $donor_id;
        }
    }

    // Use provided date or current time. Canonical input key is
    // `donation_date`; `date` accepted as legacy input for callers that
    // haven't migrated yet (CC-2 read-both, write-canonical).
    $memorial_date = $input['donation_date'] ?? $input['date'] ?? wp_date( 'Y-m-d H:i:s' );

    // Denormalized donor name for the wall + search. This is a
    // per-tribute copy, so prefer the name supplied for THIS memorial
    // (the form's "Your Name" field). get_or_create_donor intentionally
    // does NOT overwrite an existing donor's canonical _sd_display_name,
    // so a returning email would otherwise show the stored donor name
    // instead of what the giver typed here. Fall back to the donor
    // record only when no name was supplied.
    $donor_display_name = '';
    if ( ! ( $input['is_anonymous'] ?? false ) ) {
        $supplied = trim( (string) ( $input['donor_name'] ?? '' ) );
        $donor_display_name = '' !== $supplied
            ? \Starter_Shelter\Admin\Shared\Donor_Lookup::sanitize_display_name( $supplied )
            : ( get_post_meta( $donor_id, '_sd_display_name', true ) ?: get_the_title( $donor_id ) );
    }

    // Create memorial post.
    $memorial_id = wp_insert_post( [
        'post_type'   => 'sd_memorial',
        'post_title'  => $input['honoree_name'],
        'post_status' => 'publish',
        'post_date'   => $memorial_date,
        'meta_input'  => [
            '_sd_donor_id'           => $donor_id,
            '_sd_donor_display_name' => $donor_display_name,
            '_sd_honoree_name'       => $input['honoree_name'],
            '_sd_memorial_type'      => Helpers\normalize_memorial_type( (string) ( $input['memorial_type'] ?? '' ) ),
            '_sd_dedication_type'    => 'honor' === ( $input['dedication_type'] ?? 'memory' ) ? 'honor' : 'memory',
            '_sd_pet_species'        => $input['pet_species'] ?? '',
            '_sd_tribute_message'    => $input['tribute_message'] ?? '',
            '_sd_amount'             => (float) ( $input['amount'] ?? 0 ),
            '_sd_wc_order_id'        => $input['order_id'] ?? 0,
            '_sd_is_anonymous'       => $input['is_anonymous'] ?? false,
            '_sd_donation_date'      => $memorial_date,
            // notify_family persisted as 5 flat keys (canonical, CC-2).
            // Input arrives nested from products.json composite mapping.
            '_sd_notify_family_enabled'   => (bool) ( $input['notify_family']['enabled'] ?? false ),
            '_sd_notify_family_name'      => (string) ( $input['notify_family']['name'] ?? '' ),
            '_sd_notify_family_email'     => (string) ( $input['notify_family']['email'] ?? '' ),
            '_sd_notify_family_address'   => (string) ( $input['notify_family']['address'] ?? '' ),
            '_sd_notify_family_send_card' => (bool) ( $input['notify_family']['send_card'] ?? false ),
        ],
    ], true );

    if ( is_wp_error( $memorial_id ) ) {
        return $memorial_id;
    }

    // Handle photo attachment.
    if ( ! empty( $input['photo_id'] ) ) {
        update_post_meta( $memorial_id, '_sd_photo_id', (int) $input['photo_id'] );
    }

    // Run shared post-processing (year taxonomy, lifetime giving, emails).
    Helpers\process_memorial_save( $memorial_id, [
        'is_new'        => true,
        'import_source' => 'ability_create',
    ] );

    return [
        'memorial_id'  => $memorial_id,
        'donor_id'     => $donor_id,
        'honoree_name' => $input['honoree_name'],
        'permalink'    => get_permalink( $memorial_id ),
        'status'       => 'created',
    ];
}

/**
 * List memorials with filtering.
 *
 * @since 1.0.0
 *
 * @param array $input Filter and pagination options.
 * @return array Paginated memorial list.
 */
function list_memorials( array $input = [] ): array {
    $query = Query::for( 'sd_memorial' )
        ->withPermalinks()                    // ← one-line addition
        ->where( 'donor_id', $input['donor_id'] ?? null )
        ->whereInTaxonomy( 'sd_campaign', $input['campaign_id'] ?? null )
        ->searchMultiple( [ 'honoree_name', 'donor_display_name' ], $input['search'] ?? null )
        ->orderBy( 'donation_date', 'DESC' );

    $type = $input['type'] ?? null;
    if ( $type && 'all' !== $type ) {
        $query->where( 'memorial_type', $type );
    }

    // Dedication occasion (memory / honor) — orthogonal to memorial_type.
    $dedication = $input['dedication'] ?? null;
    if ( $dedication && 'all' !== $dedication ) {
        $query->where( 'dedication_type', $dedication );
    }

    if ( ! empty( $input['year'] ) ) {
        $query->whereInTaxonomy( 'sd_memorial_year', (string) $input['year'], 'slug' );
    }

    return $query->paginate(                  // ← direct return, no loop
        max( 1, (int) ( $input['page'] ?? 1 ) ),
        max( 1, (int) ( $input['per_page'] ?? 12 ) )
    );
}

/**
 * Get a memorial by ID.
 *
 * @since 1.0.0
 *
 * @param array $input Input with memorial_id.
 * @return array|WP_Error Memorial data or error.
 */
function get( array $input ): array|WP_Error {
    $result = Entity_Hydrator::get( 'sd_memorial', $input['memorial_id'] );

    if ( ! $result ) {
        return new WP_Error(
            'memorial_not_found',
            __( 'Memorial not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    // Add permalink.
    $result['permalink'] = get_permalink( $input['memorial_id'] );

    return $result;
}
