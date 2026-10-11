/**
 * Regenerates .wordpress-org/ (screenshots, banners, icons) from the running
 * local demo site. Run bin/dev-site.sh --fresh and `wp server --path=.wp --port=8997`
 * first. Needs Chrome (or chrome-headless-shell) and playwright-core:
 *
 *   CHROME=/path/to/chrome PLAYWRIGHT=/path/to/node_modules/playwright-core \
 *   node bin/screenshots.mjs http://localhost:8997 [screenshot-1,art,...]
 *
 * The dev site's must-use plugin (bin/screenshot-helper.php) provides
 * ?shot_email=<template> and ?shot_manage=<address>.
 */
import { createRequire } from 'module';
import { copyFileSync, mkdirSync, unlinkSync } from 'fs';

const require = createRequire( import.meta.url );
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright-core' );

const [ , , base = 'http://localhost:8997', only = '' ] = process.argv;
const out = new URL( '../.wordpress-org/', import.meta.url ).pathname;
mkdirSync( out, { recursive: true } );

const browser = await chromium.launch( { executablePath: process.env.CHROME } );
const shots = [];
const want = ( name ) => ! only || only.split( ',' ).includes( name );
const post = base + '/usage-based-billing-is-here/';

async function page( options = {} ) {
	const context = await browser.newContext( { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, colorScheme: 'light', reducedMotion: 'reduce', ...options } );
	return { context, page: await context.newPage() };
}

async function shot( name, p, options = {} ) {
	await p.waitForTimeout( 250 );
	await p.screenshot( { path: out + name + '.png', ...options } );
	shots.push( name );
}

/** A clip (in page coordinates, for a full-page shot) from the top of one element to the bottom of another. */
async function between( p, from, to, pad = 40 ) {
	const top = await p.locator( from ).first().evaluate( ( el ) => el.getBoundingClientRect().top + window.scrollY );
	const bottom = await p.locator( to ).first().evaluate( ( el ) => el.getBoundingClientRect().bottom + window.scrollY );
	return { x: 0, y: Math.max( 0, top - pad ), width: p.viewportSize().width, height: bottom - top + pad * 2 };
}

// Signed-in session for the admin screens.
const admin = await page();
await admin.page.goto( base + '/wp-login.php' );
await admin.page.fill( '#user_login', 'maya' );
await admin.page.fill( '#user_pass', 'password' );
await Promise.all( [ admin.page.waitForNavigation(), admin.page.click( '#wp-submit' ) ] );
const screen = base + '/wp-admin/edit-comments.php?page=ruvelo-comment-subscriptions';

// 1. The comment form, as a guest, with "Subscribe without commenting" open.
if ( want( 'screenshot-1' ) ) {
	const { page: p } = await page( { viewport: { width: 1200, height: 1400 } } );
	await p.goto( post, { waitUntil: 'networkidle' } );
	await p.fill( '#comment', 'Could backfill cover last quarter too? We have three months of API usage in a spreadsheet.' );
	await p.fill( '#author', 'Priya Raman' );
	await p.fill( '#email', 'priya@raman.example' );
	await p.check( '#ruvelo-cs-replies' );
	await p.evaluate( () => document.activeElement.blur() );
	await p.evaluate( () => { document.querySelector( 'details.ruvelo-cs-sub' ).open = true; } );
	await shot( 'screenshot-1', p, { fullPage: true, clip: await between( p, '#reply-title', '.ruvelo-cs--subscribe' ) } );
}

// 2–3. Emails, as they arrive.
for ( const [ name, template ] of [ [ 'screenshot-2', 'confirm' ], [ 'screenshot-3', 'notification' ] ] ) {
	if ( want( name ) ) {
		const { page: p } = await page( { viewport: { width: 1000, height: template === 'confirm' ? 560 : 640 } } );
		await p.goto( base + '/?shot_email=' + template, { waitUntil: 'networkidle' } );
		await shot( name, p );
	}
}

// 4. The manage page, from a signed link.
if ( want( 'screenshot-4' ) ) {
	const { page: p } = await page( { viewport: { width: 1440, height: 940 } } );
	await p.goto( base + '/?shot_manage=jonas@berg.example', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-4', p );
}

// 5. The Subscribers screen.
if ( want( 'screenshot-5' ) ) {
	await admin.page.setViewportSize( { width: 1440, height: 980 } );
	await admin.page.goto( screen, { waitUntil: 'networkidle' } );
	await shot( 'screenshot-5', admin.page );
}

// 6. Import, after a dry run.
if ( want( 'screenshot-6' ) ) {
	await admin.page.setViewportSize( { width: 1440, height: 1080 } );
	await admin.page.goto( screen + '&tab=import', { waitUntil: 'networkidle' } );
	await Promise.all( [ admin.page.waitForNavigation(), admin.page.click( '.ruvelo-cs-source.is-found button:has-text("Dry run")' ) ] );
	await shot( 'screenshot-6', admin.page );
}

// 7. Preview emails.
if ( want( 'screenshot-7' ) ) {
	await admin.page.setViewportSize( { width: 1440, height: 1100 } );
	await admin.page.goto( screen + '&tab=emails&email=digest', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-7', admin.page );
}

// 8. Settings.
if ( want( 'screenshot-8' ) ) {
	await admin.page.setViewportSize( { width: 1440, height: 1300 } );
	await admin.page.goto( screen + '&tab=settings', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-8', admin.page );
}

// 9. On a phone.
if ( want( 'screenshot-9' ) ) {
	const { page: p } = await page( { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true } );
	await p.goto( base + '/?shot_manage=kenji.mori@halyard.test', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-9', p );
}

// 10. Dark mode.
if ( want( 'screenshot-10' ) ) {
	const { page: p } = await page( { viewport: { width: 1440, height: 940 }, colorScheme: 'dark' } );
	await p.goto( base + '/?shot_manage=jonas@berg.example&shot_scheme=dark', { waitUntil: 'networkidle' } );
	await shot( 'screenshot-10', p );
}

// WordPress.org banners and icons, framing the manage page.
if ( want( 'art' ) ) {
	const art = new URL( './art/', import.meta.url ).pathname;
	copyFileSync( out + 'screenshot-3.png', art + 'shot.png' );
	for ( const [ file, scale ] of [ [ 'banner-1544x500', 2 ], [ 'banner-772x250', 1 ] ] ) {
		const { page: p } = await page( { viewport: { width: 772, height: 250 }, deviceScaleFactor: scale } );
		await p.goto( 'file://' + art + 'banner.html', { waitUntil: 'networkidle' } );
		await shot( file, p );
	}
	for ( const [ file, scale ] of [ [ 'icon-128x128', 1 ], [ 'icon-256x256', 2 ] ] ) {
		const { page: p } = await page( { viewport: { width: 128, height: 128 }, deviceScaleFactor: scale } );
		await p.goto( 'file://' + art + 'icon.html', { waitUntil: 'networkidle' } );
		await shot( file, p, { omitBackground: true } );
	}
	unlinkSync( art + 'shot.png' );
}

await browser.close();
console.log( 'Wrote ' + shots.map( ( s ) => s + '.png' ).join( ', ' ) );
