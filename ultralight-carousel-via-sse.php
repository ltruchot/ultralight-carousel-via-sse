<?php
/**
 * Plugin Name:       Ultralight Carousel via SSE
 * Plugin URI:        https://github.com/ltruchot/ultralight-carousel-via-sse
 * Description:       A carousel that streams its slides over Server-Sent Events: the page ships one image, the first paint stays fast, and without JavaScript that image still shows.
 * Version:           0.6.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Loïc Truchot
 * Author URI:        https://github.com/ltruchot
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ultralight-carousel-via-sse
 * Domain Path:       /languages
 *
 * @package UltralightCarouselViaSse
 */

/*
 * THIS FILE MUST PARSE ON ANCIENT PHP.
 *
 * The bundled Datastar SDK uses enums, which are a PARSE error below PHP 8.1 --
 * not a runtime error. A file containing one kills the process at `require`,
 * before any version check can run. So this entry point stays deliberately
 * plain: no return types, no union types, no arrow functions, no enums. It
 * checks the version first, and only then loads anything else.
 */

defined( 'ABSPATH' ) || exit;

define( 'ULCAR_VERSION', '0.6.0' );
define( 'ULCAR_FILE', __FILE__ );
define( 'ULCAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ULCAR_URL', plugin_dir_url( __FILE__ ) );

/** Minimum versions, mirrored in the plugin header and in readme.txt. */
define( 'ULCAR_MIN_PHP', '8.1' );
define( 'ULCAR_MIN_WP', '6.5' );

/**
 * Refuses activation on an unsupported stack.
 *
 * WordPress honours the `Requires PHP` header since 5.1, but a site that
 * downgrades PHP after activation never goes through activation again -- hence
 * the runtime guard in ulcar_boot() as well.
 *
 * wp_die() is the whole refusal. An activation hook runs BEFORE the plugin is
 * written to the active list, so dying here leaves it inactive; there is
 * nothing to deactivate.
 */
function ulcar_activate() {
	if ( version_compare( PHP_VERSION, ULCAR_MIN_PHP, '<' )
		|| version_compare( get_bloginfo( 'version' ), ULCAR_MIN_WP, '<' ) ) {
		wp_die(
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: required WordPress version. */
					__( 'Ultralight Carousel via SSE requires PHP %1$s and WordPress %2$s or later.', 'ultralight-carousel-via-sse' ),
					ULCAR_MIN_PHP,
					ULCAR_MIN_WP
				)
			),
			'',
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'ulcar_activate' );

/**
 * Loads the plugin, or stays silent on an unsupported stack.
 *
 * Silence is deliberate: an admin notice here would need a translated string
 * evaluated before `init`, which trips _load_textdomain_just_in_time on
 * WordPress 6.7 and later. The activation guard above is where the user is
 * told; this one only has to avoid a fatal.
 */
function ulcar_boot() {
	if ( version_compare( PHP_VERSION, ULCAR_MIN_PHP, '<' ) ) {
		return;
	}

	require_once ULCAR_PATH . 'includes/class-settings.php';
	require_once ULCAR_PATH . 'includes/class-slides.php';
	require_once ULCAR_PATH . 'includes/class-assets.php';
	require_once ULCAR_PATH . 'includes/class-csp.php';
	require_once ULCAR_PATH . 'includes/class-block.php';
	require_once ULCAR_PATH . 'includes/class-sse-endpoint.php';

	ULCAR\Settings::init();
	ULCAR\Assets::init();
	ULCAR\Csp::init();
	ULCAR\Block::init();
	ULCAR\Sse_Endpoint::init();
}
ulcar_boot();
