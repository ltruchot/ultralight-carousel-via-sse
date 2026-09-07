<?php
/**
 * Unit tests for the one setting the plugin has.
 *
 * @package UltralightCarouselViaSse
 */

declare(strict_types=1);

namespace ULCAR\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ULCAR\Settings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ULCAR\Settings
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, array_filter( (array) $args, static fn( $v ) => null !== $v ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider provide_submitted_values
	 */
	public function test_the_stored_interval_is_always_within_range( $submitted, float $expected ): void {
		$this->assertSame( $expected, Settings::sanitize( array( 'interval' => $submitted ) )['interval'] );
	}

	public static function provide_submitted_values(): array {
		return array(
			'valeur normale'     => array( 5, 5.0 ),
			'le plancher'        => array( 2.5, 2.5 ),
			'le plafond'         => array( 25, 25.0 ),
			'une demi-seconde'   => array( 7.5, 7.5 ),
			'sous le plancher'   => array( 1, 2.5 ),
			'zero'               => array( 0, 2.5 ),
			// Refuse, ne reflete pas : absint() ferait de -10 dix secondes.
			'negatif'            => array( -10, 2.5 ),
			'au-dessus'          => array( 3600, 25.0 ),
			// Pas un nombre : on revient au defaut livre, pas au plancher.
			'du texte'           => array( 'abc', 5.0 ),
			'du texte numerique' => array( '12', 12.0 ),
			// Arrondi au dixieme : une valeur postee a la main n'ecrit pas
			// quinze decimales dans un attribut que le navigateur doit lire.
			'trop de decimales'  => array( 7.98765, 8.0 ),
			'un tableau'         => array( array( 1, 2 ), 5.0 ),
			'null'               => array( null, 5.0 ),
		);
	}

	public function test_a_submission_shaped_like_nothing_expected_falls_back_to_the_defaults(): void {
		foreach ( array( 'not an array', array(), array( 'autre' => 9 ) ) as $nonsense ) {
			$this->assertSame( Settings::DEFAULTS, Settings::sanitize( $nonsense ) );
		}
	}

	public function test_the_sanitiser_never_returns_a_key_it_did_not_declare(): void {
		// A settings callback that let an extra key through would store it, and
		// whatever wrote it would be read back later as if the plugin had put
		// it there.
		$out = Settings::sanitize(
			array(
				'interval'   => 5,
				'transition' => 'fade',
				'evil'       => '<script>',
				'x'          => array(),
			)
		);

		$this->assertSame( array_keys( Settings::DEFAULTS ), array_keys( $out ) );
		$this->assertIsFloat( $out['interval'] );
		$this->assertIsString( $out['transition'] );
		$this->assertIsInt( $out['duration'] );
	}

	/**
	 * @dataProvider provide_stored_rows
	 */
	public function test_a_corrupt_stored_row_still_yields_a_usable_interval( $stored, float $expected ): void {
		// The option can have been written by an older version, by WP-CLI, or
		// by hand. Reading it through get_option() alone would put whatever it
		// holds straight into an HTML attribute and into a timer.
		Functions\when( 'get_option' )->justReturn( $stored );

		$this->assertSame( $expected, Settings::interval() );
	}

	public static function provide_stored_rows(): array {
		return array(
			'absente'        => array( array(), 5.0 ),
			'correcte'       => array( array( 'interval' => 9 ), 9.0 ),
			'chaine'         => array( array( 'interval' => '9' ), 9.0 ),
			'enorme'         => array( array( 'interval' => 999999 ), 25.0 ),
			'negative'       => array( array( 'interval' => -1 ), 2.5 ),
			// Une demi-seconde stockee par une version anterieure en entiers
			// reste lisible, et n'est plus tronquee.
			'flottante'      => array( array( 'interval' => 8.7 ), 8.7 ),
			'du texte'       => array( array( 'interval' => 'drop table' ), 5.0 ),
			'pas un tableau' => array( 'corrompu', 5.0 ),
			'cle inattendue' => array( array( 'autre' => 1 ), 5.0 ),
		);
	}

	/**
	 * @dataProvider provide_intervals_in_milliseconds
	 */
	public function test_the_cadence_attribute_gets_whole_milliseconds( $stored, int $expected ): void {
		// La duree vit dans le NOM de l'attribut : une valeur fractionnaire y
		// mettrait un point de plus la ou les points separent deja les
		// modificateurs.
		Functions\when( 'get_option' )->justReturn( array( 'interval' => $stored ) );

		$this->assertSame( $expected, Settings::interval_ms() );
	}

	public static function provide_intervals_in_milliseconds(): array {
		return array(
			'entier'           => array( 5, 5000 ),
			'demi-seconde'     => array( 2.5, 2500 ),
			'le plafond'       => array( 25, 25000 ),
			'sous le plancher' => array( 1, 2500 ),
			'du texte'         => array( 'abc', 5000 ),
		);
	}

	/**
	 * @dataProvider provide_submitted_transitions
	 */
	public function test_only_a_transition_this_version_can_play_is_stored( $submitted, string $expected ): void {
		$this->assertSame( $expected, Settings::sanitize( array( 'transition' => $submitted ) )['transition'] );
	}

	public static function provide_submitted_transitions(): array {
		return array(
			'fondu'             => array( 'fade', 'fade' ),
			'aucune'            => array( 'none', 'none' ),
			// Un nom inconnu atteindrait le balisage tel quel : la feuille de
			// style n'a pas de regle pour lui, et l'echange ne se ferait plus.
			'un nom invente'    => array( 'slide', 'fade' ),
			'une injection'     => array( 'fade;--x', 'fade' ),
			'la mauvaise casse' => array( 'FADE', 'fade' ),
			'un nombre'         => array( 1, 'fade' ),
			'un tableau'        => array( array( 'fade' ), 'fade' ),
			'null'              => array( null, 'fade' ),
		);
	}

	/**
	 * @dataProvider provide_stored_transitions
	 */
	public function test_a_corrupt_stored_transition_falls_back_to_the_default( $stored, string $expected ): void {
		Functions\when( 'get_option' )->justReturn( $stored );

		$this->assertSame( $expected, Settings::transition() );
	}

	public static function provide_stored_transitions(): array {
		return array(
			'absente'        => array( array(), 'fade' ),
			'aucune'         => array( array( 'transition' => 'none' ), 'none' ),
			'inconnue'       => array( array( 'transition' => 'zoom' ), 'fade' ),
			'pas un tableau' => array( 'corrompu', 'fade' ),
		);
	}

	public function test_the_default_transition_is_one_the_plugin_offers(): void {
		// The select is built from TRANSITIONS; a default outside it would show
		// no option selected and change on the first save.
		$this->assertContains( Settings::DEFAULTS['transition'], Settings::TRANSITIONS );
		$this->assertSame( array( 'fade', 'none' ), Settings::TRANSITIONS );
	}

	public function test_the_plugins_screen_points_at_the_settings_page(): void {
		// Without this the page exists and nothing links to it: the only way to
		// reach it is to already know it is there. Every other plugin on the
		// screen shows the link, so its absence reads as "this one has no
		// settings".
		Functions\when( 'admin_url' )->alias( static fn( $p ) => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );

		$links = Settings::add_settings_link( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links );

		// First, not last: that is where core and well-behaved plugins put it,
		// and where a hand goes looking.
		$this->assertStringContainsString( 'options-general.php?page=ulcar', $links[0] );
		$this->assertStringContainsString( '<a href=', $links[0] );

		// And nothing already there was dropped on the way.
		$this->assertContains( '<a href="#">Deactivate</a>', $links );
	}

	public function test_the_settings_link_survives_a_plugin_that_hands_it_nothing(): void {
		Functions\when( 'admin_url' )->alias( static fn( $p ) => '/wp-admin/' . $p );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );

		// Another plugin filtering this list can hand over anything at all.
		$this->assertCount( 1, Settings::add_settings_link( array() ) );
	}

	public function test_the_bounds_are_the_ones_the_form_advertises(): void {
		// The number input carries min and max; server-side clamping has to
		// agree with them, or the field promises something the server refuses.
		$this->assertSame( 2.5, Settings::MIN_INTERVAL );
		$this->assertSame( 25, Settings::MAX_INTERVAL );

		// Le fondu le plus long doit rester strictement sous l'intervalle le
		// plus court, sans quoi une image n'aurait aucun instant de repos et le
		// fondu suivant partirait avant la fin du precedent.
		$this->assertLessThan( Settings::MIN_INTERVAL * 1000, Settings::MAX_DURATION );
		$this->assertGreaterThanOrEqual( Settings::MIN_INTERVAL, Settings::DEFAULTS['interval'] );
		$this->assertLessThanOrEqual( Settings::MAX_INTERVAL, Settings::DEFAULTS['interval'] );

		$this->assertSame( 100, Settings::MIN_DURATION );
		$this->assertSame( 2000, Settings::MAX_DURATION );
		$this->assertGreaterThanOrEqual( Settings::MIN_DURATION, Settings::DEFAULTS['duration'] );
		$this->assertLessThanOrEqual( Settings::MAX_DURATION, Settings::DEFAULTS['duration'] );
	}

	/**
	 * @dataProvider provide_submitted_durations
	 */
	public function test_the_stored_duration_is_always_within_range( $submitted, int $expected ): void {
		$this->assertSame( $expected, Settings::sanitize( array( 'duration' => $submitted ) )['duration'] );
	}

	public static function provide_submitted_durations(): array {
		return array(
			'valeur normale'     => array( 800, 800 ),
			'le plancher'        => array( 100, 100 ),
			'le plafond'         => array( 2000, 2000 ),
			'sous le plancher'   => array( 40, 100 ),
			'zero'               => array( 0, 100 ),
			// Meme lecture que pour l'intervalle : on refuse, on ne reflete pas.
			'negatif'            => array( -400, 100 ),
			'au-dessus'          => array( 10000, 2000 ),
			// Pas un nombre : on revient au defaut livre, pas au plancher.
			'du texte'           => array( 'lentement', 1000 ),
			'du texte numerique' => array( '250', 250 ),
			'un flottant'        => array( 999.9, 999 ),
			'un tableau'         => array( array( 500 ), 1000 ),
			'null'               => array( null, 1000 ),
		);
	}

	/**
	 * @dataProvider provide_stored_durations
	 */
	public function test_a_corrupt_stored_duration_still_yields_a_usable_length( $stored, int $expected ): void {
		Functions\when( 'get_option' )->justReturn( $stored );

		$this->assertSame( $expected, Settings::duration() );
	}

	public static function provide_stored_durations(): array {
		return array(
			// La cle manque sur toute installation anterieure a 0.3.0 : c'est
			// le cas le plus frequent, pas un cas limite.
			'absente'        => array( array( 'interval' => 5 ), 1000 ),
			'correcte'       => array( array( 'duration' => 1500 ), 1500 ),
			'chaine'         => array( array( 'duration' => '1500' ), 1500 ),
			'enorme'         => array( array( 'duration' => 999999 ), 2000 ),
			'negative'       => array( array( 'duration' => -1 ), 100 ),
			'du texte'       => array( array( 'duration' => 'drop table' ), 1000 ),
			'pas un tableau' => array( 'corrompu', 1000 ),
		);
	}
}
