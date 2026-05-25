<?php
/**
 * Helper functions for computed fields and general utilities.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Helpers;

use Starter_Shelter\Core\Config;

/**
 * Format a number as currency.
 *
 * @since 1.0.0
 *
 * @param float  $amount   The amount to format.
 * @param string $currency Currency code (default USD).
 * @return string Formatted currency string.
 */
function format_currency( float $amount, string $currency = 'USD' ): string {
    $symbol = match ( $currency ) {
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        default => '$',
    };

    return $symbol . number_format( $amount, 2 );
}

/**
 * Format a date for display.
 *
 * @since 1.0.0
 *
 * @param string $date   The date string.
 * @param string $format PHP date format (default site format).
 * @return string Formatted date string.
 */
function format_date( string $date, string $format = '' ): string {
    if ( empty( $date ) ) {
        return '';
    }

    if ( empty( $format ) ) {
        $format = get_option( 'date_format', 'F j, Y' );
    }

    $timestamp = strtotime( $date );
    if ( false === $timestamp ) {
        return $date;
    }

    return wp_date( $format, $timestamp );
}

/**
 * Format a date with time.
 *
 * @since 1.0.0
 *
 * @param string $datetime The datetime string.
 * @return string Formatted datetime string.
 */
function format_datetime( string $datetime ): string {
    if ( empty( $datetime ) ) {
        return '';
    }

    $date_format = get_option( 'date_format', 'F j, Y' );
    $time_format = get_option( 'time_format', 'g:i a' );

    $timestamp = strtotime( $datetime );
    if ( false === $timestamp ) {
        return $datetime;
    }

    return wp_date( "$date_format $time_format", $timestamp );
}



/**
 * Get donor display name from entity.
 *
 * @since 1.0.0
 *
 * @param int       $donor_id     The donor post ID.
 * @param bool|null $is_anonymous Optional override for anonymous flag.
 * @return string The donor display name.
 */
function get_donor_display_name( int $donor_id, ?bool $is_anonymous = null ): string {
    // If anonymous flag is explicitly true, return anonymous.
    if ( true === $is_anonymous ) {
        return 'Anonymous Donor';
    }

    if ( ! $donor_id ) {
        return 'Anonymous';
    }

    // Check donor's own anonymous preference if not overridden.
    if ( null === $is_anonymous ) {
        $is_anon = get_post_meta( $donor_id, '_sd_is_anonymous', true );
        if ( $is_anon ) {
            return 'Anonymous Donor';
        }
    }

    // Priority 1: Check first/last name (standard donors from WooCommerce orders).
    $first_name = get_post_meta( $donor_id, '_sd_first_name', true );
    $last_name  = get_post_meta( $donor_id, '_sd_last_name', true );
    $name       = trim( "$first_name $last_name" );

    if ( ! empty( $name ) ) {
        return $name;
    }

    // Priority 2: Check display_name meta (legacy imports, name-only donors).
    $display_name = get_post_meta( $donor_id, '_sd_display_name', true );

    if ( ! empty( $display_name ) ) {
        return $display_name;
    }

    // Priority 3: Fall back to post title.
    $post = get_post( $donor_id );
    if ( $post && ! empty( $post->post_title ) ) {
        return $post->post_title;
    }

    return 'Anonymous';
}

/**
 * Get donor display name for a memorial.
 *
 * Resolution order:
 * 1. If anonymous flag is set → "Anonymous Donor"
 * 2. If the memorial's denormalized _sd_donor_display_name has a value → use it
 * 3. Fall back to the donor post lookup via get_donor_display_name()
 * 4. Ultimate fallback → "A Friend"
 *
 * Called by Entity_Hydrator via the entities.json computed field config.
 *
 * @since 2.1.1
 *
 * @param bool   $is_anonymous       Whether the donation is anonymous.
 * @param string $donor_display_name Denormalized donor name from the memorial post.
 * @param int    $donor_id           The donor post ID (fallback lookup).
 * @return string The resolved display name — never empty.
 */
function get_memorial_donor_name( bool $is_anonymous, string $donor_display_name, int $donor_id ): string {
    if ( $is_anonymous ) {
        return __( 'Anonymous Donor', 'starter-shelter' );
    }

    if ( ! empty( $donor_display_name ) ) {
        return $donor_display_name;
    }

    $name = get_donor_display_name( $donor_id );

    // Never return empty — use a friendly fallback.
    return ! empty( $name ) && 'Anonymous' !== $name
        ? $name
        : __( 'A Friend', 'starter-shelter' );
}

/**
 * Get membership tier label from slug.
 *
 * @since 1.0.0
 *
 * @param string $tier_slug The tier slug.
 * @return string The tier label.
 */
function get_tier_label( string $tier_slug ): string {
    $tiers = Config::get_item( 'tiers', 'tiers', [] );

    // Check individual tiers.
    foreach ( $tiers['individual'] ?? [] as $tier ) {
        if ( ( $tier['slug'] ?? '' ) === $tier_slug ) {
            return $tier['name'] ?? ucwords( str_replace( '-', ' ', $tier_slug ) );
        }
    }

    // Check business tiers.
    foreach ( $tiers['business'] ?? [] as $tier ) {
        if ( ( $tier['slug'] ?? '' ) === $tier_slug ) {
            return $tier['name'] ?? ucwords( str_replace( '-', ' ', $tier_slug ) );
        }
    }

    return ucwords( str_replace( '-', ' ', $tier_slug ) );
}

/**
 * Check if a membership is currently active.
 *
 * @since 1.0.0
 *
 * @param string $expiration_date The expiration date (Y-m-d).
 * @return bool Whether the membership is active.
 */
function is_membership_active( string $expiration_date ): bool {
    if ( empty( $expiration_date ) ) {
        return false;
    }

    $expiration = strtotime( $expiration_date );
    if ( false === $expiration ) {
        return false;
    }

    return $expiration >= strtotime( 'today' );
}

/**
 * Check if a membership is expiring soon (within 30 days).
 *
 * @since 1.0.0
 *
 * @param string $expiration_date The expiration date (Y-m-d).
 * @param int    $days_threshold  Days to consider "soon" (default 30).
 * @return bool Whether the membership is expiring soon.
 */
function is_membership_expiring_soon( string $expiration_date, int $days_threshold = 30 ): bool {
    if ( empty( $expiration_date ) ) {
        return false;
    }

    $expiration = strtotime( $expiration_date );
    if ( false === $expiration ) {
        return false;
    }

    $today    = strtotime( 'today' );
    $soon     = strtotime( "+{$days_threshold} days" );

    return $expiration >= $today && $expiration <= $soon;
}

/**
 * Get days until a date.
 *
 * @since 1.0.0
 *
 * @param string $target_date The target date (Y-m-d).
 * @return int Days until the date (negative if past).
 */
function get_days_until( string $target_date ): int {
    if ( empty( $target_date ) ) {
        return 0;
    }

    $target = strtotime( $target_date );
    if ( false === $target ) {
        return 0;
    }

    $today = strtotime( 'today' );
    $diff  = $target - $today;

    return (int) floor( $diff / DAY_IN_SECONDS );
}

/**
 * Concatenate first and last names.
 *
 * @since 1.0.0
 *
 * @param string $first_name First name.
 * @param string $last_name  Last name.
 * @return string Full name.
 */
function concat_names( string $first_name, string $last_name ): string {
    return trim( "$first_name $last_name" );
}

/**
 * Calculate donor level based on lifetime giving.
 *
 * @since 1.0.0
 *
 * @param float $lifetime_giving Total lifetime giving amount.
 * @return string The donor level.
 */
function calculate_donor_level( float $lifetime_giving ): string {
    return match ( true ) {
        $lifetime_giving >= 10000 => 'champion',
        $lifetime_giving >= 5000  => 'guardian',
        $lifetime_giving >= 1000  => 'supporter',
        $lifetime_giving >= 500   => 'friend',
        $lifetime_giving > 0      => 'donor',
        default                   => 'new',
    };
}

/**
 * Get donor level label.
 *
 * @since 1.0.0
 *
 * @param string $level The donor level slug.
 * @return string The human-readable label.
 */
function get_donor_level_label( string $level ): string {
    return match ( $level ) {
        'champion'  => 'Champion ($10,000+)',
        'guardian'  => 'Guardian ($5,000+)',
        'supporter' => 'Supporter ($1,000+)',
        'friend'    => 'Friend ($500+)',
        'donor'     => 'Donor',
        default     => 'New Donor',
    };
}

/**
 * Format an address array for display.
 *
 * @since 1.0.0
 *
 * @param array|object $address The address array or object.
 * @param string       $format  Format: 'single_line', 'multi_line', or 'html'.
 * @return string The formatted address.
 */
function format_address( $address, string $format = 'single_line' ): string {
    // Convert object to array if needed.
    if ( is_object( $address ) ) {
        $address = (array) $address;
    }

    if ( empty( $address ) || ! is_array( $address ) ) {
        return '';
    }

    $parts = array_filter( [
        $address['line_1'] ?? $address['address_1'] ?? '',
        $address['line_2'] ?? $address['address_2'] ?? '',
        $address['city'] ?? '',
        $address['state'] ?? '',
        $address['postal_code'] ?? $address['postcode'] ?? '',
        $address['country'] ?? '',
    ] );

    if ( empty( $parts ) ) {
        return '';
    }

    // Build city/state/zip line.
    $city_state_zip = array_filter( [
        $address['city'] ?? '',
        ( $address['state'] ?? '' ) . ( ! empty( $address['postal_code'] ?? $address['postcode'] ?? '' ) ? ' ' . ( $address['postal_code'] ?? $address['postcode'] ?? '' ) : '' ),
    ] );

    $lines = array_filter( [
        $address['line_1'] ?? $address['address_1'] ?? '',
        $address['line_2'] ?? $address['address_2'] ?? '',
        implode( ', ', $city_state_zip ),
        $address['country'] ?? '',
    ] );

    return match ( $format ) {
        'multi_line' => implode( "\n", $lines ),
        'html'       => implode( '<br>', array_map( 'esc_html', $lines ) ),
        default      => implode( ', ', $lines ),
    };
}

/**
 * Get attachment URL by ID.
 *
 * @since 1.0.0
 *
 * @param int    $attachment_id The attachment ID.
 * @param string $size          Image size (default 'full').
 * @return string The attachment URL or empty string.
 */
function get_attachment_url( int $attachment_id, string $size = 'full' ): string {
    if ( ! $attachment_id ) {
        return '';
    }

    $url = wp_get_attachment_image_url( $attachment_id, $size );
    return $url ?: '';
}

/**
 * Get memorial type label.
 *
 * @since 1.0.0
 *
 * @param string $type The memorial type (memory or honor).
 * @return string The human-readable label.
 */
function get_memorial_type_label( string $type ): string {
    return match ( normalize_memorial_type( $type ) ) {
        'pet'   => __( 'Pet', 'starter-shelter' ),
        default => __( 'Person', 'starter-shelter' ),
    };
}

/**
 * Normalize a memorial_type value to the canonical enum.
 *
 * The canonical memorial_type values are `person` and `pet`. Legacy data
 * may contain `human` (treated as person), `honor` (the "in honor of"
 * sentiment, stored separately as dedication_type but historically
 * written to memorial_type), or `memory` (same conflation). All legacy
 * values normalize to `person` except `pet`.
 *
 * Apply this on read so existing rows render correctly while writers
 * are updated to emit canonical values only.
 *
 * @since 1.1.2
 *
 * @param string $raw The raw memorial_type value.
 * @return string Canonical value: `person` or `pet`.
 */
function normalize_memorial_type( string $raw ): string {
    return 'pet' === strtolower( trim( $raw ) ) ? 'pet' : 'person';
}

/**
 * Get date range for a reporting period.
 *
 * @since 1.0.0
 *
 * @param string   $period      Period type: 'fiscal_year', 'calendar_year', 'quarter', 'month', 'custom'.
 * @param int|null $year        Optional specific year.
 * @param int|null $fiscal_year Optional fiscal year (for fiscal_year period).
 * @return array{start: string, end: string} Date range.
 */
function get_date_range_for_period( string $period, ?int $year = null, ?int $fiscal_year = null ): array {
    $year = $year ?? (int) wp_date( 'Y' );

    return match ( $period ) {
        'today' => [
            'start' => wp_date( 'Y-m-d' ),
            'end'   => wp_date( 'Y-m-d' ),
        ],
        'week' => [
            'start' => wp_date( 'Y-m-d', strtotime( 'monday this week' ) ),
            'end'   => wp_date( 'Y-m-d' ),
        ],
        'month' => [
            'start' => wp_date( 'Y-m-01' ),
            'end'   => wp_date( 'Y-m-t' ),
        ],
        'quarter' => get_current_quarter_range( $year ),
        'year' => [
            'start' => "$year-01-01",
            'end'   => "$year-12-31",
        ],
        'fiscal_year' => get_fiscal_year_range( $fiscal_year ?? $year ),
        'calendar_year' => [
            'start' => "$year-01-01",
            'end'   => "$year-12-31",
        ],
        'ytd' => [
            'start' => "$year-01-01",
            'end'   => wp_date( 'Y-m-d' ),
        ],
        'custom' => [
            'start' => sanitize_text_field( $_GET['date_from'] ?? $_POST['date_from'] ?? "$year-01-01" ),
            'end'   => sanitize_text_field( $_GET['date_to'] ?? $_POST['date_to'] ?? wp_date( 'Y-m-d' ) ),
        ],
        'all_time' => [
            'start' => '2000-01-01',
            'end'   => wp_date( 'Y-m-d' ),
        ],
        default => [
            'start' => "$year-01-01",
            'end'   => "$year-12-31",
        ],
    };
}

/**
 * Get fiscal year date range.
 * Assumes fiscal year starts July 1.
 *
 * @since 1.0.0
 *
 * @param int $fiscal_year The fiscal year (e.g., 2024 = July 2024 - June 2025).
 * @return array{start: string, end: string} Date range.
 */
function get_fiscal_year_range( int $fiscal_year ): array {
    return [
        'start' => "$fiscal_year-07-01",
        'end'   => ( $fiscal_year + 1 ) . '-06-30',
    ];
}

/**
 * Get current quarter date range.
 *
 * @since 1.0.0
 *
 * @param int|null $year Optional year.
 * @return array{start: string, end: string} Date range.
 */
function get_current_quarter_range( ?int $year = null ): array {
    $year    = $year ?? (int) wp_date( 'Y' );
    $month   = (int) wp_date( 'n' );
    $quarter = (int) ceil( $month / 3 );

    $start_month = ( ( $quarter - 1 ) * 3 ) + 1;
    $end_month   = $quarter * 3;

    return [
        'start' => sprintf( '%d-%02d-01', $year, $start_month ),
        'end'   => wp_date( 'Y-m-t', strtotime( sprintf( '%d-%02d-01', $year, $end_month ) ) ),
    ];
}

/**
 * Normalize a membership tier slug from various formats.
 *
 * @since 1.0.0
 *
 * @param string $tier The raw tier string.
 * @return string Normalized tier slug.
 */
function normalize_tier( string $tier ): string {
    // Remove price suffix (e.g., "Guardian - $100" -> "Guardian").
    $tier = preg_replace( '/\s*-\s*\$[\d,]+/', '', $tier );

    // Remove "Membership" suffix.
    $tier = preg_replace( '/\s+membership$/i', '', $tier );

    // Convert to slug format.
    return sanitize_title( trim( $tier ) );
}

/**
 * Get or create a donor by email.
 *
 * @since 1.0.0
 *
 * @param string $email      The donor email.
 * @param string $name       Optional donor name.
 * @param array  $extra_meta Optional additional meta to set.
 * @return int|\WP_Error The donor post ID or error.
 */
function get_or_create_donor( string $email, string $name = '', array $extra_meta = [] ) {
    if ( empty( $email ) || ! is_email( $email ) ) {
        return new \WP_Error( 'invalid_email', __( 'A valid email address is required.', 'starter-shelter' ) );
    }

    $email = sanitize_email( $email );

    // Check for existing donor.
    $existing = get_posts( [
        'post_type'      => 'sd_donor',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [
            [
                'key'   => '_sd_email',
                'value' => $email,
            ],
        ],
        'fields'         => 'ids',
    ] );

    if ( ! empty( $existing ) ) {
        $donor_id = $existing[0];
        
        // Ensure display_name is set (might be missing on older records).
        $current_display_name = get_post_meta( $donor_id, '_sd_display_name', true );
        if ( empty( $current_display_name ) && ! empty( $name ) ) {
            update_post_meta(
                $donor_id,
                '_sd_display_name',
                \Starter_Shelter\Admin\Shared\Donor_Lookup::sanitize_display_name( $name )
            );
        }
        
        return $donor_id;
    }

    // Parse name into first/last.
    $name_parts = explode( ' ', trim( $name ), 2 );
    $first_name = $name_parts[0] ?? '';
    $last_name  = $name_parts[1] ?? '';

    // Create new donor.
    $donor_id = wp_insert_post( [
        'post_type'   => 'sd_donor',
        'post_title'  => $name ?: $email,
        'post_status' => 'publish',
        'meta_input'  => array_merge( [
            '_sd_email'           => $email,
            '_sd_display_name'    => ! empty( $name )
                ? \Starter_Shelter\Admin\Shared\Donor_Lookup::sanitize_display_name( $name )
                : $email,
            '_sd_first_name'      => $first_name,
            '_sd_last_name'       => $last_name,
            '_sd_lifetime_giving' => 0,
            '_sd_created_date'    => wp_date( 'Y-m-d H:i:s' ),
        ], $extra_meta ),
    ], true );

    return $donor_id;
}

/**
 * Update a donor's lifetime giving total.
 *
 * @since 1.0.0
 *
 * @param int   $donor_id The donor post ID.
 * @param float $amount   The amount to add.
 * @return float The new total.
 */
function update_donor_lifetime_giving( int $donor_id, float $amount ): float {
    $current = (float) get_post_meta( $donor_id, '_sd_lifetime_giving', true );
    $new_total = $current + $amount;

    update_post_meta( $donor_id, '_sd_lifetime_giving', $new_total );

    return $new_total;
}

/**
 * Get membership benefits by tier.
 *
 * @since 1.0.0
 *
 * @param string $tier_slug       The tier slug.
 * @param string $membership_type Type: 'individual' or 'business'.
 * @return array Array of benefits.
 */
function get_tier_benefits( string $tier_slug, string $membership_type = 'individual' ): array {
    $tiers = Config::get_item( 'tiers', 'tiers', [] );
    $tier_list = $tiers[ $membership_type ] ?? [];

    foreach ( $tier_list as $tier ) {
        if ( ( $tier['slug'] ?? '' ) === $tier_slug ) {
            return $tier['benefits'] ?? [];
        }
    }

    return [];
}

/**
 * Get tier amount by slug.
 *
 * @since 1.0.0
 *
 * @param string $tier_slug       The tier slug.
 * @param string $membership_type Type: 'individual' or 'business'.
 * @return float The tier amount or 0.
 */
function get_tier_amount( string $tier_slug, string $membership_type = 'individual' ): float {
    $tiers = Config::get_item( 'tiers', 'tiers', [] );
    $tier_list = $tiers[ $membership_type ] ?? [];

    foreach ( $tier_list as $tier ) {
        if ( ( $tier['slug'] ?? '' ) === $tier_slug ) {
            return (float) ( $tier['amount'] ?? 0 );
        }
    }

    return 0.0;
}

/**
 * Check if value equals expected.
 *
 * @since 1.0.0
 *
 * @param mixed $value    The value to check.
 * @param mixed $expected The expected value.
 * @return bool Whether they are equal.
 */
function equals( $value, $expected ): bool {
    return $value === $expected;
}

/**
 * Generate a unique reference number.
 *
 * @since 1.0.0
 *
 * @param string $prefix Optional prefix.
 * @return string The reference number.
 */
function generate_reference( string $prefix = '' ): string {
    $ref = strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 ) );
    return $prefix ? "$prefix-$ref" : $ref;
}

/**
 * Sanitize and validate a phone number.
 *
 * @since 1.0.0
 *
 * @param string $phone The phone number.
 * @return string Sanitized phone or empty string.
 */
function sanitize_phone( string $phone ): string {
    // Remove all non-numeric characters except + for international.
    $phone = preg_replace( '/[^0-9+]/', '', $phone );

    // Basic validation: at least 10 digits.
    if ( strlen( preg_replace( '/[^0-9]/', '', $phone ) ) < 10 ) {
        return '';
    }

    return $phone;
}

/**
 * Get pet species label.
 *
 * @since 1.0.0
 *
 * @param string $species The species slug.
 * @return string The human-readable label.
 */
function get_species_label( string $species ): string {
    return match ( strtolower( $species ) ) {
        'dog'   => 'Dog',
        'cat'   => 'Cat',
        'bird'  => 'Bird',
        'horse' => 'Horse',
        'other' => 'Other Pet',
        default => ucfirst( $species ),
    };
}




/**
 * Process post-save side effects for a memorial.
 *
 * Centralizes the business logic that must run after a memorial is created
 * or updated, regardless of the entry point (ability create, REST save,
 * legacy import). This ensures consistent behavior for:
 * - Title sync from honoree name
 * - Donor resolution from display name
 * - Year taxonomy assignment from donation date
 * - Donor lifetime giving update
 * - Email/notification hook
 *
 * @since 2.1.0
 *
 * @param int   $memorial_id The memorial post ID.
 * @param array $context {
 *     Optional context about how this save originated.
 *
 *     @type bool   $is_new        Whether this is a new memorial (default true).
 *     @type string $import_source Source identifier for donor auto-creation.
 * }
 */
function process_memorial_save( int $memorial_id, array $context = [] ): void {
    $is_new        = $context['is_new'] ?? true;
    $import_source = $context['import_source'] ?? 'admin';

    $honoree_name  = get_post_meta( $memorial_id, '_sd_honoree_name', true );
    $donor_id      = (int) get_post_meta( $memorial_id, '_sd_donor_id', true );
    $display_name  = get_post_meta( $memorial_id, '_sd_donor_display_name', true );
    $donation_date = get_post_meta( $memorial_id, '_sd_donation_date', true );
    $amount        = (float) get_post_meta( $memorial_id, '_sd_amount', true );

    // 1. Sync post_title from honoree_name.
    $post = get_post( $memorial_id );
    if ( ! empty( $honoree_name ) && $post && $post->post_title !== $honoree_name ) {
        wp_update_post( [
            'ID'         => $memorial_id,
            'post_title' => $honoree_name,
        ] );
    }

    // 2. Auto-resolve donor from display_name if donor_id is empty.
    if ( ! $donor_id && ! empty( $display_name ) ) {
        $result = \Starter_Shelter\Admin\Shared\Donor_Lookup::find_or_create( [
            'display_name'  => $display_name,
            'import_source' => $import_source,
        ] );

        if ( ! is_wp_error( $result ) ) {
            $donor_id = $result['id'];
            update_post_meta( $memorial_id, '_sd_donor_id', $donor_id );
        }
    }

    // 3. Assign sd_memorial_year taxonomy from donation date.
    if ( ! empty( $donation_date ) ) {
        $year = date( 'Y', strtotime( $donation_date ) );
        wp_set_object_terms( $memorial_id, [ $year ], 'sd_memorial_year' );
    }

    // 4. Update donor lifetime giving.
    if ( $donor_id && $amount > 0 && $is_new ) {
        update_donor_lifetime_giving( $donor_id, $amount );
    }

    // 5. Fire memorial created hook (triggers emails).
    if ( $is_new ) {
        $input = [
            'honoree_name'  => $honoree_name,
            'amount'        => $amount,
            'notify_family' => [
                'enabled' => (bool) get_post_meta( $memorial_id, '_sd_notify_family_enabled', true ),
                'name'    => get_post_meta( $memorial_id, '_sd_notify_family_name', true ),
                'email'   => get_post_meta( $memorial_id, '_sd_notify_family_email', true ),
            ],
        ];

        do_action( 'starter_shelter_memorial_created', $memorial_id, $donor_id, $input );
    }
}

/**
 * Get allocation label.
 *
 * @since 1.0.0
 *
 * @param string $allocation The allocation slug.
 * @return string The human-readable label.
 */
function get_allocation_label( string $allocation ): string {
    $allocations = Config::get_item( 'settings', 'allocations', [] );
    
    if ( isset( $allocations[ $allocation ] ) ) {
        return $allocations[ $allocation ];
    }

    // Default labels.
    return match ( $allocation ) {
        'general-fund'      => __( 'General Fund', 'starter-shelter' ),
        'medical-care'      => __( 'Medical Care', 'starter-shelter' ),
        'food-supplies'     => __( 'Food & Supplies', 'starter-shelter' ),
        'facility'          => __( 'Facility Improvements', 'starter-shelter' ),
        'rescue-operations' => __( 'Rescue Operations', 'starter-shelter' ),
        default             => ucwords( str_replace( [ '-', '_' ], ' ', $allocation ) ),
    };
}
