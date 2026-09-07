<?php
/**
 * Runs uninstall.php for real, on a single site and on a network.
 *
 * The file is executed, not read: an option name that is only grepped for
 * proves the two ends agree on a string, not that the row goes away.
 *
 * @package UltralightCarouselViaSse
 */

declare(strict_types=1);

namespace ULCAR\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class UninstallTest extends TestCase {

	private const UNINSTALL = __DIR__ . '/../../uninstall.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// Each test includes the file, and the file declares a constant, so each
	// needs a process of its own.

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_it_refuses_to_run_outside_an_uninstall(): void {
		Functions\expect( 'delete_option' )->never();

		// Without WP_UNINSTALL_PLUGIN the guard exits, so it is asserted from a
		// child process rather than from here.
		$php = sprintf(
			'define( "ABSPATH", "/" ); function delete_option() { echo "DELETED"; } include %s; echo "AFTER";',
			var_export( self::UNINSTALL, true )
		);
		// A test may shell out; the plugin never does, and SecurityTest holds it to that.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( sprintf( '%s -r %s', escapeshellarg( PHP_BINARY ), escapeshellarg( $php ) ), $output );

		$this->assertSame( array(), $output, 'uninstall.php ran without WP_UNINSTALL_PLUGIN.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_a_single_site_it_deletes_the_one_option(): void {
		define( 'WP_UNINSTALL_PLUGIN', true );

		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'delete_option' )->once()->with( 'ulcar_settings' );
		Functions\expect( 'delete_site_option' )->never();
		Functions\expect( 'get_sites' )->never();

		include self::UNINSTALL;

		// The file names the option once, in a constant that uninstall.php has
		// to repeat by hand because the plugin is not loaded when it runs.
		$this->assertSame( 'ulcar_settings', ULCAR_UNINSTALL_OPTION );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_a_network_it_visits_every_site(): void {
		define( 'WP_UNINSTALL_PLUGIN', true );

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'wp_is_large_network' )->justReturn( false );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );
		Functions\expect( 'switch_to_blog' )->times( 3 );
		Functions\expect( 'restore_current_blog' )->times( 3 );
		// Once for the current site, once per site of the network.
		Functions\expect( 'delete_option' )->times( 4 )->with( 'ulcar_settings' );
		Functions\expect( 'delete_site_option' )->once()->with( 'ulcar_settings' );

		include self::UNINSTALL;

		// The file names the option once, in a constant that uninstall.php has
		// to repeat by hand because the plugin is not loaded when it runs.
		$this->assertSame( 'ulcar_settings', ULCAR_UNINSTALL_OPTION );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_on_a_large_network_it_does_not_walk_the_sites(): void {
		define( 'WP_UNINSTALL_PLUGIN', true );

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'wp_is_large_network' )->justReturn( true );
		Functions\expect( 'get_sites' )->never();
		Functions\expect( 'switch_to_blog' )->never();
		Functions\expect( 'delete_option' )->once()->with( 'ulcar_settings' );
		Functions\expect( 'delete_site_option' )->once()->with( 'ulcar_settings' );

		include self::UNINSTALL;

		// The file names the option once, in a constant that uninstall.php has
		// to repeat by hand because the plugin is not loaded when it runs.
		$this->assertSame( 'ulcar_settings', ULCAR_UNINSTALL_OPTION );
	}
}
