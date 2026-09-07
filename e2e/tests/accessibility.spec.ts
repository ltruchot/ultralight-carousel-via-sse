import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { FIXTURES } from '../fixtures';
import { visibleSlide, waitForBurst } from '../helpers';

/**
 * This carousel starts on its own and cannot be stopped, which is a knowing
 * failure of WCAG 2.2.2 (level A) and is written down as such in the readme.
 * Everything else below is a condition of acceptance, not a nicety -- and each
 * assertion here is written on something the carousel DOES, because assertions
 * written on what it does not do were satisfied by the carousel being broken.
 */
test.describe( 'accessibility', () => {
	test( 'axe finds nothing on the carousel', async ( { page } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		await waitForBurst( page, FIXTURES.many.slides );

		const results = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
			.include( '.wp-block-ulcar-carousel' )
			.analyze();

		expect( results.violations.map( ( v ) => `${ v.id }: ${ v.help }` ) ).toEqual( [] );
	} );

	test( 'axe would notice if the alt text went away', async ( { page } ) => {
		// A green run only means something if the tool is looking at our subtree.
		// Take the alternative text off the photograph on screen and it must go
		// red -- otherwise the assertion above is decoration.
		await page.goto( `/${ FIXTURES.many.slug }/` );
		await waitForBurst( page, FIXTURES.many.slides );
		await page.evaluate( () =>
			document
				.querySelectorAll( '.ulcar-slide img' )
				.forEach( ( image ) => image.removeAttribute( 'alt' ) )
		);

		const results = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
			.include( '.wp-block-ulcar-carousel' )
			.analyze();

		expect( results.violations.map( ( v ) => v.id ) ).toContain( 'image-alt' );
	} );

	test( 'it never announces the photographs it changes', async ( { page } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		await waitForBurst( page, FIXTURES.many.slides );

		// Reading a photograph out every five seconds is hostile, not helpful --
		// and with nothing for the visitor to press, there is no moment at which
		// announcing one would be a reply to anything they did.
		const announcing = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.wp-block-ulcar-carousel, .wp-block-ulcar-carousel *' ) ]
				.map( ( el ) => el.getAttribute( 'aria-live' ) )
				.filter( ( value ) => null !== value && 'off' !== value )
		);

		expect( announcing ).toEqual( [] );
	} );

	test( 'slides off screen are out of the tab order and out of the tree', async ( { page } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		await waitForBurst( page, FIXTURES.many.slides );

		const reachable = await page.evaluate(
			() =>
				[ ...document.querySelectorAll( '.ulcar-slide[hidden]' ) ].flatMap( ( slide ) => [
					...slide.querySelectorAll( 'a, button, input, [tabindex]' ),
				] ).length
		);

		// An opacity-0 slide would stay focusable and stay readable.
		expect( reachable ).toBe( 0 );
		await expect( page.locator( '.ulcar-slide[hidden]' ).first() ).toBeHidden();
	} );

	test( 'reduced motion stops it, and the same run proves it would have moved', async ( {
		browser,
	} ) => {
		// Two contexts in one test, on purpose.
		//
		// The earlier version of this test had only the second half: it asserted
		// that the slide did not change under reduced motion. A carousel that had
		// stopped working satisfies that just as well, and this test stayed green
		// through a real bug that froze the carousel on slide 1 while flashing the
		// whole page every five seconds. The first half is what makes the second
		// half mean anything: if the plugin is dead, the control arm fails.
		const moving = await browser.newContext();
		const still = await browser.newContext( { reducedMotion: 'reduce' } );
		const pages = await Promise.all( [ moving.newPage(), still.newPage() ] );

		await Promise.all( pages.map( ( page ) => page.goto( `/${ FIXTURES.many.slug }/` ) ) );
		await Promise.all( pages.map( ( page ) => waitForBurst( page, FIXTURES.many.slides ) ) );

		const before = await Promise.all( pages.map( ( page ) => visibleSlide( page ) ) );
		await pages[ 0 ].waitForTimeout( 11_000 );
		const after = await Promise.all( pages.map( ( page ) => visibleSlide( page ) ) );

		// No stylesheet can stop a timer, so this is checked inside the expression
		// that drives the rotation, on every tick -- which also means turning the
		// system setting on mid-visit is obeyed.
		expect( after[ 0 ] ).not.toBe( before[ 0 ] );
		expect( after[ 1 ] ).toBe( before[ 1 ] );

		await Promise.all( [ moving.close(), still.close() ] );
	} );

	test( 'every slide is named for a screen reader', async ( { page } ) => {
		await page.goto( `/${ FIXTURES.many.slug }/` );
		await waitForBurst( page, FIXTURES.many.slides );

		const labels: string[] = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.ulcar-track > .ulcar-slide' ) ].map( ( s ) =>
				s.getAttribute( 'aria-label' )
			)
		);

		// Every slide names its position and the total, in whatever language the
		// site runs. Asserting the wording would test the translation instead.
		expect( labels ).toHaveLength( FIXTURES.many.slides );
		labels.forEach( ( label, index ) => {
			expect( label ).toContain( String( index + 1 ) );
			expect( label ).toContain( String( FIXTURES.many.slides ) );
		} );
		expect( new Set( labels ).size ).toBe( FIXTURES.many.slides );
	} );
} );
