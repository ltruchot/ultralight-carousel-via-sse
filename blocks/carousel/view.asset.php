<?php
/**
 * Dependencies of view.js, written by hand.
 *
 * WordPress reads this file to know what the view module needs. It is not
 * generated: this plugin ships no build step, and a file this short is easier
 * to review than the tool that would have produced it.
 *
 * The dependency is what orders the two module tags. It is NOT what makes the
 * diagnostics work if Datastar is missing -- view.js imports nothing from it on
 * purpose, so it still runs and still reports when the runtime never arrives.
 *
 * @package UltralightCarouselViaSse
 */

return array(
	'dependencies' => array( 'ulcar-datastar' ),
	'version'      => '0.6.0',
);
