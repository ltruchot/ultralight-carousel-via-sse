/**
 * Diagnostics, and nothing else.
 *
 * This module deliberately does NOT import Datastar. If it did, a runtime that
 * failed to load would take this file down with it -- and the one moment a
 * diagnostic is worth having is the moment the runtime is missing. It runs on
 * its own and reports what it can see.
 *
 * Everything it writes goes to the console, never to the page: a visitor is not
 * the person who can fix any of this, and a carousel that quietly stays on its
 * first image is a far better failure than an error message over a photograph.
 */
( function () {
	'use strict';

	var VERSION = '1.0.3';
	var PREFIX = '[Ultralight Carousel] ';
	var root = document.documentElement;

	/**
	 * Two runtimes on one page, which is fatal rather than untidy.
	 *
	 * Measured: with a second Datastar runtime present, the page made TWO calls
	 * to the stream -- each runtime keeps its own signal store, so the guard
	 * that is meant to prevent a second burst cannot see the other one -- and
	 * stopped responding within 0.7 second. That is why this is an error and not
	 * a warning, and why the message says what it says.
	 *
	 * Datastar exposes no global and carries no version string, so there is
	 * nothing to ask it -- checked in the shipped bundle. What can be seen is
	 * the page itself: every module script whose file is called datastar.
	 * Another plugin shipping its own copy registers its own module id, and
	 * WordPress has no way to know the two are the same library.
	 */
	var reported = false;

	function reportDuplicateRuntimes() {
		if ( reported ) {
			return;
		}

		var runtimes = Array.prototype.slice
			.call( document.querySelectorAll( 'script[type="module"][src]' ) )
			.map( function ( script ) {
				return script.src;
			} )
			.filter( function ( src ) {
				return /datastar[-.@][^/]*\.js/i.test( src ) || /\/datastar\.js/i.test( src );
			} );

		if ( runtimes.length < 2 ) {
			return;
		}

		reported = true;
		window.console.error(
			PREFIX +
				'Two or more Datastar runtimes are on this page. This page will ' +
				'stop responding: each runtime keeps its own state, so both react ' +
				'to the same attributes and neither can see the other. Measured, ' +
				'the page froze within a second.\n  ' +
				runtimes.join( '\n  ' ) +
				'\nPoint them at one file with the `ulcar_datastar_src` filter, or ' +
				'stop one of the plugins from loading its own copy.'
		);
	}

	// Twice: now, and again with the late check below. A plugin that injects its
	// runtime from a script rather than from the markup would not be in the
	// document yet -- and a check that only ever looks once would call that
	// page clean.
	reportDuplicateRuntimes();

	// Two copies of THIS plugin, which the check above cannot see when both
	// carry the same file name.
	if ( root.dataset.ulcarRuntime && root.dataset.ulcarRuntime !== VERSION ) {
		window.console.error(
			PREFIX +
				'Datastar ' +
				root.dataset.ulcarRuntime +
				' was already on this page and this plugin bundles ' +
				VERSION +
				'. Two versions of one library share the same attributes and will ' +
				'disagree about them.'
		);
	}

	root.dataset.ulcarRuntime = VERSION;

	/**
	 * Did the burst fail outright?
	 *
	 * Datastar says nothing in the console when a request comes back with a
	 * status other than 200, nor when the network gives up: it dispatches a
	 * `datastar-fetch` event and stops -- read in the shipped bundle, `error`
	 * with the status for anything from 400 up, `retries-failed` after the
	 * last reconnection attempt. The status is the one fact that names the
	 * cause: 401 or 403 is a site that restricts its REST API to logged-in
	 * users, 404 is a REST API that is not routed at all, 5xx is PHP.
	 * Recorded per carousel so the report below can quote it instead of
	 * guessing.
	 */
	var failures = {};

	document.addEventListener( 'datastar-fetch', function ( event ) {
		var detail = event.detail || {};
		var carousel = detail.el && detail.el.closest ? detail.el.closest( '.ulcar-carousel' ) : null;

		if ( ! carousel ) {
			return;
		}

		if ( 'error' === detail.type ) {
			failures[ carousel.id ] = 'HTTP ' + ( ( detail.argsRaw && detail.argsRaw.status ) || 'error' );
		} else if ( 'retries-failed' === detail.type ) {
			failures[ carousel.id ] = 'a network error, after every retry';
		}
	} );

	/**
	 * Did the burst ever land?
	 *
	 * A carousel that never received its burst stays on its first image. That is
	 * the intended failure -- nothing is broken for the visitor -- but it is
	 * indistinguishable from a carousel of one image unless somebody says so.
	 *
	 * What proves the burst landed is the marker the server puts on the cadence
	 * element, not the number of slides: a carousel whose other photographs were
	 * deleted after its page went into a cache receives a burst that carries
	 * nothing but that marker, and it is not broken.
	 *
	 * Five seconds: the burst is asked for 500ms after load and answers in one
	 * round trip. Anything still alone after ten times that is not slow, it is
	 * absent.
	 */
	window.setTimeout( function () {
		reportDuplicateRuntimes();

		Array.prototype.slice
			.call( document.querySelectorAll( '.ulcar-carousel' ) )
			.forEach( function ( carousel ) {
				if ( carousel.querySelector( '[data-ulcar-burst]' ) ) {
					return;
				}

				var expression = carousel.getAttribute( 'data-init__delay.500ms' ) || '';
				var url = expression.match( /@get\('([^']+)'/ );
				var failure = failures[ carousel.id ];

				window.console.error(
					PREFIX +
						'The carousel "' +
						( carousel.getAttribute( 'aria-label' ) || carousel.id ) +
						'" never received its slides, and is showing its first image only.\n' +
						'Its stream is ' +
						( url ? url[ 1 ] : 'not in the markup' ) +
						( failure ? '\nThe request failed with: ' + failure : '' ) +
						'\nThings that produce exactly this: a REST API restricted to logged-in ' +
						'users (allow the `ulcar/v1` namespace), a Site Address that is not the ' +
						'one visitors use (the request is then cross-origin), the Datastar ' +
						'runtime blocked or delayed by an optimisation plugin, or a ' +
						'Content-Security-Policy without the nonce filter. See the readme.'
				);
			} );
	}, 5000 );
} )();
