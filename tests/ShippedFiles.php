<?php
/**
 * Lists what the plugin ZIP would carry.
 *
 * Shared rather than copied. The first version of this logic lived in one test
 * file and was duplicated into another; the copy got the anchoring wrong and
 * reported that the Datastar source map would not ship, when it does. Logic
 * that has to be right in two places is wrong in one of them soon enough.
 *
 * @package UltralightCarouselViaSse
 */

declare(strict_types=1);

namespace ULCAR\Tests;

/**
 * Reads .distignore the way rsync does.
 */
trait ShippedFiles {

	/**
	 * Lists the files the plugin ZIP would carry.
	 *
	 * The matching follows rsync's rules, because rsync is what builds the ZIP:
	 * a pattern with a leading slash is anchored at the root, one WITHOUT
	 * matches a component at any depth. That distinction is not academic — an
	 * unanchored `vendor` takes `assets/vendor/` with it, and the plugin ships
	 * without the runtime it cannot start without.
	 *
	 * @param string $extension Extension to keep, or '*' for every file.
	 * @return array<string> Paths relative to the plugin root, sorted.
	 */
	protected function shipped_files( string $extension = '*' ): array {
		$root     = (string) realpath( self::ROOT );
		$anchored = array();
		$floating = array();

		foreach ( file( $root . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			if ( str_starts_with( $line, '/' ) ) {
				$anchored[] = ltrim( $line, '/' );
			} else {
				$floating[] = $line;
			}
		}

		$excluded = static function ( string $relative ) use ( $anchored, $floating ): bool {
			foreach ( $anchored as $pattern ) {
				// fnmatch() for the one anchored wildcard, `/*.zip`: a ZIP built
				// at the root must not ship inside the next one. FNM_PATHNAME
				// keeps `*` from crossing a slash, as it does not in rsync.
				if ( $relative === $pattern
					|| str_starts_with( $relative, $pattern . '/' )
					|| fnmatch( $pattern, $relative, FNM_PATHNAME ) ) {
					return true;
				}
			}

			foreach ( $floating as $pattern ) {
				if ( in_array( $pattern, explode( '/', $relative ), true ) ) {
					return true;
				}
			}

			return false;
		};

		// Prune at descent, not after. vendor/ and .git/ hold tens of thousands
		// of files; walking them and discarding the results afterwards runs the
		// process out of memory before it reaches a single assertion.
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $file ) use ( $root, $excluded ): bool {
					$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
					return ! $excluded( $relative );
				}
			)
		);

		$found = array();

		foreach ( $iterator as $file ) {
			if ( '*' === $extension || $extension === $file->getExtension() ) {
				$found[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}

		sort( $found );

		return $found;
	}
}
