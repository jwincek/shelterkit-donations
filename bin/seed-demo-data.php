<?php
/**
 * Fill an empty, throwaway site with made-up donors, gifts, members and
 * memorials, and a page for each block, for the WordPress.org screenshots.
 *
 * Every person, business and pet here is invented. Never take listing
 * screenshots on a site with real donors: the August 2026 set was taken on
 * the development copy of a shelter's site, and showed its real memorials
 * and members.
 *
 *   wp eval-file bin/seed-demo-data.php --user=admin
 *
 * Needs WooCommerce and this plugin active. Refuses to run on a site that
 * already has donors or orders. Sends no email: everything is created as
 * internal processing, as the importers do. Sets the site's time zone to
 * America/Los_Angeles first.
 *
 * Business logos are drawn with GD. Set SKDON_DEMO_FONT to a TrueType font
 * for nicer ones; without it, GD's built-in font is used.
 *
 * @package Starter_Shelter
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Admin\Shared\Donor_Lookup;
use Starter_Shelter\Helpers;

if ( ! defined( 'WP_CLI' ) || ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wc_get_orders' ) ) {
	exit( "Run with WP-CLI, with WooCommerce and ShelterKit Donations active.\n" );
}
if ( get_posts( [ 'post_type' => 'skdon_donor', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ] )
	|| wc_get_orders( [ 'limit' => 1, 'return' => 'ids' ] ) ) {
	WP_CLI::error( 'This site already has donors or orders. Run this only on an empty, throwaway site.' );
}

// A shelter's own time zone, not UTC: every date the screenshots show is
// read in it, and a UTC site hides time-zone bugs (#62).
update_option( 'timezone_string', 'America/Los_Angeles' );

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

mt_srand( 2026 );
$now   = new DateTimeImmutable( 'now', wp_timezone() );
$at    = static fn( int $days_ago ): string => $now->modify( "-{$days_ago} days" )->setTime( mt_rand( 8, 20 ), mt_rand( 0, 59 ) )->format( 'Y-m-d H:i:s' );
$pick  = static fn( array $list ) => $list[ mt_rand( 0, count( $list ) - 1 ) ];
$count = [ 'donations' => 0, 'memberships' => 0, 'memorials' => 0 ];

$run = static function ( string $ability, array $input ) {
	$a      = wp_get_ability( $ability );
	$result = $a ? $a->execute( $input ) : new WP_Error( 'no_ability', $ability );
	if ( is_wp_error( $result ) ) {
		WP_CLI::warning( "{$ability}: " . $result->get_error_message() );
		return null;
	}
	return $result;
};

// The plugin's WooCommerce products, as the first admin visit would make them.
Starter_Shelter\Core\Activator::maybe_create_products();

Helpers\set_internal_processing( true );

// ─── Campaigns ──────────────────────────────────────────────────────
$campaign = static function ( string $name, string $description, string $type, float $goal, int $ends_in ) use ( $now ): int {
	$term = wp_insert_term( $name, 'skdon_campaign', [ 'description' => $description ] );
	$id   = (int) $term['term_id'];
	update_term_meta( $id, '_skdon_campaign_type', $type );
	update_term_meta( $id, '_skdon_goal', $goal );
	update_term_meta( $id, '_skdon_end_date', $now->modify( "+{$ends_in} days" )->format( 'Y-m-d' ) );
	return $id;
};
$winter  = $campaign( 'Winter Warmth Fund', 'Heated beds, blankets and a warm kennel for every dog this winter.', 'donation_drive', 5000, 45 );
$kittens = $campaign( 'Kitten Season Ready', 'Bottles, formula and foster supplies before the spring kittens arrive.', 'donation_drive', 3000, 140 );
$drive   = $campaign( 'Fifty New Members', 'Help us welcome fifty new members before spring.', 'membership_drive', 50, 100 );

// ─── Donors ─────────────────────────────────────────────────────────
$people = [
	[ 'Maria', 'Alvarez' ], [ 'James', 'Okafor' ], [ 'Priya', 'Natarajan' ], [ 'Tom', 'Becker' ],
	[ 'Grace', 'Lindqvist' ], [ 'Daniel', 'Moreau' ], [ 'Aiko', 'Tanaka' ], [ 'Samuel', 'Price' ],
	[ 'Hannah', 'Kowalski' ], [ 'Luis', 'Ortega' ], [ 'Emily', 'Chen' ], [ 'Marcus', 'Reid' ],
	[ 'Sofia', 'Rossi' ], [ 'Owen', 'Gallagher' ], [ 'Fatima', 'Haddad' ], [ 'Ben', 'Carter' ],
	[ 'Nora', 'Fitzgerald' ], [ 'Elena', 'Petrova' ], [ 'David', 'Kim' ], [ 'Rachel', 'Stein' ],
	[ 'Ahmed', 'Farouk' ], [ 'Claire', 'Dubois' ], [ 'Peter', 'Lund' ], [ 'Zoe', 'Martin' ],
];
$donors = [];
foreach ( $people as [ $first, $last ] ) {
	$donor = Donor_Lookup::find_or_create( [
		'email'         => strtolower( "{$first}.{$last}@example.org" ),
		'first_name'    => $first,
		'last_name'     => $last,
		'display_name'  => "{$first} {$last}",
		'import_source' => 'demo',
	] );
	$donors[ "{$first} {$last}" ] = (int) $donor['id'];
}
$donor_ids = array_values( $donors );

// ─── Donations: a year of gifts, busiest in the giving season ───────
$funds      = [ 'general-fund', 'general-fund', 'general-fund', 'medical-care', 'medical-care', 'food-supplies', 'rescue-operations', 'spay-neuter-clinic', 'facility', 'emergency-fund' ];
$amounts    = [ 15, 20, 25, 25, 30, 50, 50, 50, 75, 100, 100, 150, 200, 250 ];
$dedication = [ 'In memory of Pepper, our first rescue', "For Mom's birthday", 'Thank you for Luna!', 'In honor of the volunteers', 'For the senior dogs' ];
// Gifts per month, from eleven months ago to this month.
foreach ( [ 4, 3, 5, 6, 4, 3, 4, 5, 6, 8, 9, 7 ] as $i => $gifts ) {
	$months_ago = 11 - $i;
	for ( $g = 0; $g < $gifts; $g++ ) {
		$days_ago = $months_ago * 30 + mt_rand( 0, 27 );
		$input    = [
			'donor_id'      => $pick( $donor_ids ),
			'amount'        => (float) $pick( $amounts ),
			'allocation'    => $pick( $funds ),
			'donation_date' => $at( max( 1, $days_ago ) ),
			'is_anonymous'  => 0 === mt_rand( 0, 9 ),
		];
		if ( 0 === mt_rand( 0, 5 ) ) {
			$input['dedication'] = $pick( $dedication );
		}
		$count['donations'] += $run( 'shelter-donations/create', $input ) ? 1 : 0;
	}
}
// The campaigns' gifts, recent.
foreach ( [ $winter => [ 1000, 500, 250, 250, 250, 150, 150, 100, 100, 100, 100, 75, 75, 50, 50, 50, 25, 25, 25, 20 ], $kittens => [ 250, 100, 100, 75, 50, 50, 50, 25, 25, 20 ] ] as $campaign_id => $gifts ) {
	foreach ( $gifts as $amount ) {
		$count['donations'] += $run( 'shelter-donations/create', [
			'donor_id'      => $pick( $donor_ids ),
			'amount'        => (float) $amount,
			'allocation'    => $campaign_id === $winter ? 'general-fund' : 'medical-care',
			'campaign_id'   => $campaign_id,
			'donation_date' => $at( mt_rand( 1, 40 ) ),
		] ) ? 1 : 0;
	}
}

// ─── Members ────────────────────────────────────────────────────────
$tiers = [ 'single' => 10, 'family' => 25, 'contributing' => 50, 'supporting' => 100, 'donor' => 250, 'sustaining' => 500, 'patron' => 750 ];
$members = [
	'Maria Alvarez' => 'sustaining', 'James Okafor' => 'family', 'Priya Natarajan' => 'supporting', 'Tom Becker' => 'single',
	'Grace Lindqvist' => 'patron', 'Daniel Moreau' => 'contributing', 'Aiko Tanaka' => 'family', 'Samuel Price' => 'donor',
	'Hannah Kowalski' => 'single', 'Luis Ortega' => 'supporting', 'Emily Chen' => 'contributing', 'Marcus Reid' => 'family',
	'Sofia Rossi' => 'sustaining', 'Owen Gallagher' => 'single', 'Fatima Haddad' => 'supporting', 'Ben Carter' => 'contributing',
	'Nora Fitzgerald' => 'donor', 'Elena Petrova' => 'family',
];
$n = 0;
foreach ( $members as $name => $tier ) {
	// Most joined during the drive; three joined nearly a year ago and renew soon.
	$days_ago = $n < 3 ? 345 + $n * 5 : ( $n < 12 ? mt_rand( 5, 95 ) : mt_rand( 120, 300 ) );
	$input    = [
		'donor_id'        => $donors[ $name ],
		'tier'            => $tier,
		'membership_type' => 'individual',
		'amount'          => (float) $tiers[ $tier ],
		'date'            => $at( $days_ago ),
		'is_anonymous'    => 'Owen Gallagher' === $name,
	];
	if ( $days_ago < 100 ) {
		$input['campaign_id'] = $drive;
	}
	$count['memberships'] += $run( 'shelter-memberships/create', $input ) ? 1 : 0;
	++$n;
}

// Businesses, with logos.
$logo = static function ( string $name, array $bg, array $fg ): int {
	$im = imagecreatetruecolor( 600, 300 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, ...$bg ) );
	$ink  = imagecolorallocate( $im, ...$fg );
	$font = (string) getenv( 'SKDON_DEMO_FONT' );
	if ( '' !== $font && is_readable( $font ) ) {
		$lines = explode( "\n", wordwrap( $name, 14, "\n" ) );
		foreach ( $lines as $i => $line ) {
			$box = imagettfbbox( 40, 0, $font, $line );
			$x   = (int) ( ( 600 - ( $box[2] - $box[0] ) ) / 2 );
			$y   = (int) ( 150 - ( count( $lines ) - 1 ) * 30 + $i * 60 + 14 );
			imagettftext( $im, 40, 0, $x, $y, $ink, $font, $line );
		}
	} else {
		imagestring( $im, 5, 40, 140, $name, $ink );
	}
	$file = wp_tempnam( sanitize_title( $name ) ) . '.png';
	imagepng( $im, $file );
	imagedestroy( $im );
	$id = media_handle_sideload( [ 'name' => sanitize_title( $name ) . '.png', 'tmp_name' => $file ], 0, $name . ' logo' );
	return is_wp_error( $id ) ? 0 : (int) $id;
};
foreach ( [
	[ 'Riverbend Veterinary Clinic', 'Claire Dubois', 'sustaining', [ 32, 96, 120 ], [ 255, 255, 255 ] ],
	[ 'Bark & Brew Coffee', 'Peter Lund', 'supporting', [ 92, 58, 38 ], [ 246, 226, 196 ] ],
	[ 'Golden Paws Grooming', 'Zoe Martin', 'donor', [ 244, 190, 66 ], [ 60, 40, 10 ] ],
	[ 'Meadow Market', 'Ahmed Farouk', 'contributing', [ 74, 140, 82 ], [ 255, 255, 255 ] ],
] as [ $business, $contact, $tier, $bg, $fg ] ) {
	$logo_id = $logo( $business, $bg, $fg );
	$result  = $run( 'shelter-memberships/create', [
		'donor_id'           => $donors[ $contact ],
		'tier'               => $tier,
		'membership_type'    => 'business',
		'amount'             => (float) $tiers[ $tier ],
		'business_name'      => $business,
		'logo_attachment_id' => $logo_id,
		'date'               => $at( mt_rand( 20, 250 ) ),
	] );
	if ( $result ) {
		update_post_meta( (int) $result['membership_id'], '_skdon_logo_status', 'approved' );
		++$count['memberships'];
	}
}

// ─── Memorials ──────────────────────────────────────────────────────
$tributes = [
	'Biscuit'          => [ 'pet', 'dog', 'memory', 'The best trail buddy a family could ask for.' ],
	'Luna'             => [ 'pet', 'cat', 'memory', 'Fifteen years of purring on the porch.' ],
	'Shadow'           => [ 'pet', 'cat', 'memory', '' ],
	'Max'              => [ 'pet', 'dog', 'memory', 'He knew every neighbor by their pocket treats.' ],
	'Pepper'           => [ 'pet', 'rabbit', 'memory', 'Small, fearless, and always first to breakfast.' ],
	'Rosie'            => [ 'pet', 'dog', 'memory', 'Adopted from this shelter in 2014. Thank you.' ],
	'Oliver'           => [ 'pet', 'cat', 'memory', '' ],
	'Clover'           => [ 'pet', 'horse', 'memory', 'Gentle giant of the north pasture.' ],
	'Scout'            => [ 'pet', 'dog', 'memory', 'Our brave old shepherd.' ],
	'Mochi'            => [ 'pet', 'cat', 'memory', '' ],
	'Peanut'           => [ 'pet', 'bird', 'memory', 'She sang every morning.' ],
	'Ruth Ellingsen'   => [ 'person', '', 'memory', 'She never met a stray she would not feed.' ],
	'Walter Kemp'      => [ 'person', '', 'memory', 'Volunteer, dog walker, friend.' ],
	'Joan Mercer'      => [ 'person', '', 'memory', 'For Grandma, who taught us to be kind to animals.' ],
	'Dr. Anita Shah'   => [ 'person', '', 'honor', 'Thank you for twenty years of care for our pets.' ],
	'Coach Dave Ruiz'  => [ 'person', '', 'honor', 'Happy retirement, Coach!' ],
	'The Kim Family'   => [ 'person', '', 'honor', 'On fostering their 100th kitten.' ],
];
$days = 330;
foreach ( $tributes as $honoree => [ $type, $species, $dedication_type, $message ] ) {
	$input = [
		'donor_id'        => $pick( $donor_ids ),
		'honoree_name'    => $honoree,
		'memorial_type'   => $type,
		'dedication_type' => $dedication_type,
		'amount'          => (float) $pick( [ 25, 25, 50, 50, 75, 100, 150 ] ),
		'donation_date'   => $at( $days ),
		'is_anonymous'    => 'Shadow' === $honoree,
	];
	if ( $species ) {
		$input['pet_species'] = $species;
	}
	if ( $message ) {
		$input['tribute_message'] = $message;
	}
	$result = $run( 'shelter-memorials/create', $input );
	if ( $result ) {
		update_post_meta( (int) $result['memorial_id'], '_skdon_candle_count', mt_rand( 0, 28 ) );
		++$count['memorials'];
	}
	$days -= mt_rand( 10, 25 );
}

Helpers\set_internal_processing( false );

// ─── A donor who can sign in, for the donor dashboard ───────────────
$user_id = wp_insert_user( [
	'user_login'   => 'maria',
	'user_pass'    => 'demo-donor-pass',
	'user_email'   => 'maria.alvarez@example.org',
	'first_name'   => 'Maria',
	'last_name'    => 'Alvarez',
	'display_name' => 'Maria Alvarez',
	'role'         => 'customer',
] );
if ( ! is_wp_error( $user_id ) ) {
	update_post_meta( $donors['Maria Alvarez'], '_skdon_user_id', $user_id );
}

// ─── A page for each block ──────────────────────────────────────────
$page = static fn( string $title, string $slug, string $content ): int => (int) wp_insert_post( [
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => $title,
	'post_name'    => $slug,
	'post_content' => $content,
] );
$block = static fn( string $name, array $attrs = [] ): string => sprintf( '<!-- wp:shelter-donations/%s %s/-->', $name, $attrs ? wp_json_encode( $attrs ) . ' ' : '' );

$donate = $page( 'Donate', 'donate', '<!-- wp:paragraph --><p>Every gift feeds, treats and shelters an animal waiting for a home.</p><!-- /wp:paragraph -->' . $block( 'donation-form' ) );
$join   = $page( 'Become a Member', 'join', $block( 'membership-form', [ 'showTypeToggle' => true ] ) );
$page( 'Give in Memory', 'give-in-memory', $block( 'memorial-form' ) );
$page( 'Memorial Wall', 'memorial-wall', $block( 'memorial-wall', [ 'layout' => 'grid', 'perPage' => 6, 'align' => 'wide' ] ) );
$page( 'Our Members', 'members', $block( 'business-slideshow', [ 'align' => 'wide' ] ) . $block( 'members-wall', [ 'align' => 'wide' ] ) );
$page( 'Campaigns', 'campaigns', '<!-- wp:columns {"align":"wide"} --><div class="wp-block-columns alignwide">'
	. '<!-- wp:column --><div class="wp-block-column">' . $block( 'campaign-card', [ 'campaignId' => $winter ] ) . '</div><!-- /wp:column -->'
	. '<!-- wp:column --><div class="wp-block-column">' . $block( 'campaign-card', [ 'campaignId' => $kittens ] ) . '</div><!-- /wp:column -->'
	. '<!-- wp:column --><div class="wp-block-column">' . $block( 'campaign-card', [ 'campaignId' => $drive ] ) . '</div><!-- /wp:column -->'
	. '</div><!-- /wp:columns -->' );
$page( 'My Giving', 'my-giving', $block( 'donor-dashboard' ) );

$options = (array) get_option( 'starter_shelter_options', [] );
update_option( 'starter_shelter_options', array_merge( $options, [ 'donation_page' => $donate, 'membership_page' => $join ] ) );

WP_CLI::success( sprintf(
	'%d donors, %d donations, %d memberships, %d memorials, 3 campaigns, 7 pages. Donor login: maria / demo-donor-pass.',
	count( $donors ),
	$count['donations'],
	$count['memberships'],
	$count['memorials']
) );
