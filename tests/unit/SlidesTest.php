<?php
/**
 * Unit tests for the slide source of truth.
 *
 * @package UltralightCarouselViaSse
 */

declare(strict_types=1);

namespace ULCAR\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ULCAR\Slides;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ULCAR\Slides
 */
final class SlidesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_attr__' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'wp_salt' )->justReturn( 'a-salt-that-is-not-a-real-one' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Makes get_post() and get_post_mime_type() answer for a fixed catalogue.
	 *
	 * @param array<int, array{type?: string, status?: string, mime?: string}> $catalogue Keyed by id.
	 */
	private function given_attachments( array $catalogue ): void {
		Functions\when( 'get_post' )->alias(
			static function ( $id ) use ( $catalogue ) {
				if ( ! isset( $catalogue[ $id ] ) ) {
					return null;
				}
				$row               = $catalogue[ $id ];
				$post              = new \stdClass();
				$post->ID          = $id;
				$post->post_type   = $row['type'] ?? 'attachment';
				$post->post_status = $row['status'] ?? 'inherit';
				return $post;
			}
		);
		Functions\when( 'get_post_mime_type' )->alias(
			static fn( $post ) => $catalogue[ $post->ID ]['mime'] ?? 'image/jpeg'
		);
	}

	// -- sanitize_ids ------------------------------------------------------

	public function test_it_keeps_only_real_images_and_says_nothing_about_the_rest(): void {
		$this->given_attachments(
			array(
				10 => array(),                              // fine
				11 => array( 'mime' => 'application/pdf' ), // a PDF dropped into a gallery
				12 => array( 'type' => 'page' ),            // not an attachment at all
				13 => array( 'status' => 'trash' ),         // the attachment itself was trashed
				14 => array( 'mime' => 'video/mp4' ),
				15 => array(),                              // fine
				16 => array( 'status' => 'private' ),       // an attachment is never private itself...
				17 => array( 'status' => 'draft' ),         // ...nor a draft: only `inherit` is real
				18 => array( 'status' => 'future' ),
				19 => array( 'mime' => 'image/svg+xml' ),   // an image as far as the library is concerned
			)
		);

		// 99 does not exist; 0 and the string are not ids at all.
		$this->assertSame(
			array( 10, 15, 19 ),
			Slides::sanitize_ids( array( 10, 11, 12, 13, 14, 99, 0, 'nope', 15, 16, 17, 18, 19 ) )
		);
	}

	public function test_it_preserves_the_order_the_editor_chose(): void {
		$this->given_attachments(
			array(
				1 => array(),
				2 => array(),
				3 => array(),
			)
		);

		$this->assertSame( array( 3, 1, 2 ), Slides::sanitize_ids( array( 3, 1, 2 ) ) );
	}

	public function test_it_drops_duplicates_so_a_slide_cannot_appear_twice(): void {
		$this->given_attachments(
			array(
				7 => array(),
				8 => array(),
			)
		);

		$this->assertSame( array( 7, 8 ), Slides::sanitize_ids( array( 7, 8, 7, 7, 8 ) ) );
	}

	public function test_it_stops_at_the_cap_the_route_also_enforces(): void {
		$catalogue = array();
		for ( $id = 1; $id <= Slides::MAX_SLIDES + 20; $id++ ) {
			$catalogue[ $id ] = array();
		}
		$this->given_attachments( $catalogue );

		$kept = Slides::sanitize_ids( array_keys( $catalogue ) );

		// The route's regex allows MAX_SLIDES ids and no more. If this ever
		// disagreed, a legitimate carousel would be answered with a 400.
		$this->assertCount( Slides::MAX_SLIDES, $kept );
	}

	// -- sanitize_size -----------------------------------------------------

	public function test_it_only_ever_renders_a_size_it_declared(): void {
		foreach ( Slides::SIZES as $size ) {
			$this->assertSame( $size, Slides::sanitize_size( $size ) );
		}

		foreach ( array( '', 'evil', '../../etc/passwd', 'LARGE' ) as $bad ) {
			$this->assertSame( 'large', Slides::sanitize_size( $bad ) );
		}
	}

	// -- dom_id and signal_key --------------------------------------------

	public function test_the_dom_id_matches_what_the_route_will_accept(): void {
		// This is a cross-file invariant. The endpoint validates `target`
		// against exactly this pattern before interpolating it into a CSS
		// selector; a generator that drifted from it would make every carousel
		// answer 400, or worse, open the selector up.
		$this->assertMatchesRegularExpression(
			'/^ulcar-[a-f0-9]{12}$/',
			Slides::dom_id( array( 1, 2, 3 ), 'large', 0 )
		);
	}

	public function test_the_dom_id_is_the_same_for_the_same_carousel(): void {
		$first  = Slides::dom_id( array( 4, 5 ), 'medium', 2 );
		$second = Slides::dom_id( array( 4, 5 ), 'medium', 2 );

		// Not wp_unique_id(): a page assembled from partly cached fragments
		// must produce the same id, because the id is inside the HMAC.
		$this->assertSame( $first, $second );
	}

	/**
	 * @dataProvider provide_different_carousels
	 */
	public function test_different_carousels_get_different_dom_ids( array $ids, string $size, int $instance ): void {
		$this->assertNotSame(
			Slides::dom_id( array( 4, 5 ), 'medium', 2 ),
			Slides::dom_id( $ids, $size, $instance )
		);
	}

	public static function provide_different_carousels(): array {
		return array(
			'autres images'  => array( array( 4, 6 ), 'medium', 2 ),
			'ordre inverse'  => array( array( 5, 4 ), 'medium', 2 ),
			'autre taille'   => array( array( 4, 5 ), 'large', 2 ),
			'autre instance' => array( array( 4, 5 ), 'medium', 3 ),
		);
	}

	public function test_the_signal_path_is_a_valid_javascript_identifier_path(): void {
		// A hex hash can start with a digit, and `$ulcar.0a1b2c.view` is a syntax
		// error inside a Datastar expression -- one that fails silently, since
		// an expression that will not compile is simply ignored.
		for ( $instance = 0; $instance < 200; $instance++ ) {
			$signal = Slides::signal_key( Slides::dom_id( array( 1 ), 'large', $instance ) );
			$this->assertMatchesRegularExpression( '/^ulcar\.[A-Za-z_$][A-Za-z0-9_$]*$/', $signal );
		}
	}

	// -- the token ---------------------------------------------------------

	public function test_a_token_this_site_issued_is_accepted(): void {
		$token = Slides::token( '1,2,3', 'large', 'ulcar-abcdef012345' );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
		$this->assertTrue( Slides::verify_token( '1,2,3', 'large', 'ulcar-abcdef012345', $token ) );
	}

	/**
	 * @dataProvider provide_tampered_requests
	 */
	public function test_any_tampering_is_rejected( string $ids, string $size, string $target ): void {
		// Without this, the route is an enumeration oracle over the whole media
		// library -- drafts and unattached uploads included.
		$token = Slides::token( '1,2,3', 'large', 'ulcar-abcdef012345' );

		$this->assertFalse( Slides::verify_token( $ids, $size, $target, $token ) );
	}

	public static function provide_tampered_requests(): array {
		return array(
			'un id ajoute'    => array( '1,2,3,4', 'large', 'ulcar-abcdef012345' ),
			'un id retire'    => array( '1,2', 'large', 'ulcar-abcdef012345' ),
			'ordre change'    => array( '3,2,1', 'large', 'ulcar-abcdef012345' ),
			'taille changee'  => array( '1,2,3', 'full', 'ulcar-abcdef012345' ),
			'cible changee'   => array( '1,2,3', 'large', 'ulcar-000000000000' ),
			'separateur ruse' => array( '1,2|large|ulcar-abcdef012345,3', 'large', 'ulcar-abcdef012345' ),
		);
	}

	public function test_an_empty_token_is_rejected(): void {
		$this->assertFalse( Slides::verify_token( '1', 'large', 'ulcar-abcdef012345', '' ) );
	}

	public function test_a_token_from_another_site_of_the_network_is_rejected(): void {
		// Every site of a multisite network shares the secret wp_salt() derives
		// from, so the blog id has to be in the message or a token minted on
		// one site would open the same ids everywhere.
		$token = Slides::token( '1,2,3', 'large', 'ulcar-abcdef012345' );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );

		$this->assertFalse( Slides::verify_token( '1,2,3', 'large', 'ulcar-abcdef012345', $token ) );
	}

	public function test_a_token_depends_on_the_secret_and_not_only_on_the_message(): void {
		$token = Slides::token( '1,2,3', 'large', 'ulcar-abcdef012345' );

		Functions\when( 'wp_salt' )->justReturn( 'another-secret' );

		$this->assertFalse( Slides::verify_token( '1,2,3', 'large', 'ulcar-abcdef012345', $token ) );
	}

	// -- rendering ---------------------------------------------------------

	/**
	 * Makes wp_get_attachment_image() report the attributes it was handed.
	 */
	private function given_image_renderer(): void {
		Functions\when( 'wp_get_attachment_image' )->alias(
			static function ( $id, $size, $icon, $attrs ) {
				$pairs = '';
				foreach ( $attrs as $name => $value ) {
					$pairs .= sprintf( ' %s="%s"', $name, $value );
				}
				return sprintf( '<img data-id="%d" data-size="%s"%s />', $id, $size, $pairs );
			}
		);
	}

	public function test_the_first_slide_is_protected_from_deferred_loading(): void {
		$this->given_image_renderer();

		$html = Slides::render_slide( 42, 'large', 0, 3, 'ulcar.kabc' );

		// It is almost always the LCP element. A lazy-loading plugin that
		// deferred it would undo the entire point of the plugin.
		$this->assertStringContainsString( 'loading="eager"', $html );
		$this->assertStringContainsString( 'fetchpriority="high"', $html );
		$this->assertStringContainsString( 'skip-lazy', $html );
		$this->assertStringContainsString( 'no-lazyload', $html );
	}

	public function test_later_slides_are_not_given_that_treatment(): void {
		$this->given_image_renderer();

		$html = Slides::render_slide( 42, 'large', 2, 3, 'ulcar.kabc' );

		$this->assertStringNotContainsString( 'fetchpriority', $html );
		$this->assertStringNotContainsString( 'skip-lazy', $html );
	}

	public function test_only_the_first_slide_starts_visible(): void {
		$this->given_image_renderer();

		$this->assertStringNotContainsString( ' hidden', Slides::render_slide( 1, 'large', 0, 3, 'ulcar.kabc' ) );
		$this->assertStringContainsString( ' hidden', Slides::render_slide( 2, 'large', 1, 3, 'ulcar.kabc' ) );
	}

	public function test_a_hidden_slide_is_hidden_by_the_attribute_not_by_opacity(): void {
		$this->given_image_renderer();

		$html = Slides::render_slide( 2, 'large', 1, 3, 'ulcar.kabc' );

		// An opacity-0 slide stays focusable and stays in the accessibility
		// tree: a screen reader would read every slide and the tab order would
		// wander into what nobody can see.
		$this->assertStringContainsString( 'data-attr:hidden="$ulcar.kabc.view !== 1"', $html );
		$this->assertStringNotContainsString( 'opacity', $html );
	}

	public function test_a_static_render_carries_no_behaviour_at_all(): void {
		$this->given_image_renderer();

		$html = Slides::render_slide( 2, 'large', 1, 3 );

		// This is what the editor preview and a single-image carousel get.
		$this->assertStringNotContainsString( 'data-attr', $html );
		$this->assertStringNotContainsString( ' hidden', $html );
	}

	public function test_slides_are_numbered_for_a_screen_reader(): void {
		$this->given_image_renderer();

		$this->assertStringContainsString( 'aria-label="1 of 7"', Slides::render_slide( 1, 'large', 0, 7 ) );
		$this->assertStringContainsString( 'aria-label="7 of 7"', Slides::render_slide( 1, 'large', 6, 7 ) );
	}

	public function test_an_image_that_renders_to_nothing_produces_no_empty_slide(): void {
		Functions\when( 'wp_get_attachment_image' )->justReturn( '' );

		// Otherwise the carousel would step onto a blank box for five seconds
		// with nothing on screen and no error anywhere.
		$this->assertSame( '', Slides::render_slide( 42, 'large', 0, 3 ) );
	}

	public function test_render_slides_can_start_after_the_first(): void {
		$this->given_image_renderer();

		$html = Slides::render_slides( array( 10, 20, 30 ), 'large', 1, 'ulcar.kabc' );

		$this->assertStringNotContainsString( 'data-id="10"', $html );
		$this->assertStringContainsString( 'data-id="20"', $html );
		$this->assertStringContainsString( 'data-id="30"', $html );
		$this->assertSame( 2, substr_count( $html, 'class="ulcar-slide"' ) );
	}
}
