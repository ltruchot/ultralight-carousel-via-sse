<?php
/**
 * What the plugin directory reads before it reads any code.
 *
 * These headers are the most consequential lines in the repository and the
 * easiest to let drift: a Stable tag that no longer matches the version in the
 * PHP header leaves a plugin that cannot be updated, and says nothing about it.
 *
 * @package UltralightCarouselViaSse
 */

declare(strict_types=1);

namespace ULCAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PackagingTest extends TestCase {

	use ShippedFiles;

	private const ROOT = __DIR__ . '/../..';

	private function header( string $file, string $key ): string {
		$source = (string) file_get_contents( self::ROOT . '/' . $file );
		$found  = preg_match( '/^\s*\*?\s*' . preg_quote( $key, '/' ) . ':\s*(.+)$/m', $source, $matches );

		$this->assertSame( 1, $found, sprintf( '%s has no "%s" header.', $file, $key ) );

		return trim( $matches[1] );
	}

	/**
	 * @dataProvider provide_headers_that_must_agree
	 */
	public function test_the_two_files_agree( string $php_header, string $readme_header ): void {
		$this->assertSame(
			$this->header( 'ultralight-carousel-via-sse.php', $php_header ),
			$this->header( 'readme.txt', $readme_header )
		);
	}

	public static function provide_headers_that_must_agree(): array {
		return array(
			// The number shown on the download button comes from the PHP file,
			// while what is served comes from the Stable tag. Disagreeing means
			// offering one version and delivering another.
			'la version'           => array( 'Version', 'Stable tag' ),
			'le WordPress minimum' => array( 'Requires at least', 'Requires at least' ),
			'le PHP minimum'       => array( 'Requires PHP', 'Requires PHP' ),
		);
	}

	public function test_the_minimums_are_the_ones_the_code_actually_needs(): void {
		// The vendored SDK uses enums, which are a PARSE error below 8.1 --
		// announcing anything lower would mean a white screen on activation.
		$this->assertSame( '8.1', $this->header( 'ultralight-carousel-via-sse.php', 'Requires PHP' ) );

		// wp_register_script_module() and viewScriptModule arrived in 6.5.
		$this->assertSame( '6.5', $this->header( 'ultralight-carousel-via-sse.php', 'Requires at least' ) );

		// And the runtime guard has to agree with the header, because a site
		// that downgrades PHP after activation never goes through activation
		// again.
		$main = (string) file_get_contents( self::ROOT . '/ultralight-carousel-via-sse.php' );
		$this->assertStringContainsString( "define( 'ULCAR_MIN_PHP', '8.1' );", $main );
		$this->assertStringContainsString( "define( 'ULCAR_MIN_WP', '6.5' );", $main );
	}

	public function test_the_text_domain_is_the_slug(): void {
		// Not a convention: translate.wordpress.org matches the two, and a text
		// domain that differs from the slug gets no translations at all.
		//
		// The slug is taken from the main file's name, NOT from the directory:
		// a checkout can sit anywhere -- inside a container it is /app -- and a
		// test that depended on that would fail for a reason having nothing to
		// do with the plugin.
		$main = 'ultralight-carousel-via-sse.php';
		$slug = basename( $main, '.php' );

		$this->assertFileExists( self::ROOT . '/' . $main );
		$this->assertSame( $slug, $this->header( $main, 'Text Domain' ) );

		// Lowercase, hyphens, no underscores, 50 characters at most.
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug );
		$this->assertLessThanOrEqual( 50, strlen( $slug ) );

		// And every translated string in the shipped code uses that domain,
		// never a variable -- which would silently produce no translation.
		foreach ( array( 'includes', 'blocks' ) as $directory ) {
			foreach ( ( glob( self::ROOT . '/' . $directory . '/*.php' ) ? glob( self::ROOT . '/' . $directory . '/*.php' ) : array() ) as $file ) {
				$source = (string) file_get_contents( $file );
				preg_match_all( "/\b(?:__|_e|_x|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*'[^']*',\s*([^)]+)\)/", $source, $domains );
				foreach ( $domains[1] as $used ) {
					$this->assertSame( "'" . $slug . "'", trim( $used ), basename( $file ) );
				}
			}
		}
	}

	/**
	 * Lists translation files of one extension, or an empty array.
	 *
	 * @param string $path      Domain path, leading slash included.
	 * @param string $extension Extension without the dot.
	 * @return array<string> Absolute paths.
	 */
	private static function files( string $path, string $extension ): array {
		$found = glob( self::ROOT . $path . '/*.' . $extension );

		return $found ? $found : array();
	}

	public function test_the_shipped_translations_are_where_the_header_says(): void {
		// Without a Domain Path, WordPress looks for translations in the global
		// language directory only, and the ones travelling with the plugin are
		// never loaded. The symptom is a plugin that stays in English on a site
		// that is not -- with nothing anywhere to say why.
		$path = $this->header( 'ultralight-carousel-via-sse.php', 'Domain Path' );

		$this->assertSame( '/languages', $path );
		$this->assertDirectoryExists( self::ROOT . $path );
		$this->assertFileExists( self::ROOT . $path . '/ultralight-carousel-via-sse.pot' );

		// A .po beside a .mo, and never one without the other: the .mo is what
		// WordPress reads, the .po is what a human can edit.
		foreach ( self::files( $path, 'po' ) as $po ) {
			$this->assertFileExists( substr( $po, 0, -3 ) . '.mo', basename( $po ) . ' has no compiled .mo.' );
		}

		foreach ( self::files( $path, 'mo' ) as $mo ) {
			$this->assertFileExists( substr( $mo, 0, -3 ) . '.po', basename( $mo ) . ' has no source .po.' );
		}
	}

	public function test_the_editor_translations_are_shipped_as_json(): void {
		// Un `.mo` traduit le PHP et RIEN d'autre. Les chaines de l'editeur
		// passent par un fichier `<domaine>-<locale>-<md5>.json`, produit par
		// `wp i18n make-json` -- sans lui, l'editeur reste en anglais quelle que
		// soit la qualite de la traduction. Constate le 31/08/2026 : tout le
		// panneau lateral en anglais alors que le `.po` etait complet.
		$json = glob( dirname( __DIR__, 2 ) . '/languages/ultralight-carousel-via-sse-fr_FR-*.json' );

		$this->assertNotEmpty( $json, 'Aucun fichier de traduction JavaScript livre.' );

		$strings = json_decode( (string) file_get_contents( $json[0] ), true );
		$this->assertIsArray( $strings );

		// Il doit traduire ce que l'editeur affiche, pas seulement exister.
		$messages = $strings['locale_data']['messages'] ?? array();
		$this->assertArrayHasKey( 'Add or remove images', $messages );
		$this->assertNotSame( 'Add or remove images', $messages['Add or remove images'][0] );
	}

	public function test_the_editor_translations_are_wired_to_the_plugin_directory(): void {
		// `wp_set_script_translations()` sans chemin ne regarde que
		// `WP_LANG_DIR/plugins/` : la traduction que cette extension LIVRE n'est
		// alors jamais lue. C'est exactement ce qui s'est passe.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-block.php' );

		$this->assertMatchesRegularExpression(
			'/wp_set_script_translations\([^;]*ULCAR_PATH \. \x27languages\x27/s',
			$source
		);
	}

	public function test_the_readme_stays_within_what_the_directory_accepts(): void {
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );

		// Five tags at most, and a file over 10 KB "may result in errors".
		$tags = array_filter( array_map( 'trim', explode( ',', $this->header( 'readme.txt', 'Tags' ) ) ) );
		$this->assertLessThanOrEqual( 5, count( $tags ) );
		// 10 Kio, et le budget se depense surtout en changelog. Il n'y a donc que
		// la version courante dans `readme.txt`, l'historique vivant dans le depot :
		// sans cette regle on recoupe le fichier a chaque sortie, ce qui est arrive
		// trois fois de suite le 31/08/2026 avant qu'on s'en apercoive.
		//
		// The handbook says "larger than 10k", and does not say which k. Under
		// 10 000 bytes both readings hold. Measured on 07/09/2026: 6 857 bytes
		// after the rewrite, so there is room, and the first thing to cut when
		// it runs out is the changelog of the previous release, not an
		// explanation that helps whoever installs the plugin.
		$this->assertLessThan( 10000, strlen( $readme ) );

		// Tested up to takes digits, not "WP 6.8".
		$this->assertMatchesRegularExpression( '/^[0-9.]+$/', $this->header( 'readme.txt', 'Tested up to' ) );

		// The short description is the line after the header block, capped at
		// 150 characters and cut without warning past that.
		$short = trim( explode( "\n", trim( explode( "\n\n", $readme, 3 )[1] ) )[0] );
		$this->assertLessThanOrEqual( 150, strlen( $short ) );
		$this->assertStringNotContainsString( '<', $short );
	}

	public function test_nothing_ships_from_the_root_that_was_not_meant_to(): void {
		// The mirror image of .distignore. That file lists what to leave out;
		// this lists what may go in. Anything new at the root that is on
		// neither list fails here, which is how a hidden directory dropped by a
		// tool is caught before Plugin Check refuses the ZIP for it.
		$allowed = array(
			'LICENSE.txt',
			'README.md',
			'readme.txt',
			'ultralight-carousel-via-sse.php',
			'uninstall.php',
			'assets',
			'blocks',
			'includes',
			'languages',
		);

		$roots = array_values(
			array_unique(
				array_map(
					static fn( string $path ): string => explode( '/', $path, 2 )[0],
					$this->shipped_files()
				)
			)
		);
		sort( $roots );
		sort( $allowed );

		$this->assertSame( $allowed, $roots, 'Something at the root would ship that is not on the list.' );
	}

	public function test_the_editor_and_the_server_agree_on_the_most_images_a_carousel_holds(): void {
		// The server keeps the first MAX_SLIDES and drops the rest without a
		// word. The media modal cannot know the limit, so the editor script
		// carries its own copy to warn the author. Two copies of one number
		// only stay equal if something checks.
		$php = (string) file_get_contents( self::ROOT . '/includes/class-slides.php' );
		$js  = (string) file_get_contents( self::ROOT . '/blocks/carousel/index.js' );

		$this->assertSame( 1, preg_match( '/const MAX_SLIDES = (\d+);/', $php, $from_php ) );
		$this->assertSame( 1, preg_match( '/var MAX_SLIDES = (\d+);/', $js, $from_js ) );
		$this->assertSame( $from_php[1], $from_js[1] );
	}

	public function test_every_translation_header_line_is_well_formed(): void {
		// A header line that lost its closing quote does not stop msgfmt: it
		// swallows the next line into the same string, and the compiled
		// catalogue carries a version number cut in half and two dates fused
		// into one. Found in the shipped fr_FR file on 07/09/2026, so it is
		// asserted rather than trusted.
		foreach ( array_merge( glob( self::ROOT . '/languages/*.po' ), glob( self::ROOT . '/languages/*.pot' ) ) as $file ) {
			$source = (string) file_get_contents( $file );
			$header = explode( "\n\n", $source, 2 )[0];

			foreach ( explode( "\n", $header ) as $line ) {
				if ( '' === $line || str_starts_with( $line, '#' ) || str_starts_with( $line, 'msg' ) ) {
					continue;
				}
				$this->assertMatchesRegularExpression(
					'/^"[^"]*\\\\n"$/',
					$line,
					sprintf( '%s: header line %s is not one quoted string ending in \\n.', basename( $file ), $line )
				);
			}
		}
	}

	public function test_the_readme_says_where_the_source_is(): void {
		// Guideline 4 treats minified JS with no documented source as a failure,
		// and names the fix: a Development or Build section in readme.txt
		// pointing at the source repository. One minified file ships here — the
		// Datastar runtime — so the section is not optional.
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );

		$this->assertMatchesRegularExpression( '/^== (Development|Build) ==$/m', $readme );
		$this->assertStringContainsString( 'github.com/ltruchot/ultralight-carousel-via-sse', $readme );

		// And the source map that makes that one file readable really ships.
		$this->assertContains( 'assets/vendor/datastar/datastar-1.0.3.js.map', $this->shipped_files() );
	}

	public function test_every_filter_a_site_owner_can_use_is_documented(): void {
		// A hook that exists only in the source is not an escape hatch: whoever
		// needs it is reading readme.txt, not our PHP. Both of ours answer a
		// failure a site cannot otherwise get out of -- a second Datastar
		// runtime freezing the page, and a Content-Security-Policy nonce the
		// plugin has no way to invent.
		// The sweep goes over everything the ZIP carries, and not over a list of
		// directories: a filter added in a second block, or one directory deeper
		// than the two that exist today, would otherwise never be looked at and
		// the check would stay green while its guarantee stopped holding.
		//
		// Vendored code is swept too and cannot match: the pattern demands our
		// own prefix, which nothing upstream writes.
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );
		$hooks  = array();

		foreach ( $this->shipped_files( 'php' ) as $relative ) {
			$source = (string) file_get_contents( self::ROOT . '/' . $relative );

			if ( preg_match_all( '/apply_filters\(\s*\x27(ulcar_[a-z_]+)\x27/', $source, $matches ) ) {
				$hooks = array_merge( $hooks, $matches[1] );
			}
		}

		// Without this, a rename of every filter -- or a sweep that reads nothing
		// at all -- would leave the check green.
		$this->assertNotEmpty( $hooks, 'No filter was found: this check would be vacuous.' );

		foreach ( array_unique( $hooks ) as $hook ) {
			$this->assertStringContainsString(
				$hook,
				$readme,
				sprintf( '%s is a public filter and readme.txt never mentions it.', $hook )
			);
		}
	}

	/**
	 * Reads a .po or .pot into (context, id) => translation.
	 *
	 * @param string $path Absolute path.
	 * @return array<string, string> Keyed by "context\x04id".
	 */
	private function catalogue( string $path ): array {
		$entries = array();

		foreach ( explode( "\n\n", (string) file_get_contents( $path ) ) as $block ) {
			$context = null;
			$id      = null;
			$string  = null;

			foreach ( explode( "\n", $block ) as $line ) {
				if ( str_starts_with( $line, 'msgctxt "' ) ) {
					$context = substr( $line, 9, -1 );
				} elseif ( str_starts_with( $line, 'msgid "' ) ) {
					$id = substr( $line, 7, -1 );
				} elseif ( str_starts_with( $line, 'msgstr "' ) ) {
					$string = substr( $line, 8, -1 );
				}
			}

			if ( null !== $id && '' !== $id ) {
				$entries[ ( $context ?? '' ) . "\x04" . $id ] = (string) $string;
			}
		}

		return $entries;
	}

	public function test_no_translation_entry_was_lost_or_merged(): void {
		// A gettext entry is identified by the pair (context, text), never by
		// the text alone. "Ultralight Carousel" exists twice — once with
		// msgctxt "block title", once without — and a deduplication keyed on
		// the text merged them, which silently left the block titled in English
		// while everything around it was translated. Keywords kept working,
		// which made it look like the translation was fine.
		$pot = $this->catalogue( self::ROOT . '/languages/ultralight-carousel-via-sse.pot' );

		$this->assertNotEmpty( $pot, 'The .pot is empty: this check would be vacuous.' );

		foreach ( self::files( '/languages', 'po' ) as $translation ) {
			$po = $this->catalogue( $translation );

			$missing = array_keys( array_diff_key( $pot, $po ) );

			$this->assertSame(
				array(),
				$missing,
				sprintf(
					'%s is missing %d entry/entries the .pot declares, e.g. %s',
					basename( $translation ),
					count( $missing ),
					str_replace( "\x04", ' / ', (string) reset( $missing ) )
				)
			);
		}
	}

	public function test_the_licence_is_declared_and_present(): void {
		$this->assertSame(
			'GPL v2 or later',
			$this->header( 'ultralight-carousel-via-sse.php', 'License' )
		);
		$this->assertFileExists( self::ROOT . '/LICENSE.txt' );

		// Both bundled dependencies are MIT, which is GPL-compatible, and their
		// licences travel with them.
		$this->assertFileExists( self::ROOT . '/assets/vendor/datastar/LICENSE.md' );
		$this->assertFileExists( self::ROOT . '/includes/datastar-php/LICENSE.md' );
	}

	public function test_the_bundled_runtime_is_named_after_the_version_the_code_asks_for(): void {
		$assets = (string) file_get_contents( self::ROOT . '/includes/class-assets.php' );

		$this->assertSame( 1, preg_match( "/DATASTAR_VERSION = '([0-9.]+)'/", $assets, $version ) );
		$this->assertFileExists(
			self::ROOT . '/assets/vendor/datastar/datastar-' . $version[1] . '.js',
			'The constant names a file that is not there; the module would 404 in silence.'
		);
	}
}
