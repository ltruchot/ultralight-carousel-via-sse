import { expect, test } from '@playwright/test';
import { FIXTURES } from '../fixtures';
import { streamUrl, waitForBurst } from '../helpers';

/**
 * What the page does when the burst does not go to plan, and what it says.
 *
 * The stream is replaced on the way in (page.route) so that each failure is
 * the exact one under test and not whatever the site happens to do that day.
 */
test.describe( 'the diagnostics', () => {
	test( 'the request carries no signals and is not restarted when the tab shows', async ( { page } ) => {
		// A hidden document from the start, then shown once the burst had time
		// to land. Datastar aborts and re-issues a GET on that transition unless
		// told otherwise, and a re-issued burst appends every slide twice.
		await page.addInitScript( () => {
			let hidden = true;
			Object.defineProperty( document, 'hidden', { get: () => hidden, configurable: true } );
			Object.defineProperty( document, 'visibilityState', {
				get: () => ( hidden ? 'hidden' : 'visible' ),
				configurable: true,
			} );
			( window as unknown as { __show: () => void } ).__show = () => {
				hidden = false;
				document.dispatchEvent( new Event( 'visibilitychange' ) );
			};
		} );

		// The burst is held back for a second so that the tab can be shown
		// while the request is still in flight -- the only moment at which
		// Datastar would abort and re-issue it. On localhost it lands in 44 ms
		// and nothing could be shown in time.
		const streams: string[] = [];
		await page.route( '**/ulcar/v1/slides*', async ( route ) => {
			streams.push( route.request().url() );
			await new Promise( ( resolve ) => setTimeout( resolve, 1_000 ) );
			await route.continue();
		} );

		await page.goto( `/${ FIXTURES.many.slug }/` );
		await page.waitForTimeout( 900 ); // data-init fires at 500 ms; the burst is now in flight.
		await page.evaluate( () => ( window as unknown as { __show: () => void } ).__show() );
		await waitForBurst( page, FIXTURES.many.slides );
		await page.waitForTimeout( 1_500 );

		expect( streams ).toHaveLength( 1 );
		await expect( page.locator( '.ulcar-slide' ) ).toHaveCount( FIXTURES.many.slides );

		// And the one request carried none of the page's signals: the route
		// reads none, and on a site that shares its Datastar runtime they
		// would be somebody else's data in this site's access log.
		expect( new URL( streams[ 0 ] ).searchParams.get( 'datastar' ) ).toBe( '{}' );
	} );

	test( 'a burst that brings no slides is not reported as a failure', async ( { page } ) => {
		// What the server sends for a carousel whose other images were deleted
		// after the page went into a cache: no elements, the count, the marker.
		await page.route( '**/ulcar/v1/slides*', async ( route ) => {
			const target = new URL( route.request().url() ).searchParams.get( 'target' );
			const key = target!.replace( 'ulcar-', 'k' );
			await route.fulfill( {
				status: 200,
				contentType: 'text/event-stream',
				body:
					`event: datastar-patch-signals\ndata: signals {"ulcar":{"${ key }":{"count":1,"loaded":true}}}\n\n` +
					`event: datastar-patch-elements\ndata: elements <div id="${ target }-cadence" hidden data-ulcar-burst=""></div>\n\n`,
			} );
		} );

		const errors: string[] = [];
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				errors.push( message.text() );
			}
		} );

		await page.goto( `/${ FIXTURES.many.slug }/` );
		await expect( page.locator( '[data-ulcar-burst]' ) ).toHaveCount( 1 );
		await page.waitForTimeout( 5_500 );

		await expect( page.locator( '.ulcar-slide' ) ).toHaveCount( 1 );
		expect( errors.filter( ( e ) => e.includes( '[Ultralight Carousel]' ) ) ).toEqual( [] );
	} );

	test( 'a refused burst is reported with its status', async ( { page } ) => {
		// What a site that restricts its REST API to logged-in users answers.
		await page.route( '**/ulcar/v1/slides*', ( route ) =>
			route.fulfill( { status: 401, contentType: 'application/json', body: '{"code":"rest_not_logged_in"}' } )
		);

		const errors: string[] = [];
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				errors.push( message.text() );
			}
		} );

		await page.goto( `/${ FIXTURES.many.slug }/` );
		await page.waitForTimeout( 5_500 );

		const report = errors.find( ( e ) => e.includes( '[Ultralight Carousel]' ) );
		expect( report ).toBeDefined();
		expect( report ).toContain( 'HTTP 401' );
		expect( report ).toContain( 'ulcar/v1' );
		expect( report ).toContain( await streamUrl( page ) );
	} );
} );
