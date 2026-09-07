<?php
/**
 * Server render of the carousel block.
 *
 * Emits ONE slide plus the shell. Slides two and up arrive later, in a single
 * SSE burst, and the rotation then runs in the browser. That is the whole point
 * of the plugin: a carousel that costs what one image costs.
 *
 * In scope, provided by WordPress: $attributes, $content, $block.
 *
 * @package UltralightCarouselViaSse
 */

use ULCAR\Block;
use ULCAR\Settings;
use ULCAR\Slides;

defined( 'ABSPATH' ) || exit;

$ulcar_ids  = Slides::sanitize_ids( (array) ( $attributes['ids'] ?? array() ) );
$ulcar_size = Slides::sanitize_size( (string) ( $attributes['sizeSlug'] ?? 'large' ) );
$ulcar_n    = count( $ulcar_ids );

// No images, nothing at all. Not an empty box, not a placeholder: a carousel
// with no photographs has nothing to say on a live site.
if ( 0 === $ulcar_n ) {
	return '';
}

$ulcar_label = trim( (string) ( $attributes['ariaLabel'] ?? '' ) );

if ( '' === $ulcar_label ) {
	$ulcar_label = __( 'Image carousel', 'ultralight-carousel-via-sse' );
}

$ulcar_dom_id = Slides::dom_id( $ulcar_ids, $ulcar_size, Block::next_instance() );
$ulcar_signal = Slides::signal_key( $ulcar_dom_id );

/*
 * The cross-fade, or the absence of one.
 *
 * The stylesheet owns the fade and reads its length from --ulcar-fade, so
 * turning the setting off is one declaration rather than a second code path:
 * zero is a cut. A theme that wants another length sets the same property.
 *
 * The length rides in the rendered HTML rather than in the burst, unlike the
 * interval. That is a real limitation and it is stated in the settings page:
 * a page already in a cache keeps the length it was rendered with. The interval
 * had to travel in the burst because data-on-interval parses its duration from
 * the attribute NAME; a custom property has no such constraint, and putting it
 * here keeps the swap correct even if the burst never arrives.
 */
$ulcar_fade = sprintf(
	' style="--ulcar-fade:%dms"',
	'fade' === Settings::transition() ? Settings::duration() : 0
);

/*
 * The editor previews a dynamic block by rendering it through the REST block
 * renderer, where Datastar is not loaded -- viewScriptModule belongs to the
 * front end. Left alone, the author would see one image and no way to tell
 * whether the other six were saved. So the preview shows the whole selection,
 * flat, with no behaviour attached.
 *
 * The test is broader than the editor: any REST render gets this branch, a
 * headless front end reading `content.rendered` included. That is the right
 * answer there too -- such a client has no Datastar to finish the job, and a
 * complete static carousel is what it can use.
 */
$ulcar_is_preview = wp_is_serving_rest_request();

// One image never rotates, so it needs no shell and no stream.
$ulcar_is_static = $ulcar_is_preview || 1 === $ulcar_n;

$ulcar_wrapper = get_block_wrapper_attributes(
	array( 'class' => 'ulcar' . ( $ulcar_is_preview ? ' ulcar--preview' : '' ) )
);

if ( $ulcar_is_static ) {
	printf(
		'<div %1$s><div class="ulcar-track">%2$s</div></div>',
		$ulcar_wrapper, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes().
		Slides::render_slides( $ulcar_ids, $ulcar_size ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Slides, which escapes.
	);
	return;
}

$ulcar_ids_csv = implode( ',', $ulcar_ids );

/*
 * Not esc_url(): it turns "&" into "&#038;", and the single esc_attr() applied
 * to the whole expression below would then encode the ampersand a second time.
 * The URL is our own, built from rest_url(), and carries nothing a user typed.
 */
$ulcar_stream = add_query_arg(
	array(
		'ids'    => $ulcar_ids_csv,
		'size'   => $ulcar_size,
		'target' => $ulcar_dom_id,
		'token'  => Slides::token( $ulcar_ids_csv, $ulcar_size, $ulcar_dom_id ),
	),
	rest_url( 'ulcar/v1/slides' )
);

/*
 * `count` starts at 1 because exactly one slide exists right now. The burst
 * corrects it. Getting this wrong would make the rotation step through slides
 * that are not there yet, and the carousel would blink on an empty box.
 *
 * `loaded` guards against a second burst: were data-init to run again, an
 * append would duplicate every slide.
 */
$ulcar_signals = wp_json_encode(
	array(
		'ulcar' => array(
			substr( $ulcar_signal, strlen( 'ulcar.' ) ) => array(
				'view'   => 0,
				'count'  => 1,
				'loaded' => false,
			),
		),
	)
);

/*
 * Two options on the request, and each one closes a hole that was measured.
 *
 * `payload: {}` -- by default @get appends EVERY signal on the page to the
 * query string. Alone, that is fifty bytes of this block's own state. But the
 * readme tells a site that already runs Datastar to share one runtime, and
 * then whatever another plugin binds -- a search field, a password -- would
 * ride along into this site's access logs. The route reads no signals at all.
 *
 * `openWhenHidden: true` -- a GET is otherwise ABORTED when the tab is hidden
 * and re-issued when it shows again. Open a page in a background tab, switch
 * to it while the burst is in flight, and the slides are appended twice: the
 * `loaded` guard cannot help, it is the same request restarting. The burst is
 * tiny and closes at once, so there is nothing to save by holding it back.
 */
$ulcar_init = sprintf(
	'!$%1$s.loaded && @get(\'%2$s\', {payload: {}, openWhenHidden: true})',
	$ulcar_signal,
	$ulcar_stream
);
?>
<div <?php echo $ulcar_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
	<div
		id="<?php echo esc_attr( $ulcar_dom_id ); ?>"
		class="ulcar-carousel"
		role="region"
		aria-roledescription="<?php esc_attr_e( 'carousel', 'ultralight-carousel-via-sse' ); ?>"
		aria-label="<?php echo esc_attr( $ulcar_label ); ?>"
		<?php echo $ulcar_fade; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a literal chosen above, not data. ?>
		data-signals="<?php echo esc_attr( (string) $ulcar_signals ); ?>"
		data-init__delay.500ms="<?php echo esc_attr( $ulcar_init ); ?>"
	>
		<?php
		/*
		 * ONE image, and nothing else, until the browser asks for more.
		 *
		 * There is deliberately no <noscript> copy of the other slides. It was
		 * there at first, for crawlers, and it was wrong twice over. It broke
		 * the layout of a page without scripting -- the slides fall out of the
		 * grid and stack vertically, 3 593 pixels of photographs where the
		 * design has one box -- and it contradicted the whole argument of the
		 * plugin: a page that costs what ONE image costs. Without JavaScript
		 * this block is a plain image, indistinguishable from an image block,
		 * and nobody is any the wiser.
		 *
		 * What it costs, said plainly: a crawler sees the first photograph and
		 * not the others. For a rotating hero that is the right trade -- the
		 * other slides are decoration, and the page they illustrate is indexed
		 * either way.
		 */
		?>
		<div class="ulcar-track">
			<?php
			echo Slides::render_slide( $ulcar_ids[0], $ulcar_size, 0, $ulcar_n, $ulcar_signal ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Slides, which escapes.
			?>
		</div>

		<?php
		/*
		 * Placeholder for the element that carries the rotation cadence. The
		 * burst replaces it whole, which is how the interval reaches a page
		 * that a caching layer froze days ago: the HTML is stale, the burst
		 * never is. data-on-interval parses its duration from the attribute
		 * NAME, so no signal could have carried it.
		 */
		?>
		<div id="<?php echo esc_attr( $ulcar_dom_id ); ?>-cadence" hidden></div>
	</div>
</div>
