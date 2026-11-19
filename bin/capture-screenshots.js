/**
 * Capture the WordPress.org screenshots for ShelterKit Donations.
 *
 * Take them only on a demo site filled by bin/seed-demo-data.php, never on a
 * site with real donors. The August 2026 set was taken on a shelter's
 * development copy and showed its real memorials and members, which the
 * repository then published. So this script:
 *   - has no default site: WP_URL is required
 *   - refuses to run unless every donor on the site has a reserved example
 *     address (example.org/.com/.net, or .test), as the seed script makes
 *   - blocks every request that isn't to the site itself
 *
 *   PLAYWRIGHT_PATH=../shelterkit-pets/node_modules/playwright \
 *   WP_URL=http://127.0.0.1:8093 WP_PASS='<demo admin password>' \
 *   node bin/capture-screenshots.js screenshots-draft
 *
 * It writes screenshot-1.png … screenshot-10.png in the order of the readme's
 * == Screenshots == captions. Review them, copy the keepers into
 * .wordpress-org/, and run bin/check-screenshots.sh.
 *
 * Optional: WP_USER (default admin); DONOR_USER / DONOR_PASS, the donor the
 * dashboard shot signs in as (default maria / demo-donor-pass, from the seed
 * script); PW_CHANNEL=chrome to drive the installed Chrome instead of
 * Playwright's own browser.
 *
 * Conventions that cost time to rediscover:
 *   - 'load', not 'networkidle', in wp-admin: Heartbeat keeps the network busy
 *   - log in by POSTing wp-login.php, then check for #wpadminbar
 *   - wait for the Interactivity API to hydrate before touching a form
 *   - element screenshots, not viewport clips, so tall content isn't sliced
 *   - hide #wpadminbar, notices and the footer before capturing
 */

const path = require( 'path' );
const fs = require( 'fs' );

const PLAYWRIGHT = process.env.PLAYWRIGHT_PATH
	? path.resolve( process.env.PLAYWRIGHT_PATH )
	: 'playwright';
let chromium;
try {
	( { chromium } = require( PLAYWRIGHT ) );
} catch ( e ) {
	console.error( `Could not load Playwright from "${ PLAYWRIGHT }". Set PLAYWRIGHT_PATH to a node_modules/playwright directory.` );
	process.exit( 1 );
}

const SITE = ( process.env.WP_URL || '' ).replace( /\/$/, '' );
const USER = process.env.WP_USER || 'admin';
const PASS = process.env.WP_PASS || '';
const DONOR_USER = process.env.DONOR_USER || 'maria';
const DONOR_PASS = process.env.DONOR_PASS || 'demo-donor-pass';
const OUT = process.argv[ 2 ] || 'screenshots-draft';
const MADE_UP = /@(example\.(org|com|net)|[a-z0-9-]+\.test)$/i;

const HIDE = `
  #wpadminbar, #wpfooter, .notice, .update-nag, #screen-meta, #screen-meta-links,
  .woocommerce-store-notice, .woocommerce-layout__header, .wp-block-template-part { display: none !important; }
  html.wp-toolbar { padding-top: 0 !important; }
  html { scroll-behavior: auto !important; }
`;

async function shot( page, selector, n, maxHeight = 0 ) {
	await page.addStyleTag( { content: HIDE } ).catch( () => {} );
	const target = await page.$( selector );
	if ( ! target ) {
		console.warn( `  SKIP  screenshot-${ n }.png: nothing matches ${ selector }` );
		return;
	}
	const file = path.join( OUT, `screenshot-${ n }.png` );
	const box = maxHeight ? await target.boundingBox() : null;
	if ( box && box.height > maxHeight ) {
		// The top of a tall screen: what it is, without the scroll.
		await page.screenshot( { path: file, fullPage: true, clip: { x: box.x, y: box.y, width: box.width, height: maxHeight } } );
	} else {
		await target.screenshot( { path: file } );
	}
	console.log( `  ok    screenshot-${ n }.png` );
}

async function go( page, url, settle ) {
	await page.goto( url, { waitUntil: 'load', timeout: 60000 } );
	if ( settle ) {
		await page.waitForSelector( settle, { timeout: 30000 } ).catch( () => {} );
	}
	await page.waitForTimeout( 1200 ); // Fonts, lazy images, hydration.
}

async function signedIn( browser, user, pass ) {
	const context = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
	const origin = new URL( SITE ).host;
	await context.route( '**/*', ( route ) => {
		const url = new URL( route.request().url() );
		// WordPress swaps emoji for images from s.w.org, which this blocks; so
		// block the local script that swaps them, and they draw natively.
		return url.host === origin && ! url.pathname.includes( 'wp-emoji' ) ? route.continue() : route.abort();
	} );
	if ( user ) {
		await context.addCookies( [ { name: 'wordpress_test_cookie', value: 'WP Cookie check', url: SITE } ] );
		await context.request.post( `${ SITE }/wp-login.php`, {
			form: { log: user, pwd: pass, 'wp-submit': 'Log In', testcookie: '1' },
			maxRedirects: 0,
		} );
	}
	return context.newPage();
}

( async () => {
	if ( ! SITE || ! PASS ) {
		console.error( 'Set WP_URL to the demo site and WP_PASS to its admin password. There is deliberately no default site.' );
		process.exit( 1 );
	}
	fs.mkdirSync( OUT, { recursive: true } );

	let browser;
	try {
		browser = await chromium.launch( process.env.PW_CHANNEL ? { channel: process.env.PW_CHANNEL } : {} );
	} catch ( e ) {
		console.error( `Could not start the browser: ${ e.message }` );
		process.exit( 1 );
	}

	const admin = await signedIn( browser, USER, PASS );
	await go( admin, `${ SITE }/wp-admin/`, '#wpadminbar' );
	if ( ! ( await admin.$( '#wpadminbar' ) ) ) {
		console.error( `Could not sign in to ${ SITE } as ${ USER }.` );
		process.exit( 1 );
	}

	// Real donors? Stop. Every address the seed script makes is reserved for examples.
	await go( admin, `${ SITE }/wp-admin/edit.php?post_type=skdon_donor&posts_per_page=200`, '#the-list' );
	const emails = await admin.$$eval( '#the-list td.column-email', ( cells ) => cells.map( ( c ) => c.innerText.trim() ).filter( Boolean ) );
	const real = emails.filter( ( e ) => ! MADE_UP.test( e ) );
	if ( ! emails.length || real.length ) {
		console.error( real.length
			? `This site has donors with real-looking addresses (${ real.slice( 0, 3 ).join( ', ' ) }…). Use a demo site from bin/seed-demo-data.php.`
			: 'Found no donors. Fill a demo site with bin/seed-demo-data.php first.' );
		process.exit( 1 );
	}

	const a = ( slug ) => `${ SITE }/wp-admin/admin.php?page=${ slug }`;
	const visitor = await signedIn( browser );

	// 1. The donation form, filled in.
	await go( visitor, `${ SITE }/donate/`, '.wp-block-shelter-donations-donation-form' );
	const D = '.wp-block-shelter-donations-donation-form';
	await visitor.locator( `${ D } .sd-amount-button` ).nth( 1 ).click();
	await visitor.locator( `${ D } select.sd-select` ).selectOption( 'medical-care' ).catch( () => {} );
	await visitor.locator( `${ D } .sd-dedication-section input` ).fill( 'In memory of Pepper' ).catch( () => {} );
	await visitor.locator( 'body' ).click( { position: { x: 5, y: 5 } } );
	await shot( visitor, D, 1 );

	// 2. The memorial wall.
	await go( visitor, `${ SITE }/memorial-wall/`, '.wp-block-shelter-donations-memorial-wall' );
	await shot( visitor, '.wp-block-shelter-donations-memorial-wall', 2 );

	// 3. Members: business supporters and members by tier.
	await go( visitor, `${ SITE }/members/`, '.wp-block-shelter-donations-members-wall' );
	await shot( visitor, '.wp-block-post-content', 3 );

	// 4. Campaigns, part way to their goals.
	await go( visitor, `${ SITE }/campaigns/`, '.wp-block-columns' );
	await visitor.waitForTimeout( 2500 ); // The progress bars fill in.
	await shot( visitor, '.wp-block-columns', 4 );

	// 5. A donor's own dashboard.
	const donor = await signedIn( browser, DONOR_USER, DONOR_PASS );
	await go( donor, `${ SITE }/my-giving/`, '.wp-block-shelter-donations-donor-dashboard' );
	await shot( donor, '.wp-block-shelter-donations-donor-dashboard', 5 );

	// 6. Reports.
	await go( admin, `${ a( 'shelterkit-donations-reports' ) }&period=all_time`, '#wpbody-content' );
	await shot( admin, '#wpbody-content', 6, 1100 );

	// 7. Quick Add, with two memorials entered.
	await go( admin, a( 'shelterkit-donations-quick-add' ), '#sd-qa-rows' );
	const row = ( n ) => admin.locator( '#sd-qa-rows > .sd-qa-row' ).nth( n );
	await row( 0 ).locator( '.select2-selection' ).click();
	await admin.keyboard.type( 'Kowal' );
	await admin.waitForSelector( '.select2-results__option--highlighted, .select2-results__option[aria-selected]', { timeout: 10000 } );
	await admin.keyboard.press( 'Enter' );
	await row( 0 ).locator( 'input[name$="[honoree_name]"]' ).fill( 'Duke' );
	await row( 0 ).locator( 'input[value="pet"]' ).check();
	await row( 0 ).locator( 'select[name$="[pet_species]"]' ).selectOption( 'dog' );
	await row( 0 ).locator( 'input[name$="[amount]"]' ).fill( '50' );
	await row( 0 ).locator( 'textarea[name$="[tribute_message]"]' ).fill( 'Fourteen years of muddy paws and a wagging tail.' );
	await row( 0 ).locator( 'input[name$="[email_donor]"]' ).check();
	await admin.locator( '.sd-qa-add-row' ).click();
	await row( 1 ).locator( 'input[name$="[donor_name]"]' ).fill( 'Robert Hale' );
	await row( 1 ).locator( 'input[name$="[donor_email]"]' ).fill( 'robert.hale@example.org' );
	await row( 1 ).locator( 'select[name$="[dedication_type]"]' ).selectOption( 'honor' );
	await row( 1 ).locator( 'input[name$="[honoree_name]"]' ).fill( 'Ms. Patel' );
	await row( 1 ).locator( 'input[name$="[amount]"]' ).fill( '30' );
	await row( 1 ).locator( '.sd-qa-family-toggle' ).check();
	await row( 1 ).locator( 'input[name$="[family_name]"]' ).fill( 'The Patel family' );
	await row( 1 ).locator( 'input[name$="[family_email]"]' ).fill( 'patel.family@example.org' );
	await row( 1 ).locator( 'input[name$="[email_family]"]' ).check();
	await shot( admin, '#wpbody-content', 7 );

	// 8. A memorial's history: bought at checkout, the family told, then edited.
	const bella = await admin.evaluate( async ( site ) => {
		const r = await fetch( `${ site }/wp-admin/edit.php?post_type=skdon_memorial&s=Bella` );
		const m = ( await r.text() ).match( /id="post-(\d+)"/ );
		return m ? m[ 1 ] : '';
	}, SITE );
	await go( admin, `${ SITE }/wp-admin/post.php?post=${ bella }&action=edit`, '#skdon-activity-history' );
	await shot( admin, '#skdon-activity-history', 8 );

	// 9. Funds, tiers and donor levels are settings, not code.
	await go( admin, `${ a( 'shelterkit-donations-settings' ) }&tab=data`, '#wpbody-content' );
	await shot( admin, '#wpbody-content', 9, 1150 );

	// 10. Export and a full backup.
	await go( admin, a( 'shelterkit-donations-import-export' ), '#wpbody-content' );
	await shot( admin, '#wpbody-content', 10 );

	await browser.close();
	console.log( `\nDrafts in ${ OUT }/. Review them, copy the keepers into .wordpress-org/, and run bin/check-screenshots.sh.` );
} )();
