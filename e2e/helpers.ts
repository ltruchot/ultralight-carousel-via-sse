import type { Page } from '@playwright/test';

/** The slide currently on screen, by its "n of m" label. */
export async function visibleSlide( page: Page, root = '.ulcar-carousel' ): Promise< string | null > {
	return page.evaluate( ( selector ) => {
		const carousel = document.querySelector( selector );
		const shown = [ ...( carousel?.querySelectorAll( '.ulcar-slide' ) ?? [] ) ].filter(
			( slide ) => ! slide.hasAttribute( 'hidden' )
		);
		return shown[ 0 ]?.getAttribute( 'aria-label' ) ?? null;
	}, root );
}

/** Waits until the burst has landed and the carousel holds every slide. */
export async function waitForBurst( page: Page, slides: number ): Promise< void > {
	await page.waitForFunction(
		( expected ) => document.querySelectorAll( '.ulcar-slide:not(noscript .ulcar-slide)' ).length >= expected,
		slides,
		{ timeout: 15_000 }
	);
}

/**
 * The rotation interval the burst delivered, in milliseconds.
 *
 * Read from the cadence element rather than assumed: it is a site setting,
 * and the suite runs against whatever site it is pointed at.
 */
export async function deliveredInterval( page: Page ): Promise< number > {
	const names = await page.locator( '[data-ulcar-burst]' ).first().evaluate( ( el ) =>
		Array.from( el.attributes ).map( ( a ) => a.name )
	);
	const name = names.find( ( n ) => n.startsWith( 'data-on-interval__duration.' ) );
	const match = name?.match( /duration\.(\d+)ms$/ );

	if ( ! match ) {
		throw new Error( `No cadence on the burst marker; attributes: ${ names.join( ' ' ) }` );
	}

	return Number( match[ 1 ] );
}

/**
 * The stream URL the page asked for, taken from the rendered markup.
 *
 * Rebuilding it in the test would mean recomputing the signature, which is
 * exactly the thing under test.
 */
export async function streamUrl( page: Page ): Promise< string > {
	const expression = await page.getAttribute( '.ulcar-carousel', 'data-init__delay.500ms' );
	// `@get('url', {…})`: the options after the URL are the request's, not
	// ours to parse here.
	const match = expression?.match( /@get\('([^']+)'/ );

	if ( ! match ) {
		throw new Error( `No stream URL in: ${ expression }` );
	}

	return match[ 1 ];
}

/**
 * How many pixels differ between two PNG buffers.
 *
 * The decoding is done by the browser under test rather than by a package: it
 * already has a PNG decoder and a canvas, and adding a dependency to compare
 * two screenshots would be a dependency to keep up to date for ever.
 *
 * This exists because computed styles are not what a visitor sees. A slide can
 * sit at 0.78 opacity, carry the higher z-index, and still be painted behind
 * the other one -- which is exactly the defect that shipped in 0.3.0 and which
 * every style-based assertion in this suite passed straight through.
 */
export async function differingPixels(
	page: Page,
	first: Buffer,
	second: Buffer
): Promise< number > {
	return page.evaluate(
		async ( [ a, b ] ) => {
			const decode = ( data: string ) =>
				new Promise< HTMLImageElement >( ( resolve, reject ) => {
					const image = new Image();
					image.onload = () => resolve( image );
					image.onerror = reject;
					image.src = `data:image/png;base64,${ data }`;
				} );

			const [ one, two ] = await Promise.all( [ decode( a ), decode( b ) ] );

			if ( one.width !== two.width || one.height !== two.height ) {
				throw new Error( 'Screenshots of different sizes cannot be compared.' );
			}

			const pixels = ( image: HTMLImageElement ) => {
				const canvas = document.createElement( 'canvas' );
				canvas.width = image.width;
				canvas.height = image.height;
				canvas.getContext( '2d' )!.drawImage( image, 0, 0 );
				return canvas
					.getContext( '2d' )!
					.getImageData( 0, 0, image.width, image.height ).data;
			};

			const [ x, y ] = [ pixels( one ), pixels( two ) ];
			let differing = 0;

			for ( let i = 0; i < x.length; i += 4 ) {
				const distance =
					Math.abs( x[ i ] - y[ i ] ) +
					Math.abs( x[ i + 1 ] - y[ i + 1 ] ) +
					Math.abs( x[ i + 2 ] - y[ i + 2 ] );

				if ( distance > 24 ) {
					differing += 1;
				}
			}

			return differing;
		},
		[ first.toString( 'base64' ), second.toString( 'base64' ) ]
	);
}
