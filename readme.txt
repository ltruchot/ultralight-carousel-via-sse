=== Ultralight Carousel via SSE ===
Contributors: ltruchot
Tags: carousel, slideshow, gallery, images, performance
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A light image carousel. The page loads one image, the others stream in after. Fast first paint, and it still works without JavaScript.

== Description ==

A carousel usually puts every image in the page. This one puts **one**. The others arrive a
moment later over Server-Sent Events (SSE), then the slideshow runs in the browser.

What you get:

* **A fast first paint.** The page loads with a single image, so nothing else slows it down.
* **Nothing breaks.** If JavaScript is off, or a visitor asked their system for reduced motion,
  the page looks normal. The first image is there. It just does not rotate.
* **A simple, modern way to do a carousel.** No jQuery, no CDN, no library to configure.
* **Free and open source.** GPL, no paid version, no account, no tracking. Use it on any site.

It is built on [Datastar](https://data-star.dev/), a small hypermedia library that is bundled with
the plugin.

There are **few options** for now. If you need something specific, open an issue on
[GitHub](https://github.com/ltruchot/ultralight-carousel-via-sse/issues) and say what you are
trying to do.

= How to use it =

1. Add the **Ultralight Carousel** block to a page.
2. Pick your images in the Media Library, like you would for a Gallery. The order you pick is the
   order they rotate in. Up to 50 images.
3. Done.

To change the images later, select the block and click **Images** in its toolbar.

The block stores media IDs, not copies. Replace an image in the Media Library and every carousel
that uses it is updated.

= Settings =

Go to **Settings → Ultralight Carousel**. Three settings, shared by every carousel on the site:

* how long each image stays on screen (2.5 to 25 seconds);
* whether images cross-fade or just switch;
* how long the cross-fade takes (100 to 2000 ms).

= Styling =

The block has no look of its own. No size, no colours, no arrows. Your theme decides how big the
box is and how images are cropped. Give it a container with a size and the images fill it.

If you style it, these are the names to use. They will not change:

* `ulcar-carousel`: the container.
* `ulcar-track`: wraps the slides.
* `ulcar-slide`: one slide. Slides off screen have the `hidden` attribute.
* `--ulcar-fade`: a CSS variable with the cross-fade length.

Keep one thing as it is: the slide that leaves sits on top and fades out over the next one. If
you change `z-index` inside the track, the fade turns into a flash.

= Accessibility: read this first =

**This carousel starts by itself and has no pause button.** No play, pause or arrows. On a page
with other content, that fails WCAG 2.2.2 (level A). If your site must meet WCAG level A, do not
use this plugin.

What it does do: no rotation at all for visitors who asked for reduced motion; slides off screen
are hidden from screen readers and the tab order; nothing is announced; the carousel is a named
region and each slide says "2 of 5", with the alt text from the Media Library.

= What it costs =

One extra request per carousel, per page view. The request is a single burst that closes right
away, a few hundredths of a second of PHP. It never keeps a connection open.

Cached pages stay correct: the burst is never cached, so a deleted image disappears from every
carousel without clearing any cache, and a new rotation speed applies right away. Adding an
image, or changing the cross-fade length, needs the page cache cleared.

== Frequently Asked Questions ==

= The carousel stays on its first image =

Open the browser console. After five seconds the plugin says which carousel got no slides and,
when it knows, why. The usual causes:

* the REST API is restricted to logged-in users: allow the `ulcar/v1` namespace;
* the Site Address is not the address visitors use;
* an optimisation plugin delays or minifies `datastar-1.0.3.js`: exclude that file;
* a strict Content-Security-Policy without the nonce filter (see below).

= Does it work with a strict Content-Security-Policy? =

Yes, without `unsafe-eval`, if you give it your page nonce:

`add_filter( 'ulcar_csp_nonce', fn() => my_csp_nonce() );`

The plugin does not make up a nonce. It only works if the same value is in the `script-src` of the
response, and only your CSP code knows that value.

= Another plugin already loads Datastar =

Two copies of Datastar on one page freeze it. Point this plugin at the copy the site already
loads. It must be the same version as the one bundled here:

`add_filter( 'ulcar_datastar_src', fn() => 'https://example.com/datastar-1.0.3.js' );`

= Is the stream secure? =

Yes. The page carries a signature over the image list, and the stream refuses anything else. That
proves the list was made by your site. It does not make images private: an image in the Media
Library is public, as with the core Gallery block. If you regenerate your site's secret keys,
clear the page cache, or cached pages keep an old signature and stay on their first image.

= Does it phone home? =

No. The only request it makes is to your own site.

= I need an option that is not there =

Open an issue on [GitHub](https://github.com/ltruchot/ultralight-carousel-via-sse/issues).

= What happens if I deactivate or uninstall it? =

Deactivating leaves nothing in your content: the page keeps a block comment and no markup.
Uninstalling removes the single option the plugin stores.

== Development ==

The source is at
[github.com/ltruchot/ultralight-carousel-via-sse](https://github.com/ltruchot/ultralight-carousel-via-sse).
What is published here is that source: no build step, no bundler. The only minified file is the
Datastar runtime, which is third-party code and ships with its source map.

== Third-party code ==

Two pieces of [Datastar](https://data-star.dev/), both MIT licensed, both served from this plugin:
the browser runtime `v1.0.3`, unmodified, and the PHP SDK `1.0.1` with its namespace prefixed so
it cannot collide with another plugin. Each folder has the upstream licence and an `UPSTREAM.md`
with the exact version, its checksum and every change made to it.

== AI label ==

This plugin's code was written by an AI assistant, under human direction, and carries the
European Union's "AI generated" label. The README on GitHub says how.

== Changelog ==

= 0.6.0 =
* Renamed from its working name, Hypermedia Carousel for Datastar. The block name, CSS classes,
  option, REST namespace and filters now use the `ulcar` prefix.
* The stream request no longer carries the page's signals, and is not restarted when the tab is
  hidden. That could add every slide twice.
* The console message for a failed stream now gives the HTTP status, and no longer fires for a
  carousel whose other images were deleted.
* The editor warns when more than 50 images are picked.
* On a multisite network, a stream signature is now tied to one site.

Earlier releases: see the repository history.
