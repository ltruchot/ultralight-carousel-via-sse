import { expect, test } from '@playwright/test';
import { FIXTURES } from '../fixtures';
import { streamUrl } from '../helpers';

/**
 * The plugin opens exactly one channel to the server. These tests say what that
 * channel accepts, and prove it accepts nothing else.
 */
test.describe( 'the one channel', () => {
	const tamper = ( url: string, from: string, to: string ) => url.replace( from, to );

	test( 'answers a request this site signed', async ( { page, request } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		const response = await request.get( await streamUrl( page ) );

		expect( response.status() ).toBe( 200 );
	} );

	test( 'refuses every altered request', async ( { page, request } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		const url = await streamUrl( page );
		const ids = new URL( url ).searchParams.get( 'ids' )!.split( ',' );

		// Without the signature this route would render the markup of any
		// attachment the site holds, to anyone willing to count from one.
		const forged = {
			'an id appended': tamper( url, `ids=${ ids.join( ',' ) }`, `ids=${ ids.join( ',' ) },1` ),
			'an id removed': tamper( url, `ids=${ ids.join( ',' ) }`, `ids=${ ids.slice( 0, -1 ).join( ',' ) }` ),
			'the order changed': tamper( url, `ids=${ ids.join( ',' ) }`, `ids=${ [ ...ids ].reverse().join( ',' ) }` ),
			'another size': tamper( url, 'size=large', 'size=full' ),
			'another target': url.replace( /target=ulcar-[a-f0-9]{12}/, 'target=ulcar-000000000000' ),
			'one character of the token': url.replace( /token=([a-f0-9]{31})[a-f0-9]/, ( _m, head ) => `token=${ head }0` ),
		};

		for ( const [ what, candidate ] of Object.entries( forged ) ) {
			const response = await request.get( candidate );
			expect( response.status(), what ).toBe( 403 );
			expect( ( await response.text() ), what ).not.toContain( '<img' );
		}
	} );

	test( 'refuses a malformed request before it reaches any code of ours', async ( { page, request } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		const url = await streamUrl( page );

		const malformed = {
			// The target is interpolated into the CSS selector of a patch.
			// Anything looser than the pattern would let a caller choose where
			// the site injects markup.
			'a target that is a selector': url.replace( /target=ulcar-[a-f0-9]{12}/, 'target=body' ),
			'a target with a wildcard': url.replace( /target=ulcar-[a-f0-9]{12}/, 'target=ulcar-%2A' ),
			'a size outside the list': url.replace( 'size=large', 'size=../../../etc/passwd' ),
			'ids that are not numbers': url.replace( /ids=[^&]+/, 'ids=1,2;DROP' ),
			'ids as an array': url.replace( /ids=[^&]+/, 'ids[]=1' ),
			'no token at all': url.replace( /&token=[a-f0-9]{32}/, '' ),
			'a token of the wrong shape': url.replace( /token=[a-f0-9]{32}/, 'token=short' ),
		};

		for ( const [ what, candidate ] of Object.entries( malformed ) ) {
			const response = await request.get( candidate );
			expect( response.status(), what ).toBeGreaterThanOrEqual( 400 );
			expect( ( await response.text() ), what ).not.toContain( '<img' );
		}
	} );

	test( 'ignores anything it was not asked about', async ( { page, request } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		const url = await streamUrl( page );

		const plain = await ( await request.get( url ) ).text();
		const noisy = await (
			await request.get( `${ url }&callback=alert&post_id=1&debug=1&fields=*&x[]=1` )
		).text();

		// Undeclared parameters are never read, so they can never change the
		// answer. Byte for byte is the only way to say that convincingly.
		expect( noisy ).toBe( plain );
	} );

	test( 'the method override WordPress offers can only ever deny, never escalate', async ( { page, request } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		const url = await streamUrl( page );
		const plain = await ( await request.get( url ) ).text();

		// `_method` and X-HTTP-Method-Override are WordPress core features and
		// apply to every REST route on every site; a plugin that fought them
		// would be surprising without being safer. What matters is where they
		// can lead, so it is measured rather than assumed.

		// Overriding to something this route does not answer: refused.
		expect( ( await request.get( `${ url }&_method=DELETE` ) ).status() ).toBe( 404 );

		// Overriding a POST into the GET this route does answer: the caller
		// arrives at the same public read, signature and all. There is nothing
		// else to arrive at -- the route writes nothing, stores nothing, and
		// another origin cannot read the answer.
		const disguised = await request.post( `${ url }&_method=GET` );
		expect( disguised.status() ).toBe( 200 );
		expect( await disguised.text() ).toBe( plain );
		expect( disguised.headers()[ 'access-control-allow-origin' ] ).toBeUndefined();
	} );

	test( 'answers nothing but GET', async ( { page, request } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		const url = await streamUrl( page );

		for ( const method of [ 'post', 'put', 'delete', 'patch' ] as const ) {
			const response = await request[ method ]( url );
			expect( response.status(), method ).toBe( 404 );
		}
	} );

	test( 'cannot be read by another origin', async ( { page, request } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		const response = await request.get( await streamUrl( page ), {
			headers: { Origin: 'https://not-this-site.example' },
		} );

		// WordPress hands every REST route the caller's own origin plus
		// Allow-Credentials. This route only ever serves the page it was
		// rendered into, so it takes those headers back off.
		const headers = response.headers();
		expect( headers[ 'access-control-allow-origin' ] ).toBeUndefined();
		expect( headers[ 'access-control-allow-credentials' ] ).toBeUndefined();
	} );

	test( 'is never cached by anything in between', async ( { page, request } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		const headers = ( await request.get( await streamUrl( page ) ) ).headers();

		// WordPress only sends no-cache headers to logged-in users, and this
		// route exists for anonymous ones.
		expect( headers[ 'cache-control' ] ).toContain( 'no-store' );
		expect( headers[ 'x-accel-buffering' ] ).toBe( 'no' );
	} );

	test( 'talks to nobody but this site', async ( { page } ) => {
		const foreign: string[] = [];
		// From BASE_URL, not from page.url(): before the first navigation the
		// page is about:blank, whose origin is the string "null" -- against
		// which every real URL looks foreign and the test fails for a reason
		// that has nothing to do with the plugin.
		const origin = new URL( process.env.BASE_URL! ).origin;

		page.on( 'request', ( r ) => {
			const url = r.url();
			if ( ! /ulcar|datastar/i.test( url ) ) {
				return; // Requests from the theme and other plugins are not ours to judge.
			}
			if ( ! url.startsWith( origin ) ) {
				foreign.push( url );
			}
		} );

		await page.goto( `/${ FIXTURES.many.slug }/` );
		await page.waitForTimeout( 3_000 );

		// Everything the plugin needs ships with it: no CDN, no phone home.
		expect( foreign ).toEqual( [] );
	} );

	test( 'an author cannot inject markup through the accessible name', async ( { page } ) => {
		await page.goto( `/${ FIXTURES.hostile.slug }/` );

		const label = await page.getAttribute( '.ulcar-carousel', 'aria-label' );

		// The value survives as text, and has not become another attribute.
		expect( label ).toContain( 'onload' );
		expect( await page.getAttribute( '.ulcar-carousel', 'onload' ) ).toBeNull();
		expect( await page.evaluate( () => ( window as never as { alerted?: boolean } ).alerted ) ).toBeUndefined();
	} );
} );
