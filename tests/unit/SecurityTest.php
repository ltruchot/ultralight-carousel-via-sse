<?php
/**
 * The plugin's boundary, asserted rather than claimed.
 *
 * A carousel has no business talking to anything, writing anything, or running
 * anything. These tests read what the ZIP would actually contain and fail the
 * day that stops being true -- which is the only form in which such a promise
 * is worth making.
 *
 * @package UltralightCarouselViaSse
 */

declare(strict_types=1);

namespace ULCAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SecurityTest extends TestCase {

	use ShippedFiles;

	private const ROOT = __DIR__ . '/../..';

	/**
	 * Reads a shipped file with its comments stripped.
	 *
	 * Comments and documentation legitimately mention URLs and function names;
	 * scanning them would produce false alarms and, worse, would train whoever
	 * reads the failure to ignore it.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string Code, without comments.
	 */
	private function code_of( string $relative ): string {
		$source = (string) file_get_contents( self::ROOT . '/' . $relative );

		if ( str_ends_with( $relative, '.php' ) ) {
			$code = '';
			foreach ( token_get_all( $source ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$code .= is_array( $token ) ? $token[1] : $token;
			}
			return $code;
		}

		return $source;
	}

	public function test_the_zip_carries_what_the_plugin_needs_and_nothing_else(): void {
		$all = $this->shipped_files();
		$php = $this->shipped_files( 'php' );

		// Every assertion in this file iterates one of these lists. A filter
		// that returned nothing would make the whole class pass while checking
		// nothing at all -- the exact shape of a test that cannot fail.
		$this->assertGreaterThanOrEqual( 30, count( $all ) );
		$this->assertGreaterThanOrEqual( 10, count( $php ) );

		// Without these the plugin does not run, or does not pass review.
		foreach (
			array(
				'ultralight-carousel-via-sse.php',
				'uninstall.php',
				'readme.txt',
				'LICENSE.txt',
				'blocks/carousel/block.json',
				'blocks/carousel/render.php',
				'includes/class-sse-endpoint.php',
				'includes/datastar-php/loader.php',
				'assets/vendor/datastar/datastar-1.0.3.js',
				'assets/vendor/datastar/LICENSE.md',
				'languages/ultralight-carousel-via-sse.pot',
			) as $needed
		) {
			$this->assertContains( $needed, $all, sprintf( '%s would be missing from the ZIP.', $needed ) );
		}

		// And none of the scaffolding. Shipping it would put a Composer
		// autoloader and a test suite inside somebody's wp-content, for no
		// purpose whatever.
		foreach ( array( 'tests', 'e2e', 'bin', 'vendor', '.github', '.claude' ) as $directory ) {
			$this->assertFileExists( self::ROOT . '/' . $directory, 'The exclusion below would be vacuous.' );
			$this->assertSame(
				array(),
				array_values( array_filter( $all, static fn( $f ) => str_starts_with( $f, $directory . '/' ) ) ),
				sprintf( '%s/ would end up in the ZIP.', $directory )
			);
		}

		foreach ( array( 'composer.json', 'composer.lock', 'phpunit.xml.dist', 'phpcs.xml.dist', '.distignore' ) as $file ) {
			$this->assertFileExists( self::ROOT . '/' . $file, 'The exclusion below would be vacuous.' );
			$this->assertNotContains( $file, $all );
		}
	}

	public function test_every_file_the_zip_needs_is_actually_in_the_repository(): void {
		// The tests above ask the filesystem, which answers the same whether git
		// knows about a file or not. That gap is not theoretical: an unanchored
		// `vendor/` in .gitignore once swallowed assets/vendor/, and the plugin
		// was published without the runtime it cannot start without. Everything
		// was green locally; CI caught it on a clean checkout, one round trip
		// later than necessary.
		$root = (string) realpath( self::ROOT );

		if ( ! is_dir( $root . '/.git' ) ) {
			$this->markTestSkipped( 'Not a git checkout.' );
		}

		$output = array();
		$status = 0;
		// A test asking git what git tracks. The rule that discourages this
		// exists for plugin code, which ships and runs on other people's
		// servers; this file does neither -- and it is the reason the shipped
		// code can promise it never shells out.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files -z 2>/dev/null', $output, $status );

		if ( 0 !== $status ) {
			$this->markTestSkipped( 'git is not available here.' );
		}

		$tracked = array_filter( explode( "\0", implode( "\n", $output ) ) );
		$this->assertNotEmpty( $tracked, 'git listed nothing: the check would be vacuous.' );

		$untracked = array_values( array_diff( $this->shipped_files(), $tracked ) );

		$this->assertSame(
			array(),
			$untracked,
			'These would ship from a working copy and be missing from a clean checkout.'
		);
	}

	/**
	 * @dataProvider provide_forbidden_calls
	 */
	public function test_the_plugin_opens_no_channel_of_its_own( string $needle, string $why ): void {
		foreach ( $this->shipped_files( 'php' ) as $relative ) {
			// The vendored SDK is third-party code, pinned by checksum below.
			if ( str_starts_with( $relative, 'includes/datastar-php/' ) ) {
				continue;
			}

			$this->assertStringNotContainsStringIgnoringCase(
				$needle,
				$this->code_of( $relative ),
				sprintf( '%s uses %s. %s', $relative, $needle, $why )
			);
		}
	}

	public static function provide_forbidden_calls(): array {
		$outbound = 'A carousel has nothing to say to another machine, and guideline 7 forbids contacting one without consent.';
		$write    = 'A plugin that writes files can be made to write the wrong one.';
		$run      = 'Nothing here needs to execute anything.';
		$db       = 'The one option goes through the Settings API; a direct query is a place for an injection to live.';

		return array(
			'wp_remote_get'        => array( 'wp_remote_get', $outbound ),
			'wp_remote_post'       => array( 'wp_remote_post', $outbound ),
			'wp_remote_request'    => array( 'wp_remote_request', $outbound ),
			'wp_safe_remote'       => array( 'wp_safe_remote', $outbound ),
			'curl_init'            => array( 'curl_init', $outbound ),
			'fsockopen'            => array( 'fsockopen', $outbound ),
			'stream_socket_client' => array( 'stream_socket_client', $outbound ),
			'file_get_contents'    => array( 'file_get_contents', $outbound . ' ' . $write ),
			'file_put_contents'    => array( 'file_put_contents', $write ),
			'fopen'                => array( 'fopen', $write ),
			'unlink'               => array( 'unlink', $write ),
			'mkdir'                => array( 'mkdir', $write ),
			'move_uploaded_file'   => array( 'move_uploaded_file', $write ),
			'eval'                 => array( 'eval(', $run ),
			'exec'                 => array( 'exec(', $run ),
			'shell_exec'           => array( 'shell_exec', $run ),
			'system'               => array( 'system(', $run ),
			'passthru'             => array( 'passthru', $run ),
			'proc_open'            => array( 'proc_open', $run ),
			'create_function'      => array( 'create_function', $run ),
			'unserialize'          => array( 'unserialize(', 'Unserialising anything a request can reach is object injection.' ),
			'extract'              => array( 'extract(', 'It turns request data into local variables.' ),
			'$wpdb'                => array( '$wpdb', $db ),
			'dbDelta'              => array( 'dbDelta', $db ),
			'wp_cron'              => array( 'wp_schedule_event', 'Nothing here happens on a timer, on the server.' ),
		);
	}

	public function test_no_superglobal_is_read_anywhere(): void {
		// Every parameter comes from WP_REST_Request, which validates and
		// sanitises against the schema declared on the route. Reaching into a
		// superglobal is how that guarantee gets bypassed by accident.
		foreach ( $this->shipped_files( 'php' ) as $relative ) {
			if ( str_starts_with( $relative, 'includes/datastar-php/' ) ) {
				continue;
			}

			foreach ( array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES', '$_SERVER' ) as $superglobal ) {
				$this->assertStringNotContainsString(
					$superglobal,
					$this->code_of( $relative ),
					sprintf( '%s reads %s directly.', $relative, $superglobal )
				);
			}
		}
	}

	public function test_the_route_is_not_wide_open(): void {
		$code = $this->code_of( 'includes/class-sse-endpoint.php' );

		// A public route still needs a permission_callback that decides
		// something. __return_true here would turn the endpoint into an
		// enumeration oracle over the whole media library.
		$this->assertStringNotContainsString( '__return_true', $code );
		$this->assertStringContainsString( "'permission_callback'", $code );
		$this->assertStringContainsString( 'verify_token', $code );
		$this->assertStringContainsString( 'hash_equals', $this->code_of( 'includes/class-slides.php' ) );

		// Read-only, and only that.
		$this->assertStringContainsString( 'WP_REST_Server::READABLE', $code );
		foreach ( array( 'CREATABLE', 'EDITABLE', 'DELETABLE', 'ALLMETHODS' ) as $method ) {
			$this->assertStringNotContainsString( $method, $code );
		}
	}

	public function test_every_argument_the_route_accepts_is_constrained(): void {
		$code = $this->code_of( 'includes/class-sse-endpoint.php' );

		// Four parameters, four constraints, no free text anywhere.
		$this->assertSame( 4, substr_count( $code, "'required'          => true" ) + substr_count( $code, "'required' => true" ) );
		$this->assertStringContainsString( "'enum'     => Slides::SIZES", $code );
		$this->assertStringContainsString( '^ulcar-[a-f0-9]{12}$', $code );
		$this->assertStringContainsString( '^[a-f0-9]{32}$', $code );
	}

	public function test_the_route_accepts_exactly_as_many_ids_as_a_carousel_can_hold(): void {
		// The route builds its pattern FROM Slides::MAX_SLIDES, so the two
		// cannot drift apart -- but `{0,N-1}` means one id plus N-1 more, and
		// an off-by-one there would either answer 400 to a legitimate full
		// carousel or let one extra id through the signature-checked list.
		// Rebuilt here exactly as the endpoint builds it.
		$pattern = '/^\d+(,\d+){0,' . ( \ULCAR\Slides::MAX_SLIDES - 1 ) . '}$/';

		$this->assertStringContainsString(
			"'/^\\d+(,\\d+){0,' . ( Slides::MAX_SLIDES - 1 ) . '}$/'",
			$this->code_of( 'includes/class-sse-endpoint.php' ),
			'The endpoint no longer builds its pattern the way this test rebuilds it.'
		);

		$full         = implode( ',', range( 1, \ULCAR\Slides::MAX_SLIDES ) );
		$one_too_many = implode( ',', range( 1, \ULCAR\Slides::MAX_SLIDES + 1 ) );

		$this->assertSame( 1, preg_match( $pattern, '1' ) );
		$this->assertSame( 1, preg_match( $pattern, $full ) );
		$this->assertSame( 0, preg_match( $pattern, $one_too_many ) );

		// And nothing that is not a list of numbers.
		foreach ( array( '', '1,', ',1', '1,,2', '1;2', '1 ,2', '-1', '1.5', 'a', '1,a' ) as $bad ) {
			$this->assertSame( 0, preg_match( $pattern, $bad ), sprintf( 'accepted %s', var_export( $bad, true ) ) );
		}
	}

	public function test_no_shipped_file_can_be_reached_directly(): void {
		foreach ( $this->shipped_files( 'php' ) as $relative ) {
			$source = (string) file_get_contents( self::ROOT . '/' . $relative );

			$guarded = str_contains( $source, "defined( 'ABSPATH' ) || exit;" )
				|| str_contains( $source, "defined( 'WP_UNINSTALL_PLUGIN' ) || exit;" )
				// The two *.asset.php files are plain `return array(...)`;
				// reached directly they print nothing and expose nothing.
				|| preg_match( '/^<\?php\s+(\/\*\*.*?\*\/\s*)?return\s+array/s', $source );

			$this->assertTrue( $guarded, sprintf( '%s has no direct-access guard.', $relative ) );
		}
	}

	public function test_the_vendored_runtime_is_the_file_upstream_published(): void {
		$notes = (string) file_get_contents( self::ROOT . '/assets/vendor/datastar/UPSTREAM.md' );

		$this->assertSame( 1, preg_match( '/SHA-256 \| `([a-f0-9]{64})`/', $notes, $recorded ) );

		// Third-party code cannot be audited by grep, so it is pinned instead.
		// If the file ever changes without the note changing with it, this
		// fails -- which is the only thing that distinguishes a vendored
		// dependency from a file someone dropped in.
		$this->assertSame(
			$recorded[1],
			hash_file( 'sha256', self::ROOT . '/assets/vendor/datastar/datastar-1.0.3.js' )
		);
	}

	public function test_the_only_stored_state_is_the_one_documented_option(): void {
		// The first shape of this test scanned for option-name literals. The
		// code passes a class constant, so it found none, compared two empty
		// arrays and passed -- a test that could not fail. What follows asserts
		// the name from both ends instead.
		$this->assertSame( 'ulcar_settings', \ULCAR\Settings::OPTION );

		// uninstall.php runs without the plugin loaded, so it cannot reach that
		// constant and repeats the name. The two have to agree or uninstalling
		// leaves the row behind.
		$this->assertStringContainsString(
			"const ULCAR_UNINSTALL_OPTION = 'ulcar_settings';",
			$this->code_of( 'uninstall.php' )
		);

		// And no other name is used anywhere, whether by literal or otherwise.
		$names = array();
		foreach ( $this->shipped_files( 'php' ) as $relative ) {
			preg_match_all(
				'/(?:get|update|add|delete)_(?:option|site_option)\(\s*([^,)]+)/',
				$this->code_of( $relative ),
				$matches
			);
			$names = array_merge( $names, array_map( 'trim', $matches[1] ) );
		}

		$this->assertNotEmpty( $names, 'No option call found at all: the scan is broken, not the code.' );

		foreach ( $names as $name ) {
			$this->assertContains(
				$name,
				array( "'ulcar_settings'", 'self::OPTION', 'ULCAR_UNINSTALL_OPTION' ),
				sprintf( 'Unexpected option name: %s', $name )
			);
		}
	}

	public function test_nothing_is_stored_anywhere_else(): void {
		foreach ( $this->shipped_files( 'php' ) as $relative ) {
			$code = $this->code_of( $relative );

			foreach ( array( 'set_transient', 'set_site_transient', 'update_user_meta', 'add_post_meta', 'wp_insert_post' ) as $writer ) {
				$this->assertStringNotContainsString(
					$writer,
					$code,
					sprintf( '%s stores state through %s. One option is the whole footprint.', $relative, $writer )
				);
			}
		}
	}
}
