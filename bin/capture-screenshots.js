/**
 * Capture WordPress.org screenshots for ShelterKit Donations.
 *
 * Conventions that cost time to rediscover:
 *   - channel:'chrome', because the cached chromium can predate this Playwright
 *   - log in once; check for #wpadminbar rather than the URL, and step past
 *     the administration-email interstitial by navigating to /wp-admin/
 *   - element screenshots, not viewport clips, so tall content is not sliced
 *   - hide #wpadminbar, .notice, .update-nag before capturing
 *
 * Playwright is not a dependency of this plugin (it has no build step). Point
 * PLAYWRIGHT_PATH at an install that has it — a sibling plugin's node_modules
 * is fine:
 *
 *   PLAYWRIGHT_PATH=../shelterkit-pets/node_modules/playwright \
 *   WP_PASS='<admin password>' node bin/capture-screenshots.js screenshots-draft
 *
 * Then review screenshots-draft/ and copy the keepers into .wordpress-org/
 * with their final numbers. The numbers must line up with the readme's
 * == Screenshots == captions — bin/check-screenshots.sh verifies that.
 */

const path = require( 'path' );

const PLAYWRIGHT = process.env.PLAYWRIGHT_PATH
	? path.resolve( process.env.PLAYWRIGHT_PATH )
	: 'playwright';

let chromium;
try {
	( { chromium } = require( PLAYWRIGHT ) );
} catch ( e ) {
	console.error(
		`Could not load Playwright from "${ PLAYWRIGHT }".\n` +
		'Set PLAYWRIGHT_PATH to a node_modules/playwright directory, e.g.\n' +
		'  PLAYWRIGHT_PATH=../shelterkit-pets/node_modules/playwright \\\n' +
		'  WP_PASS=... node bin/capture-screenshots.js screenshots-draft'
	);
	process.exit( 1 );
}

// Credentials come from the environment; create a throwaway admin first and
// delete it afterwards rather than using a real account.
const SITE = process.env.WP_URL || 'http://vchs-test.local';
const USER = process.env.WP_USER || 'shotbot';
const PASS = process.env.WP_PASS || '';
const OUT = process.argv[ 2 ] || 'screenshots-draft';

// Front-end pages differ per site; override rather than hardcoding.
const DONATE_PAGE = process.env.WP_DONATE_PAGE || 'donate';
const MEMORIAL_PAGE = process.env.WP_MEMORIAL_PAGE || 'memorials';
const MEMBERS_PAGE = process.env.WP_MEMBERS_PAGE || 'members';

const HIDE = `
  #wpadminbar, #wpfooter, .notice, .update-nag, #screen-meta, #screen-meta-links,
  #wpbody-content > .notice, .woocommerce-store-notice { display: none !important; }
  html { scroll-behavior: auto !important; }
`;

async function shot( page, selector, file ) {
	await page.addStyleTag( { content: HIDE } ).catch( () => {} );
	const target = await page.$( selector );
	if ( ! target ) {
		console.warn( `  SKIP  ${ file } — no element matching ${ selector }` );
		return false;
	}
	await target.screenshot( { path: path.join( OUT, file ) } );
	console.log( `  ok    ${ file }` );
	return true;
}

// 'load', not 'networkidle'. WordPress admin runs the Heartbeat API, which
// polls on a timer forever, so the network is never idle and every admin
// navigation times out. Wait for the document instead, then for the element
// the shot actually needs.
async function go( page, url, settle = '#wpbody-content' ) {
	await page.goto( url, { waitUntil: 'load', timeout: 60000 } );
	if ( settle ) {
		await page.waitForSelector( settle, { timeout: 30000 } ).catch( () => {} );
	}
	// Let webfonts and any lazy images paint before capturing.
	await page.waitForTimeout( 800 );
}

( async () => {
	if ( ! PASS ) {
		console.error( 'Set WP_PASS to the password of a throwaway admin user.' );
		process.exit( 1 );
	}

	const browser = await chromium.launch( { channel: 'chrome' } );
	const page = await browser.newPage( {
		viewport: { width: 1440, height: 1000 },
		deviceScaleFactor: 2,
	} );

	// --- log in ---------------------------------------------------------------
	await go( page, `${ SITE }/wp-login.php`, '#user_login' );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	// The administration-email interstitial can intercept the redirect; going
	// to /wp-admin/ directly steps past it. Assert on the admin bar, not the URL.
	await go( page, `${ SITE }/wp-admin/`, '#wpadminbar' );

	// --- admin screens --------------------------------------------------------
	const admin = ( slug ) => `${ SITE }/wp-admin/admin.php?page=${ slug }`;

	await go( page, `${ admin( 'shelterkit-donations-reports' ) }&period=all_time` );
	await shot( page, '#wpbody-content', 'reports.png' );

	await go( page, `${ admin( 'shelterkit-donations-reports' ) }&period=all_time&tab=campaigns` );
	await shot( page, '#wpbody-content', 'reports-campaigns.png' );

	await go( page, admin( 'shelterkit-donations-settings' ) );
	await shot( page, '#wpbody-content', 'settings-general.png' );

	await go( page, `${ admin( 'shelterkit-donations-settings' ) }&tab=products` );
	await shot( page, '#wpbody-content', 'settings-products.png' );

	await go( page, admin( 'shelterkit-donations' ) );
	await shot( page, '#wpbody-content', 'dashboard.png' );

	await go( page, admin( 'shelterkit-donations-import-export' ) );
	await shot( page, '#wpbody-content', 'import-export.png' );

	await go( page, admin( 'shelterkit-donations-logos' ) );
	await shot( page, '#wpbody-content', 'logo-moderation.png' );

	await go( page, `${ SITE }/wp-admin/edit-tags.php?taxonomy=sd_campaign` );
	await shot( page, '#wpbody-content', 'campaigns.png' );

	// --- front end ------------------------------------------------------------
	// Logged out, so the donor dashboard and account areas render as a visitor
	// sees them and the admin bar is gone entirely.
	const visitor = await browser.newContext( {
		viewport: { width: 1280, height: 1000 },
		deviceScaleFactor: 2,
	} );
	const pub = await visitor.newPage();

	for ( const [ slug, file, sel ] of [
		[ DONATE_PAGE, 'donation-form.png', 'main, .wp-site-blocks, body' ],
		[ MEMORIAL_PAGE, 'memorial-wall.png', 'main, .wp-site-blocks, body' ],
		[ MEMBERS_PAGE, 'members-wall.png', 'main, .wp-site-blocks, body' ],
	] ) {
		await pub.goto( `${ SITE }/${ slug }/`, { waitUntil: 'load', timeout: 60000 } ).catch( () => {} );
		await pub.waitForTimeout( 1200 );
		await shot( pub, sel.split( ',' )[ 0 ].trim(), file );
	}

	await browser.close();
	console.log( `\nDrafts in ${ OUT }/ — review, then copy the keepers into` );
	console.log( '.wordpress-org/ as screenshot-1.png, screenshot-2.png, ...' );
	console.log( 'and keep readme.txt == Screenshots == in the same order.' );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
