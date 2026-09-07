<?php
/**
 * Dependencies of the block's editor script.
 *
 * Hand-written, like view.asset.php. Without this file WordPress would enqueue
 * index.js with no dependencies at all, and the editor would fail on the first
 * reference to wp.blockEditor -- silently, in a console nobody has open.
 *
 * @package UltralightCarouselViaSse
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-block-editor',
		'wp-components',
		'wp-element',
		'wp-i18n',
		'wp-server-side-render',
	),
	'version'      => '0.6.0',
);
