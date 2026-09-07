<?php
/**
 * Test bootstrap.
 *
 * These are unit tests: WordPress is never loaded. Its functions are faked by
 * Brain Monkey, one test at a time, which is what makes the suite runnable
 * anywhere in under a second -- including on a machine with no database and no
 * WordPress checkout.
 *
 * What that buys and what it costs: the logic under test is pinned exactly
 * (ordering, deduplication, the cap, the HMAC, the clamping), while anything
 * that depends on how WordPress really behaves is left to the end-to-end suite,
 * which drives a real browser against a real site. Neither half is enough on
 * its own.
 *
 * @package UltralightCarouselViaSse
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// The plugin's classes guard themselves with this, as every WordPress file must.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/ShippedFiles.php';

require_once __DIR__ . '/../includes/class-slides.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-csp.php';
