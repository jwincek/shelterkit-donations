<?php
/**
 * Report ability callbacks.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Abilities\Reports;

use Starter_Shelter\Core\{ Query, Entity_Hydrator, Config };
use Starter_Shelter\Helpers;
use WP_Error;

/**
 * Days-ahead window for the main dashboard "Expiring Soon" stat.
 *
 * Long-term-planning view: shows admins how many memberships will need
 * renewal in the next month. Deliberately wider than the urgent
 * action-items window — different audience and intent.
 */
const EXPIRING_SOON_WINDOW_DAYS = 30;

/**
 * Days-ahead window for the urgent "Action Required" list.
 *
 * Powers the menu-badge count and the dashboard widget's action items.
 * Short on purpose: signals "act now, this is about to expire" rather
 * than "plan for upcoming renewals." Override per call via
 * shelter-reports/action-items' `expiring_window_days` input.
 */
const ACTION_ITEMS_WINDOW_DAYS = 7;

/**
 * Generate annual donor summary.
 *
 * @since 1.0.0
 *
 * @param array $input Input with donor_id and year.
 * @return array|WP_Error Annual summary or error.
 */
function annual_summary( array $input ): array|WP_Error {
    $donor_id = $input['donor_id'] ?? 0;
    $year     = $input['year'] ?? (int) wp_date( 'Y' );

    if ( ! $donor_id ) {
        return new WP_Error(
            'invalid_donor_id',
            __( 'Valid donor ID is required.', 'starter-shelter' ),
            [ 'status' => 400 ]
        );
    }

    $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
    if ( ! $donor ) {
        return new WP_Error(
            'donor_not_found',
            __( 'Donor not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    $date_from = "$year-01-01";
    $date_to   = "$year-12-31";

    // Get donations for the year.
    $donations = Query::for( 'sd_donation' )
        ->where( 'donor_id', $donor_id )
        ->whereDateBetween( 'donation_date', $date_from, $date_to )
        ->orderBy( 'donation_date', 'ASC' )
        ->get();

    // Get memorials for the year.
    $memorials = Query::for( 'sd_memorial' )
        ->where( 'donor_id', $donor_id )
        ->whereDateBetween( 'donation_date', $date_from, $date_to )
        ->orderBy( 'donation_date', 'ASC' )
        ->get();

    // Get memberships active during the year.
    $memberships = Query::for( 'sd_membership' )
        ->where( 'donor_id', $donor_id )
        ->whereDateBetween( 'start_date', $date_from, $date_to )
        ->orderBy( 'start_date', 'ASC' )
        ->get();

    // Calculate totals.
    $donation_total   = array_sum( array_column( $donations, 'amount' ) );
    $memorial_total   = array_sum( array_column( $memorials, 'amount' ) );
    $membership_total = array_sum( array_column( $memberships, 'amount' ) );
    $grand_total      = $donation_total + $memorial_total + $membership_total;

    // Build summary by allocation.
    $by_allocation = [];
    foreach ( $donations as $donation ) {
        $alloc = $donation['allocation'] ?? 'general-fund';
        if ( ! isset( $by_allocation[ $alloc ] ) ) {
            $by_allocation[ $alloc ] = [
                'allocation' => $alloc,
                'label'      => Helpers\get_allocation_label( $alloc ),
                'amount'     => 0,
                'count'      => 0,
            ];
        }
        $by_allocation[ $alloc ]['amount'] += $donation['amount'];
        $by_allocation[ $alloc ]['count']++;
    }

    return [
        'donor'          => [
            'id'        => $donor_id,
            'name'      => $donor['full_name'] ?? 'Anonymous',
            'email'     => $donor['email'] ?? '',
            'address'   => $donor['formatted_address'] ?? '',
        ],
        'year'           => $year,
        'donations'      => [
            'items'      => $donations,
            'total'      => $donation_total,
            'formatted'  => Helpers\format_currency( $donation_total ),
            'count'      => count( $donations ),
        ],
        'memorials'      => [
            'items'      => $memorials,
            'total'      => $memorial_total,
            'formatted'  => Helpers\format_currency( $memorial_total ),
            'count'      => count( $memorials ),
        ],
        'memberships'    => [
            'items'      => $memberships,
            'total'      => $membership_total,
            'formatted'  => Helpers\format_currency( $membership_total ),
            'count'      => count( $memberships ),
        ],
        'by_allocation'  => array_values( $by_allocation ),
        'grand_total'    => $grand_total,
        'grand_formatted' => Helpers\format_currency( $grand_total ),
        'tax_deductible' => $grand_total, // Assuming all donations are tax-deductible.
        'generated_date' => wp_date( 'Y-m-d H:i:s' ),
    ];
}

/**
 * Get quick-glance donor summary.
 *
 * @since 1.1.2
 *
 * @param array $input Input with donor_id.
 * @return array|WP_Error Donor summary stats or error.
 */
function donor_summary( array $input ): array|WP_Error {
    $donor_id = (int) ( $input['donor_id'] ?? 0 );

    if ( ! $donor_id ) {
        return new WP_Error(
            'invalid_donor_id',
            __( 'Valid donor ID is required.', 'starter-shelter' ),
            [ 'status' => 400 ]
        );
    }

    $year_start = wp_date( 'Y-01-01' );
    $year_end   = wp_date( 'Y-12-31' );

    $all_donations = Query::for( 'sd_donation' )
        ->where( 'donor_id', $donor_id )
        ->get();

    $ytd_donations = Query::for( 'sd_donation' )
        ->where( 'donor_id', $donor_id )
        ->whereDateBetween( 'donation_date', $year_start, $year_end )
        ->get();

    $lifetime_giving = (float) array_sum( array_column( $all_donations, 'amount' ) );
    $ytd_giving      = (float) array_sum( array_column( $ytd_donations, 'amount' ) );

    return [
        'donation_count'            => count( $all_donations ),
        'lifetime_giving'           => $lifetime_giving,
        'lifetime_giving_formatted' => Helpers\format_currency( $lifetime_giving ),
        'ytd_giving'                => $ytd_giving,
        'ytd_giving_formatted'      => Helpers\format_currency( $ytd_giving ),
    ];
}

/**
 * Get dashboard statistics.
 *
 * Return shape matches the declared output_schema and the keys read by
 * admin consumers (`class-menu`, `class-dashboard-widget`, `class-reports`):
 *
 *   donations   => total, total_formatted, count, unique_donors, average
 *   memberships => active, new, expiring_soon, revenue, revenue_formatted, by_tier
 *   memorials   => total, new, revenue, revenue_formatted (`total` and `new` are
 *                  both period counts; both keys kept because both consumers exist)
 *   donors      => total (lifetime), new (in period)
 *
 * Pre-1.1.2 this returned `memberships.active_count`/`new_count`,
 * `totals.new_donors`, etc., which no consumer read — silent zeros all
 * over the admin UI. Renamed to match the schema and the renderers.
 *
 * @since 1.0.0
 *
 * @param array $input Input with period and optional filters.
 * @return array Dashboard statistics.
 */
function dashboard_stats( array $input = [] ): array {
    $period = $input['period'] ?? 'fiscal_year';
    $range  = Helpers\get_date_range_for_period(
        $period,
        null,
        $input['fiscal_year'] ?? null
    );

    global $wpdb;
    $today           = wp_date( 'Y-m-d' );
    $expiring_window = wp_date( 'Y-m-d', strtotime( '+' . EXPIRING_SOON_WINDOW_DAYS . ' days' ) );

    // Donations: count, sum, unique donors in period.
    $donation_stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(*) as count,
            COALESCE(SUM(pm.meta_value + 0), 0) as total,
            COUNT(DISTINCT pd.meta_value) as donors
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        INNER JOIN {$wpdb->postmeta} pd ON p.ID = pd.post_id AND pd.meta_key = '_sd_donor_id'
        INNER JOIN {$wpdb->postmeta} pdt ON p.ID = pdt.post_id AND pdt.meta_key = '_sd_donation_date'
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
        AND pdt.meta_value BETWEEN %s AND %s",
        $range['start'],
        $range['end']
    ) );

    // Memberships: active (currently valid), new (started in period), expiring_soon
    // (next 30 days), revenue (sum amount of starts in period).
    $membership_stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            SUM(CASE WHEN pe.meta_value >= %s THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN ps.meta_value BETWEEN %s AND %s THEN 1 ELSE 0 END) as new_count,
            SUM(CASE WHEN pe.meta_value BETWEEN %s AND %s THEN 1 ELSE 0 END) as expiring_count,
            COALESCE(SUM(CASE WHEN ps.meta_value BETWEEN %s AND %s THEN pm.meta_value + 0 ELSE 0 END), 0) as revenue
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        INNER JOIN {$wpdb->postmeta} pe ON p.ID = pe.post_id AND pe.meta_key = '_sd_end_date'
        INNER JOIN {$wpdb->postmeta} ps ON p.ID = ps.post_id AND ps.meta_key = '_sd_start_date'
        WHERE p.post_type = 'sd_membership'
        AND p.post_status = 'publish'",
        $today,
        $range['start'],
        $range['end'],
        $today,
        $expiring_window,
        $range['start'],
        $range['end']
    ) );

    // Memberships by tier (count + revenue per tier, scoped to period).
    $by_tier_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            pt.meta_value as tier,
            COUNT(*) as count,
            COALESCE(SUM(pm.meta_value + 0), 0) as revenue
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        INNER JOIN {$wpdb->postmeta} ps ON p.ID = ps.post_id AND ps.meta_key = '_sd_start_date'
        INNER JOIN {$wpdb->postmeta} pt ON p.ID = pt.post_id AND pt.meta_key = '_sd_tier'
        WHERE p.post_type = 'sd_membership'
        AND p.post_status = 'publish'
        AND ps.meta_value BETWEEN %s AND %s
        GROUP BY pt.meta_value",
        $range['start'],
        $range['end']
    ) );

    $by_tier = [];
    foreach ( $by_tier_rows as $row ) {
        $tier = (string) $row->tier;
        if ( '' === $tier ) {
            continue;
        }
        $by_tier[ $tier ] = [
            'count'   => (int) $row->count,
            'revenue' => (float) $row->revenue,
        ];
    }

    // Memorials: count + sum in period. Filters by `_sd_donation_date`
    // (matching donations) so back-dated memorial entries land in the
    // intended reporting period rather than the period in which the post
    // happens to be saved.
    $memorial_stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(*) as count,
            COALESCE(SUM(pm.meta_value + 0), 0) as revenue
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        INNER JOIN {$wpdb->postmeta} pdt ON p.ID = pdt.post_id AND pdt.meta_key = '_sd_donation_date'
        WHERE p.post_type = 'sd_memorial'
        AND p.post_status = 'publish'
        AND pdt.meta_value BETWEEN %s AND %s",
        $range['start'],
        $range['end']
    ) );

    // Donors: lifetime total and new-in-period.
    $donor_total = (int) $wpdb->get_var(
        "SELECT COUNT(*)
        FROM {$wpdb->posts}
        WHERE post_type = 'sd_donor'
        AND post_status = 'publish'"
    );

    $donor_new = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_created_date'
        WHERE p.post_type = 'sd_donor'
        AND p.post_status = 'publish'
        AND pm.meta_value BETWEEN %s AND %s",
        $range['start'],
        $range['end']
    ) );

    $donation_total   = (float) $donation_stats->total;
    $donation_count   = (int) $donation_stats->count;
    $membership_total = (float) $membership_stats->revenue;
    $memorial_total   = (float) $memorial_stats->revenue;
    $memorial_count   = (int) $memorial_stats->count;

    return [
        'period'       => $period,
        'date_range'   => $range,
        'donations'    => [
            'total'           => $donation_total,
            'total_formatted' => Helpers\format_currency( $donation_total ),
            'count'           => $donation_count,
            'unique_donors'   => (int) $donation_stats->donors,
            'average'         => $donation_count > 0
                ? round( $donation_total / $donation_count, 2 )
                : 0,
        ],
        'memberships'  => [
            'active'            => (int) $membership_stats->active_count,
            'new'               => (int) $membership_stats->new_count,
            'expiring_soon'     => (int) $membership_stats->expiring_count,
            'revenue'           => $membership_total,
            'revenue_formatted' => Helpers\format_currency( $membership_total ),
            'by_tier'           => $by_tier,
        ],
        'memorials'    => [
            'total'             => $memorial_count,
            'new'               => $memorial_count,
            'revenue'           => $memorial_total,
            'revenue_formatted' => Helpers\format_currency( $memorial_total ),
        ],
        'donors'       => [
            'total' => $donor_total,
            'new'   => $donor_new,
        ],
        'generated_at' => wp_date( 'Y-m-d H:i:s' ),
    ];
}

/**
 * Bucket donation totals over time for the trend chart.
 *
 * Selects the grouping bucket (day / iso-week / month) based on the
 * period's span, then runs the GROUP BY DATE_FORMAT(...) aggregate that
 * Query builder can't express. Returns plain buckets; the renderer
 * picks the label format from the `bucket` field and draws the SVG.
 *
 * @since 1.1.3
 *
 * @param array $input Optional. `period` and `fiscal_year` (see
 *                     Helpers\get_date_range_for_period). Default 'fiscal_year'.
 * @return array{
 *     period: string,
 *     bucket: string,
 *     date_range: array{start:string, end:string},
 *     buckets: array<int, array{ key:string, period_start:string, total:float, count:int }>
 * }
 */
function donation_trend( array $input = [] ): array {
    $period = $input['period'] ?? 'fiscal_year';
    $range  = Helpers\get_date_range_for_period(
        $period,
        null,
        $input['fiscal_year'] ?? null
    );
    $start = $range['start'];
    $end   = $range['end'];

    $days_span = max( 1, (int) ( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) );
    if ( $days_span <= 14 ) {
        $group_format = '%Y-%m-%d';
        $bucket       = 'day';
    } elseif ( $days_span <= 90 ) {
        $group_format = '%x-%v'; // ISO year-week
        $bucket       = 'week';
    } else {
        $group_format = '%Y-%m';
        $bucket       = 'month';
    }

    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            DATE_FORMAT( pm_date.meta_value, %s ) as period_key,
            MIN( pm_date.meta_value ) as period_start,
            COALESCE( SUM( pm_amount.meta_value + 0 ), 0 ) as total,
            COUNT( * ) as count
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_sd_donation_date'
        JOIN {$wpdb->postmeta} pm_amount ON p.ID = pm_amount.post_id AND pm_amount.meta_key = '_sd_amount'
        WHERE p.post_type = 'sd_donation'
          AND p.post_status = 'publish'
          AND pm_date.meta_value >= %s
          AND pm_date.meta_value <= %s
        GROUP BY period_key
        ORDER BY period_start ASC",
        $group_format,
        $start,
        $end . ' 23:59:59'
    ) );

    $buckets = [];
    foreach ( $rows as $row ) {
        $buckets[] = [
            'key'          => (string) $row->period_key,
            'period_start' => (string) $row->period_start,
            'total'        => (float) $row->total,
            'count'        => (int) $row->count,
        ];
    }

    return [
        'period'     => $period,
        'bucket'     => $bucket,
        'date_range' => $range,
        'buckets'    => $buckets,
    ];
}

/**
 * Compute membership retention for a trailing window.
 *
 * "Retention" here means: of the memberships whose end_date fell in the
 * window, how many of those donors had a subsequent membership that
 * started on or after the expiry. The renewed-count query is a
 * self-correlated join on sd_membership — Query::for() can't express
 * that — so it stays raw, but it's now in the data layer (an ability)
 * rather than the renderer.
 *
 * @since 1.1.3
 *
 * @param array $input Optional. `window_months` (default 12).
 * @return array{
 *     window_months: int,
 *     window_start: string,
 *     window_end: string,
 *     expired_count: int,
 *     renewed_count: int,
 *     retention_rate: float
 * }
 */
function membership_retention( array $input = [] ): array {
    $months = max( 1, (int) ( $input['window_months'] ?? 12 ) );
    $from   = wp_date( 'Y-m-d', strtotime( "-{$months} months" ) );
    $to     = wp_date( 'Y-m-d' );

    // Expired-in-window count via Query builder.
    $expired_count = Query::for( 'sd_membership' )
        ->whereDateBetween( 'end_date', $from, $to )
        ->count();

    // Renewed = same donor, different membership, start_date >= the
    // expired one's end_date. Self-correlated join doesn't fit Query.
    global $wpdb;
    $renewed_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT( DISTINCT expired.ID )
        FROM {$wpdb->posts} expired
        JOIN {$wpdb->postmeta} pm_end ON expired.ID = pm_end.post_id AND pm_end.meta_key = '_sd_end_date'
        JOIN {$wpdb->postmeta} pm_donor ON expired.ID = pm_donor.post_id AND pm_donor.meta_key = '_sd_donor_id'
        JOIN {$wpdb->posts} renewed ON renewed.post_type = 'sd_membership'
            AND renewed.post_status = 'publish'
            AND renewed.ID != expired.ID
        JOIN {$wpdb->postmeta} pm_donor2 ON renewed.ID = pm_donor2.post_id
            AND pm_donor2.meta_key = '_sd_donor_id'
            AND pm_donor2.meta_value = pm_donor.meta_value
        JOIN {$wpdb->postmeta} pm_start ON renewed.ID = pm_start.post_id
            AND pm_start.meta_key = '_sd_start_date'
            AND pm_start.meta_value >= pm_end.meta_value
        WHERE expired.post_type = 'sd_membership'
          AND expired.post_status = 'publish'
          AND pm_end.meta_value >= %s
          AND pm_end.meta_value <= %s",
        $from,
        $to
    ) );

    $rate = $expired_count > 0
        ? round( ( $renewed_count / $expired_count ) * 100, 1 )
        : 0.0;

    return [
        'window_months'  => $months,
        'window_start'   => $from,
        'window_end'     => $to,
        'expired_count'  => $expired_count,
        'renewed_count'  => $renewed_count,
        'retention_rate' => $rate,
    ];
}

/**
 * Get pending admin action items.
 *
 * Returns a unified list of items needing admin attention: pending logo
 * reviews, memberships expiring soon, family notifications not yet sent.
 * Single source of truth for two consumers — the menu badge (which sums
 * the counts) and the dashboard widget's action list (which renders the
 * structured items). Each item carries a `type`, `count`, singular and
 * plural labels, and a deep-link URL.
 *
 * @since 1.1.3
 *
 * @param array $input Optional. `expiring_window_days` (default 7).
 * @return array{ items: array<int, array{type:string, count:int, label:string, label_plural:string, url:string}> }
 */
function action_items( array $input = [] ): array {
    $items = [];
    $expiring_window = (int) ( $input['expiring_window_days'] ?? ACTION_ITEMS_WINDOW_DAYS );

    // Pending logo reviews.
    if ( class_exists( '\\Starter_Shelter\\Admin\\Logo_Moderation' ) ) {
        $pending_logos = (int) \Starter_Shelter\Admin\Logo_Moderation::get_pending_count();
        if ( $pending_logos > 0 ) {
            $items[] = [
                'type'         => 'pending_logos',
                'count'        => $pending_logos,
                'label'        => __( 'logo pending review', 'starter-shelter' ),
                'label_plural' => __( 'logos pending review', 'starter-shelter' ),
                'url'          => admin_url( 'admin.php?page=starter-shelter-logos' ),
            ];
        }
    }

    // Memberships expiring within the configured window.
    $expiring = Query::for( 'sd_membership' )
        ->whereDateBetween(
            'end_date',
            wp_date( 'Y-m-d' ),
            wp_date( 'Y-m-d', strtotime( "+{$expiring_window} days" ) )
        )
        ->count();
    if ( $expiring > 0 ) {
        $items[] = [
            'type'         => 'expiring_memberships',
            'count'        => $expiring,
            /* translators: %d: number of days */
            'label'        => sprintf( __( 'membership expiring in %d days', 'starter-shelter' ), $expiring_window ),
            'label_plural' => sprintf( __( 'memberships expiring in %d days', 'starter-shelter' ), $expiring_window ),
            'url'          => admin_url( 'edit.php?post_type=sd_membership&expiring=' . $expiring_window ),
        ];
    }

    // Memorials with notify_family_enabled but no recorded family_notified_date.
    $pending_notifications = Query::for( 'sd_memorial' )
        ->where( 'notify_family_enabled', '1' )
        ->whereNotExists( 'family_notified_date' )
        ->count();
    if ( $pending_notifications > 0 ) {
        $items[] = [
            'type'         => 'pending_notifications',
            'count'        => $pending_notifications,
            'label'        => __( 'family notification pending', 'starter-shelter' ),
            'label_plural' => __( 'family notifications pending', 'starter-shelter' ),
            'url'          => admin_url( 'edit.php?post_type=sd_memorial&notify_pending=1' ),
        ];
    }

    return [ 'items' => $items ];
}

/**
 * Get recent admin activity feed (donations, memberships, memorials).
 *
 * Replaces a hand-rolled 60-line UNION ALL across three CPTs with a
 * per-type Query::for() pass + an in-PHP merge. Each item is plain
 * structured data — no display formatting — so consumers can render
 * however they like.
 *
 * @since 1.1.3
 *
 * @param array $input Optional. `limit` (default 5).
 * @return array{ items: array<int, array{
 *     type: string,
 *     id: int,
 *     post_date: string,
 *     amount: float,
 *     donor_id: int,
 *     donor_name: ?string,
 *     is_anonymous: bool,
 *     tier: ?string,
 *     honoree_name: ?string
 * }> }
 */
function recent_activity( array $input = [] ): array {
    $limit = max( 1, (int) ( $input['limit'] ?? 5 ) );

    // Fetch the most recent N from each CPT (ordered by post_date), then
    // merge + re-sort + slice in PHP. The per-type fetch is cheap because
    // each is a single primed WP_Query under the hood.
    $merged = [];
    foreach ( [ 'sd_donation', 'sd_membership', 'sd_memorial' ] as $post_type ) {
        $entities = Query::for( $post_type )
            ->orderBy( 'date', 'DESC' )
            ->get( $limit );

        foreach ( $entities as $entity ) {
            $post_id = (int) ( $entity['id'] ?? 0 );
            if ( ! $post_id ) {
                continue;
            }
            // The post is cached by the WP_Query above, so this is free.
            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }

            $merged[] = [
                'type'         => $post_type,
                'id'           => $post_id,
                'post_date'    => $post->post_date,
                'amount'       => (float) ( $entity['amount'] ?? 0 ),
                'donor_id'     => (int) ( $entity['donor_id'] ?? 0 ),
                'is_anonymous' => (bool) ( $entity['is_anonymous'] ?? false ),
                'tier'         => $entity['tier'] ?? null,           // memberships only
                'honoree_name' => $entity['honoree_name'] ?? null,   // memorials only
            ];
        }
    }

    // Sort the merged list by post_date desc and take the top N. String
    // comparison works for MySQL DATETIME / 'Y-m-d H:i:s'.
    usort( $merged, static fn( $a, $b ) => strcmp( $b['post_date'], $a['post_date'] ) );
    $merged = array_slice( $merged, 0, $limit );

    // Batch-resolve donor names: one Query for all donor_ids referenced.
    $donor_ids = array_filter( array_unique( array_column( $merged, 'donor_id' ) ) );
    $donor_names = [];
    if ( ! empty( $donor_ids ) ) {
        $donors = Query::for( 'sd_donor' )
            ->withArgs( [ 'post__in' => $donor_ids ] )
            ->get( count( $donor_ids ) );
        foreach ( $donors as $donor ) {
            $name = $donor['display_name'] ?? '';
            if ( '' === $name ) {
                $name = $donor['full_name'] ?? '';
            }
            $donor_names[ (int) $donor['id'] ] = '' === $name ? null : $name;
        }
    }
    foreach ( $merged as $idx => $item ) {
        $merged[ $idx ]['donor_name'] = $donor_names[ $item['donor_id'] ] ?? null;
    }

    return [ 'items' => $merged ];
}

/**
 * Get campaign report.
 *
 * @since 1.0.0
 *
 * @param array $input Input with campaign_id.
 * @return array|WP_Error Campaign statistics or error.
 */
function campaign_report( array $input ): array|WP_Error {
    $campaign_id = $input['campaign_id'] ?? 0;

    if ( ! $campaign_id ) {
        return new WP_Error(
            'invalid_campaign_id',
            __( 'Valid campaign ID is required.', 'starter-shelter' ),
            [ 'status' => 400 ]
        );
    }

    $term = get_term( $campaign_id, 'sd_campaign' );
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error(
            'campaign_not_found',
            __( 'Campaign not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    // Get donations for this campaign.
    $donations = Query::for( 'sd_donation' )
        ->whereInTaxonomy( 'sd_campaign', $campaign_id )
        ->orderBy( 'donation_date', 'DESC' )
        ->get();

    // Calculate stats.
    $total = array_sum( array_column( $donations, 'amount' ) );
    $donor_ids = array_unique( array_column( $donations, 'donor_id' ) );

    // Get campaign goal from term meta.
    $goal = get_term_meta( $campaign_id, 'goal', true );
    $goal = $goal ? (float) $goal : null;

    $donation_count = count( $donations );
    $average        = $donation_count > 0 ? round( $total / $donation_count, 2 ) : 0;
    $percent_of_goal = $goal ? min( 100, round( ( $total / $goal ) * 100, 1 ) ) : null;
    $remaining      = $goal ? max( 0, $goal - $total ) : null;

    return [
        'campaign'  => [
            'id'             => $campaign_id,
            'name'           => $term->name,
            'description'    => $term->description,
            'goal'           => $goal,
            'goal_formatted' => $goal ? Helpers\format_currency( $goal ) : null,
        ],
        'progress'  => [
            'total_raised'    => $total,
            'total_formatted' => Helpers\format_currency( $total ),
            'percent_of_goal' => $percent_of_goal,
            'donation_count'  => $donation_count,
            'donor_count'     => count( $donor_ids ),
            'average'         => $average,
            'remaining'       => $remaining,
        ],
        'donations' => $donations,
    ];
}
