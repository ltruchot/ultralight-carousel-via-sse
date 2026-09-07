<?php
/**
 * Registers the carousel block.
 *
 * @package UltralightCarouselViaSse
 */

namespace ULCAR;

defined( 'ABSPATH' ) || exit;

/**
 * Block registration and per-request instance numbering.
 */
final class Block {

	/**
	 * How many carousels have been rendered so far in this request.
	 *
	 * @var int
	 */
	private static int $instances = 0;

	/**
	 * Hooks registration.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the block from its metadata.
	 *
	 * Everything the block needs is declared in block.json: the render file,
	 * the editor script, and the two view modules. Nothing is enqueued here --
	 * WordPress puts viewScriptModule in the queue when the block actually
	 * renders, which is more accurate than any has_block() check could be
	 * (has_block sees neither template parts nor synced patterns).
	 */
	public static function register(): void {
		register_block_type_from_metadata( ULCAR_PATH . 'blocks/carousel' );

		/*
		 * The editor script calls wp.i18n; without this its strings stay in
		 * English however well the plugin is translated. The handle is the one
		 * generate_block_asset_handle() builds from block.json: the block name
		 * with its slash turned into a dash, plus the field.
		 *
		 * The path is NOT optional, and the comment that used to say so was
		 * wrong. Without it WordPress looks only in the language pack directory,
		 * `WP_LANG_DIR/plugins/` -- so the French this plugin SHIPS was never
		 * read, and the editor stayed in English however well it was translated.
		 * Measured in the block editor: the whole sidebar in English while the
		 * PHP side was already French. WordPress checks this directory first and
		 * falls back to the language packs on its own, so naming it costs
		 * nothing and is what a plugin carrying its own translations must do.
		 *
		 * And the file it reads is a JSON one -- `<domain>-<locale>-<md5>.json`,
		 * built by `wp i18n make-json` from the .po. A .mo alone translates the
		 * PHP and nothing else.
		 */
		wp_set_script_translations(
			'ulcar-carousel-editor-script',
			'ultralight-carousel-via-sse',
			ULCAR_PATH . 'languages'
		);
	}

	/**
	 * Returns the ordinal of the carousel about to be rendered.
	 *
	 * Two carousels on one page must not share a DOM id, and must not share a
	 * signal namespace -- Datastar signals are global to the document, so they
	 * would drive each other.
	 *
	 * @return int Zero-based ordinal within the current request.
	 */
	public static function next_instance(): int {
		return self::$instances++;
	}
}
