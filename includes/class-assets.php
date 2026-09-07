<?php
/**
 * Registers the bundled Datastar runtime as a script module.
 *
 * @package UltralightCarouselViaSse
 */

namespace ULCAR;

defined( 'ABSPATH' ) || exit;

/**
 * Script module registration.
 */
final class Assets {

	/** Module identifier, referenced by block.json and by view.asset.php. */
	public const MODULE = 'ulcar-datastar';

	/** Version of the bundled Datastar runtime. Must match the file name. */
	public const DATASTAR_VERSION = '1.0.3';

	/**
	 * Hooks registration.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers Datastar under our own module id.
	 *
	 * The block.json file lists this id in viewScriptModule without a "file:" prefix,
	 * which tells WordPress to use it as-is rather than to register a file of
	 * its own. Enqueueing then happens when a carousel really renders, and the
	 * import map deduplicates it if a second block ever asks for it.
	 *
	 * Datastar is served from this plugin and never from a CDN. That is not a
	 * preference: guideline 8 of the plugin directory forbids loading code from
	 * third-party CDNs.
	 */
	public static function register(): void {
		/**
		 * Filters where the Datastar runtime is loaded from.
		 *
		 * This exists for one situation, and it is a real one: a site already
		 * running Datastar for something else. WordPress deduplicates script
		 * modules by ID, and another plugin registers its own -- so both files
		 * load, and two copies of one library react to the same attributes.
		 * Nothing on the server can tell they are the same library.
		 *
		 * Pointing this filter at the copy the site already loads consolidates
		 * them. The version must match what this plugin expects, which is why
		 * it is passed along: an older Datastar will not understand the
		 * attributes this block writes, and a newer one is untested here.
		 *
		 * The front end says so too when it sees two of them -- see view.js.
		 *
		 * @since 0.4.0
		 *
		 * @param string $src     Absolute URL of the bundled runtime.
		 * @param string $version Version this plugin was written against.
		 */
		$src = apply_filters(
			'ulcar_datastar_src',
			ULCAR_URL . 'assets/vendor/datastar/datastar-' . self::DATASTAR_VERSION . '.js',
			self::DATASTAR_VERSION
		);

		wp_register_script_module(
			self::MODULE,
			$src,
			array(),
			self::DATASTAR_VERSION
		);
	}
}
