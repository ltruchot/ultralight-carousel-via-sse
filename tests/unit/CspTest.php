<?php
/**
 * Unit tests for the CSP nonce bridge.
 *
 * @package UltralightCarouselViaSse
 */

declare(strict_types=1);

namespace ULCAR\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ULCAR\Csp;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ULCAR\Csp
 */
final class CspTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'is_admin' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $nonce Value the site's filter returns.
	 */
	private function given_site_nonce( string $nonce ): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'ulcar_csp_nonce' === $hook ? $nonce : $value
		);
	}

	public function test_nothing_is_added_when_the_site_has_no_csp(): void {
		// The overwhelming majority of sites. An attribute added here would be
		// noise at best, and a nonce this plugin invented would be a lie.
		$this->given_site_nonce( '' );

		$this->assertSame( 'lang="fr-FR"', Csp::add_nonce( 'lang="fr-FR"' ) );
	}

	public function test_the_nonce_is_handed_to_datastar_when_the_site_supplies_one(): void {
		$this->given_site_nonce( 'r4nd0mV4lu3' );

		$this->assertSame(
			'lang="fr-FR" data-nonce="r4nd0mV4lu3"',
			Csp::add_nonce( 'lang="fr-FR"' )
		);
	}

	public function test_a_hostile_nonce_cannot_break_out_of_the_attribute(): void {
		// The value comes from a filter, so it comes from other code -- which
		// may be less careful than it should be.
		$this->given_site_nonce( '" onload="alert(1)' );

		$out = Csp::add_nonce( 'lang="fr-FR"' );

		// The quotes were neutralised, so the value cannot close the attribute
		// and start another one. Counting them is the invariant that matters:
		// four raw quotes means two delimiter pairs and nothing else.
		$this->assertSame( 'lang="fr-FR" data-nonce="&quot; onload=&quot;alert(1)"', $out );
		$this->assertSame( 4, substr_count( $out, '"' ) );
	}

	public function test_a_second_nonce_is_never_added(): void {
		// Another plugin may already ship Datastar. Two values on one attribute
		// would leave the browser using whichever it parsed first.
		$this->given_site_nonce( 'ours' );

		$already = 'lang="fr-FR" data-nonce="theirs"';

		$this->assertSame( $already, Csp::add_nonce( $already ) );
	}

	public function test_the_admin_is_left_alone(): void {
		// Datastar is a front-end module; the block editor never loads it.
		$this->given_site_nonce( 'r4nd0m' );
		Functions\when( 'is_admin' )->justReturn( true );

		$this->assertSame( 'lang="fr-FR"', Csp::add_nonce( 'lang="fr-FR"' ) );
	}
}
