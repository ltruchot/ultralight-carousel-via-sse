<?php
/**
 * Lets a site with a Content-Security-Policy run the carousel.
 *
 * @package UltralightCarouselViaSse
 */

namespace ULCAR;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges the site's CSP nonce to Datastar's CSP mode.
 */
final class Csp {

	/**
	 * Hooks the bridge up.
	 */
	public static function init(): void {
		add_filter( 'language_attributes', array( __CLASS__, 'add_nonce' ) );
	}

	/**
	 * Returns the nonce the site wants Datastar to use, or '' for none.
	 *
	 * The plugin deliberately does NOT invent a nonce. A nonce is only worth
	 * anything if it also appears in the `script-src` directive of the response,
	 * and only whatever emits that header can guarantee the two match. Inventing
	 * one here would produce an attribute that looks right and protects nothing.
	 *
	 * @return string Nonce for this response, or '' when the site has no CSP.
	 */
	public static function nonce(): string {
		return (string) apply_filters( 'ulcar_csp_nonce', '' );
	}

	/**
	 * Adds `data-nonce` to the opening `<html>` tag when the site supplies one.
	 *
	 * Datastar reads the attribute, removes it, and applies the nonce when it
	 * compiles expressions -- which is what lets it run under a policy that
	 * forbids `unsafe-eval`. Introduced in Datastar 1.0.3, the version bundled
	 * here.
	 *
	 * @param string $output Attributes already built by language_attributes().
	 * @return string Attributes, possibly with the nonce appended.
	 */
	public static function add_nonce( $output ) {
		$output = (string) $output;

		// Nothing to gain in the admin: the carousel is a front-end block, and
		// the block editor loads Datastar nowhere.
		if ( is_admin() ) {
			return $output;
		}

		// Another plugin may already ship Datastar and have set this. Two
		// values on one attribute would leave the browser reading whichever it
		// parsed first, which is not a thing to leave to chance.
		if ( str_contains( $output, 'data-nonce' ) ) {
			return $output;
		}

		$nonce = self::nonce();

		if ( '' === $nonce ) {
			return $output;
		}

		return $output . ' data-nonce="' . esc_attr( $nonce ) . '"';
	}
}
