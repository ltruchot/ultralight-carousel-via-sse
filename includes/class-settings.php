<?php
/**
 * The plugin's settings: how long a slide stays on screen, which transition
 * plays between two slides, and how long it takes.
 *
 * @package UltralightCarouselViaSse
 */

namespace ULCAR;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the three site-wide settings.
 */
final class Settings {

	/** Option name. Also hard-coded in uninstall.php, which cannot load this class. */
	public const OPTION = 'ulcar_settings';

	/** Settings group, used by register_setting() and settings_fields(). */
	private const GROUP = 'ulcar';

	/** Slug of the settings page, under Settings. */
	private const PAGE = 'ulcar';

	/** The page's only section. */
	private const SECTION = 'ulcar_main';

	/** Shortest interval a human can follow, in seconds. */
	public const MIN_INTERVAL = 2.5;

	/** Longest interval that still reads as a carousel rather than a bug. */
	public const MAX_INTERVAL = 25;

	/** Shortest cross-fade the setting accepts, in milliseconds. */
	public const MIN_DURATION = 100;

	/** Longest cross-fade the setting accepts, in milliseconds. */
	public const MAX_DURATION = 2000;

	/**
	 * Transitions the stylesheet knows how to run between two slides.
	 *
	 * A cross-fade of two stacked images, and not a View Transition, which was
	 * the first implementation and was wrong: startViewTransition snapshots the
	 * document element, so every swap cross-faded the whole viewport over
	 * itself -- 597 604 pixels changed outside the carousel on one measured
	 * swap. See blocks/carousel/style.css.
	 *
	 * Deliberately a list rather than a boolean: `none` and `fade` are what
	 * exists today, and the next one is meant to slot in beside them without
	 * changing the shape of the setting or of what reads it.
	 */
	public const TRANSITIONS = array( 'fade', 'none' );

	/** Shipped defaults. */
	public const DEFAULTS = array(
		'interval'   => 5.0,
		'transition' => 'fade',
		'duration'   => 1000,
	);

	/**
	 * Hooks the setting up.
	 *
	 * On `init`, not `admin_init`. register_setting() installs the default
	 * through the `default_option_{$option}` filter, and only for the request
	 * that called it -- registering on `admin_init` alone would make
	 * get_option() return false on the front end until someone opened the
	 * settings page. Reading through defaults() covers the same ground twice,
	 * on purpose.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_fields' ) );

		// The "Settings" link every other plugin shows under its own row on the
		// plugins screen. WordPress does not infer it: without this filter the
		// page exists but nothing on that screen points at it, and the only way
		// to find it is to already know where it is.
		add_filter(
			'plugin_action_links_' . plugin_basename( ULCAR_FILE ),
			array( __CLASS__, 'add_settings_link' )
		);
	}

	/**
	 * Puts a Settings link at the front of the plugin's row actions.
	 *
	 * @param array<int|string, string> $links Links already there.
	 * @return array<int|string, string> Links, with ours first.
	 */
	public static function add_settings_link( $links ): array {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'ultralight-carousel-via-sse' )
		);

		// Prepended, not appended: "Settings" belongs before "Deactivate", which
		// is where every core and well-behaved plugin puts it.
		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Declares the option.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'object',
				'default'           => self::DEFAULTS,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Clamps the submitted interval into range and says so when it had to.
	 *
	 * @param mixed $input Raw value from the settings form.
	 * @return array{interval: float, transition: string, duration: int} Sanitised settings.
	 */
	public static function sanitize( $input ): array {
		$raw      = is_array( $input ) && isset( $input['interval'] ) ? $input['interval'] : null;
		$interval = self::to_interval( $raw );

		if ( is_numeric( $raw ) && ( (float) $raw < self::MIN_INTERVAL || (float) $raw > self::MAX_INTERVAL ) ) {
			add_settings_error(
				self::OPTION,
				'ulcar_interval_range',
				sprintf(
					/* translators: 1: shortest allowed interval, 2: longest allowed interval, both in seconds. */
					__( 'The rotation interval must be between %1$s and %2$s seconds. Your value was adjusted.', 'ultralight-carousel-via-sse' ),
					self::MIN_INTERVAL,
					self::MAX_INTERVAL
				),
				'warning'
			);
		}

		$raw_duration = is_array( $input ) && isset( $input['duration'] ) ? $input['duration'] : null;

		if ( is_numeric( $raw_duration )
			&& ( (int) $raw_duration < self::MIN_DURATION || (int) $raw_duration > self::MAX_DURATION ) ) {
			add_settings_error(
				self::OPTION,
				'ulcar_duration_range',
				sprintf(
					/* translators: 1: shortest allowed cross-fade, 2: longest allowed cross-fade, both in milliseconds. */
					__( 'The cross-fade must last between %1$d and %2$d milliseconds. Your value was adjusted.', 'ultralight-carousel-via-sse' ),
					self::MIN_DURATION,
					self::MAX_DURATION
				),
				'warning'
			);
		}

		return array(
			'interval'   => $interval,
			'transition' => self::to_transition(
				is_array( $input ) && isset( $input['transition'] ) ? $input['transition'] : null
			),
			'duration'   => self::to_duration( $raw_duration ),
		);
	}

	/**
	 * Keeps only a transition this version knows how to play.
	 *
	 * An unknown value means the option was written by another version or by
	 * hand. Falling back to the shipped default is the only safe reading: a
	 * name that reaches the markup unchecked would set a view-transition-name
	 * nobody defined, and the browser would cross-fade the whole page.
	 *
	 * @param mixed $value Raw value.
	 * @return string One of self::TRANSITIONS.
	 */
	private static function to_transition( $value ): string {
		return in_array( $value, self::TRANSITIONS, true ) ? (string) $value : self::DEFAULTS['transition'];
	}

	/**
	 * Returns the transition to play between two slides.
	 *
	 * @return string One of self::TRANSITIONS.
	 */
	public static function transition(): string {
		$stored = wp_parse_args( (array) get_option( self::OPTION, array() ), self::DEFAULTS );

		return self::to_transition( $stored['transition'] );
	}

	/**
	 * Turns whatever was submitted or stored into a usable number of seconds.
	 *
	 * Deliberately NOT absint(): it mirrors a negative instead of refusing it,
	 * so someone posting -10 would silently get a ten-second carousel. A value
	 * that is not a number at all falls back to the shipped default rather than
	 * to the floor, because the floor is a boundary, not a preference.
	 *
	 * @param mixed $value Raw value.
	 * @return float Seconds, within range, rounded to a tenth.
	 */
	private static function to_interval( $value ): float {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULTS['interval'];
		}

		// Rounded to a tenth before clamping: the field offers half-seconds, but
		// a value posted by hand should not store fifteen decimals into an
		// attribute the browser then has to parse.
		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, round( (float) $value, 1 ) ) );
	}

	/**
	 * Returns the rotation interval in seconds, always within range.
	 *
	 * Never call get_option() for this directly: a site whose option row was
	 * written by an older version, or by hand, can hold anything at all.
	 */
	public static function interval(): float {
		$stored = wp_parse_args( (array) get_option( self::OPTION, array() ), self::DEFAULTS );

		return self::to_interval( $stored['interval'] );
	}

	/**
	 * The same interval, in whole milliseconds, for the cadence attribute.
	 *
	 * Datastar parses `__duration.<n>ms` and `__duration.<n>s` alike -- verified
	 * in the shipped bundle, which reads a trailing `ms` first and only then a
	 * trailing `s`. Milliseconds are used because the field accepts half
	 * seconds, and `2.5s` in an attribute NAME is a dot too many in a place
	 * where dots already separate modifiers.
	 */
	public static function interval_ms(): int {
		return (int) round( self::interval() * 1000 );
	}

	/**
	 * Turns whatever was submitted or stored into a usable number of milliseconds.
	 *
	 * Same reading as to_interval(): not absint(), which would mirror a negative
	 * instead of refusing it, and a value that is not a number at all falls back
	 * to the shipped default rather than to the floor -- the floor is a
	 * boundary, not a preference.
	 *
	 * @param mixed $value Raw value.
	 * @return int Milliseconds, within range.
	 */
	private static function to_duration( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULTS['duration'];
		}

		return max( self::MIN_DURATION, min( self::MAX_DURATION, (int) $value ) );
	}

	/**
	 * Returns the cross-fade length in milliseconds, always within range.
	 *
	 * Never call get_option() for this directly: a site whose option row was
	 * written by an older version has no `duration` key at all, and
	 * wp_parse_args() is what supplies it.
	 */
	public static function duration(): int {
		$stored = wp_parse_args( (array) get_option( self::OPTION, array() ), self::DEFAULTS );

		return self::to_duration( $stored['duration'] );
	}

	/**
	 * Adds the settings page under Settings.
	 *
	 * Not a top-level menu: one plugin, one option, no business being in the
	 * admin sidebar next to Posts and Media.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Ultralight Carousel', 'ultralight-carousel-via-sse' ),
			__( 'Ultralight Carousel', 'ultralight-carousel-via-sse' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Declares the section and the fields, through the Settings API.
	 *
	 * On `admin_init`, which is where the API expects them: these describe an
	 * admin screen and have nothing to say on the front end.
	 *
	 * An earlier version printed the table by hand, on the grounds that two
	 * fields did not justify three callbacks. That reasoning was wrong twice
	 * over. It is what the Plugin Handbook prescribes, so it is what a reviewer
	 * reads for; and a hand-written table cannot be extended -- with the API,
	 * another plugin can add a field to this page, and this one can grow a
	 * section without touching the page at all.
	 */
	public static function add_fields(): void {
		add_settings_section(
			self::SECTION,
			'',
			array( __CLASS__, 'render_section' ),
			self::PAGE
		);

		add_settings_field(
			'ulcar_interval',
			__( 'Time on screen', 'ultralight-carousel-via-sse' ),
			array( __CLASS__, 'render_interval_field' ),
			self::PAGE,
			self::SECTION,
			array( 'label_for' => 'ulcar-interval' )
		);

		add_settings_field(
			'ulcar_transition',
			__( 'Transition between slides', 'ultralight-carousel-via-sse' ),
			array( __CLASS__, 'render_transition_field' ),
			self::PAGE,
			self::SECTION,
			array( 'label_for' => 'ulcar-transition' )
		);

		add_settings_field(
			'ulcar_duration',
			__( 'Transition length', 'ultralight-carousel-via-sse' ),
			array( __CLASS__, 'render_duration_field' ),
			self::PAGE,
			self::SECTION,
			array( 'label_for' => 'ulcar-duration' )
		);
	}

	/**
	 * Says where the images are chosen, since they are not chosen here.
	 */
	public static function render_section(): void {
		?>
		<p>
			<?php esc_html_e( 'Images are picked in the block itself. These settings apply to every carousel on the site.', 'ultralight-carousel-via-sse' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the interval field.
	 */
	public static function render_interval_field(): void {
		?>
		<input
			type="number"
			id="ulcar-interval"
			name="<?php echo esc_attr( self::OPTION ); ?>[interval]"
			value="<?php echo esc_attr( (string) self::interval() ); ?>"
			min="<?php echo esc_attr( (string) self::MIN_INTERVAL ); ?>"
			max="<?php echo esc_attr( (string) self::MAX_INTERVAL ); ?>"
			step="0.5"
			required
			class="small-text"
		>
		<?php echo ' ' . esc_html__( 's', 'ultralight-carousel-via-sse' ); ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: shortest allowed interval, 2: longest allowed interval, both in seconds. */
					__( 'Between %1$s and %2$s seconds, in half-second steps. A carousel with a single image never rotates.', 'ultralight-carousel-via-sse' ),
					self::MIN_INTERVAL,
					self::MAX_INTERVAL
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the cross-fade length field.
	 */
	public static function render_duration_field(): void {
		?>
		<input
			type="number"
			id="ulcar-duration"
			name="<?php echo esc_attr( self::OPTION ); ?>[duration]"
			value="<?php echo esc_attr( (string) self::duration() ); ?>"
			min="<?php echo esc_attr( (string) self::MIN_DURATION ); ?>"
			max="<?php echo esc_attr( (string) self::MAX_DURATION ); ?>"
			step="50"
			required
			class="small-text"
		>
		<?php echo ' ' . esc_html__( 'ms', 'ultralight-carousel-via-sse' ); ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: shortest allowed cross-fade, 2: longest allowed cross-fade, both in milliseconds. */
					__( 'Between %1$d and %2$d milliseconds. Ignored when the transition is off.', 'ultralight-carousel-via-sse' ),
					self::MIN_DURATION,
					self::MAX_DURATION
				)
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Clear your page cache after changing this: cached pages keep the old length.', 'ultralight-carousel-via-sse' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the transition field.
	 */
	public static function render_transition_field(): void {
		$labels  = array(
			'fade' => __( 'Cross-fade', 'ultralight-carousel-via-sse' ),
			'none' => __( 'None, the image just switches', 'ultralight-carousel-via-sse' ),
		);
		$current = self::transition();
		?>
		<select id="ulcar-transition" name="<?php echo esc_attr( self::OPTION ); ?>[transition]">
			<?php foreach ( self::TRANSITIONS as $transition ) : ?>
				<option value="<?php echo esc_attr( $transition ); ?>" <?php selected( $transition, $current ); ?>>
					<?php echo esc_html( $labels[ $transition ] ?? $transition ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Visitors who asked their system for reduced motion never get a transition.', 'ultralight-carousel-via-sse' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the settings page.
	 *
	 * The capability is checked again here even though add_options_page() will
	 * not show the page without it: a direct request to the page slug reaches
	 * this callback, and a screen that renders its form to whoever asks is one
	 * change of mind about menu registration away from a hole.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
