# Ultralight Carousel via SSE

A WordPress block that streams its slides over Server-Sent Events. The page ships **one** image,
so the first paint, the first load and the largest contentful paint stay fast and uncluttered;
the rest arrive in a single burst and the rotation runs in the browser. Without JavaScript, or
for a visitor who asked for reduced motion, the page renders perfectly normally with that one
image and no slideshow. Built on [Datastar](https://data-star.dev/), bundled, never from a CDN.

Free and open source: GPL v2 or later, no paid version, no account, no tracking.

User-facing documentation lives in [`readme.txt`](readme.txt), which is what wordpress.org
publishes. This file is for people reading the source.

Few options for now, on purpose. If you need one that is not there, open an
[issue](https://github.com/ltruchot/ultralight-carousel-via-sse/issues) and say what you are
trying to do.

## Naming

The plugin was developed under the working name *Hypermedia Carousel for Datastar* and renamed
before its first release. The code prefix `ulcar` (ULtralight CARousel) is on everything a site
can touch -- the block name `ulcar/carousel`, the CSS classes, the option, the REST namespace,
the filters -- and it will not change again: those names are written into pages and themes.

## Running the tests

```bash
cd e2e
BASE_URL=http://localhost:8210 npm test        # Chromium and Firefox
BASE_URL=http://localhost:8210 npm run test:all # the same, plus WebKit
```

`test:all` runs inside the official Playwright image. WebKit installs like any other browser but
needs three system libraries the other two do not (`libicu74`, `libxml2`, `libflite1`), and putting
those on a machine takes root. The image carries them, so nothing has to be installed and nothing
on the machine changes. Keep its tag in `e2e/package.json` in step with the Playwright version.

Measured on all three: 87 end-to-end tests. The unit suite and the coding-standards check run
without a site:

```bash
composer install
composer exec -- phpunit   # unit tests
composer exec -- phpcs     # WordPress coding standards
```

## Written by an agent

**This plugin was coded end to end by Claude, agentically** -- design, implementation, tests,
hardening and documentation by Claude Opus 5; the pre-release audit, the rename and the
corrections it brought by Claude Fable 5.1 -- under the direction of
[@ltruchot](https://github.com/ltruchot), who set the constraints, arbitrated the trade-offs and
rejected the first answer more than once.

That claim is only worth something if you can check it, so here is what it
actually meant in practice:

- **Nothing was asserted that had not been measured.** The burst is 3 585 bytes
  and closes in 44 ms because that was measured on a running site, not
  estimated. The security boundary is described by the responses the endpoint
  actually gave to forged requests.
- **Every test was qualified by breaking what it tests.** Eleven deliberate
  sabotages of the code, ten caught on the first pass -- and the eleventh
  revealed a genuine gap, now covered. Four sabotages of `.distignore`, all
  caught, but only after the first version of that check came back green twice
  and had to be rewritten: it modelled different rules from the ones `rsync`
  applies.
- **The mistakes are in the git history rather than tidied out of it.** A lint
  script that reported success while thirteen fatal errors scrolled past. A
  documented limitation about Content-Security-Policy that the bundled version
  had already fixed. A settings sanitiser that turned a submitted `-10` into ten
  seconds. A README that still described controls and a View Transition three
  versions after both were removed. Each was found, fixed, and written down
  where the next person will read it.

If you want the reasoning behind a decision rather than the result, the commit
messages carry it: they say what changed, what was measured, and what was
rejected.

## It brings no styles of its own

The block streams images into a container with an id and fades one into the
next. That is its whole job.

It ships **no sizing, no positioning, no colour and no icons**. Only the theme
knows how big the box should be, and how an image that does not match its shape
should be cropped or padded or blurred at the edges. So those decisions are
left where the knowledge is. A plugin that guessed would force every theme to
out-specify the guess.

`blocks/carousel/style.css` does exactly two things: it stacks the slides so two
can be on screen at once, and it cross-fades the one arriving over the one
leaving. `editor.css` is the one exception, and a narrow one: it lays the slides
out flat in the editor so an author can see what they picked. None of it reaches
a visitor.

### Why not a View Transition

The slides arrive from the server, so `document.startViewTransition` is the
obvious answer. It was the first implementation, and it was wrong.

`startViewTransition` captures the **document element**: the root carries
`view-transition-name: root` by default, so every swap cross-fades the entire
viewport over itself. Measured on a real page, one swap at 1280×900: **597 604
pixels changed outside the carousel**, over the full width and the full height.
Decorative shapes elsewhere on the page flickered on a five second beat.

Neutralising that means `::view-transition-old(root) { animation: none }`, and
that rule is **document-wide**. A plugin has no business breaking the
cross-document transitions a theme may run. The snapshot is also lifted into the
top layer, escaping any mask or clip an ancestor applies to the image.

A cross-fade of two stacked images cannot reach a pixel outside the track,
cannot escape a mask, and needs no global rule. Measured again after the change,
on the same page: nothing outside the carousel beyond what changing the
photograph already touched.

## The styling contract

Because the block ships no styles, its class names are its public surface, and
they are treated as one: they will not change without a major version and a
changelog entry. A theme that styles them has no other way to reach the markup,
so leaving them undocumented would make every such theme depend on an accident.

| Name | What it is |
|---|---|
| `ulcar-carousel` | The container: id, ARIA region, signals. |
| `ulcar-track` | Wraps the slides and stacks them. |
| `ulcar-slide` | One slide. The ones off screen carry `hidden`, and the stylesheet renders them `display: block; visibility: hidden`: out of the accessibility tree and the tab order, but still able to fade. |
| `--ulcar-fade` | Custom property: the length of the cross-fade. The plugin writes the configured value here, or `0ms` when the setting says no transition; the stylesheet falls back to `1000ms` if it is unset. |

### Only one layer moves, and that is not a detail

Fading both slides at once is the obvious way to write a cross-fade, and it is
wrong. Two half-transparent layers do not add up to an opaque one: measured
mid-swap, 0.49 over 0.51 covered **0.75** of the box, so a quarter of the
container showed through. On a light background that reads as a **flash of
light** rather than a dissolve, and lengthening the fade makes it worse,
because the flash lasts longer.

The slide that is leaving carries `hidden`, so the stylesheet puts it on top and
fades it out over an incoming slide that is already fully opaque. Coverage never
leaves 1. A theme that restyles `z-index` inside the track has to preserve that.

It also means nothing fades **in**, which removes an entry animation the plugin
used to have to arm after the burst, and with it the risk of fading in the
first slide, almost always the LCP element, on every visit.

### Why `visibility` and not a discrete `display` transition

Holding the outgoing slide on screen with `transition: display … allow-discrete`
is the modern answer and it is not portable. Measured on the live site:
Chromium 151 held the slide for the length of the fade; **Firefox 153 set
`display: none` on the first frame**, while reporting
`transition-behavior: allow-discrete` and answering `true` to
`CSS.supports( 'transition-behavior', 'allow-discrete' )`.

`visibility` needs no discrete-transition support, is animatable everywhere, and
stays `visible` until the transition ends. It removes the slide from the
accessibility tree and from the tab order exactly as `display: none` did, which
was the whole reason for using the `hidden` attribute rather than `opacity: 0`.

The `display: block` that goes with it carries `!important`, because the HTML
rendering spec writes `[hidden] { display: none !important }` and a normal
author declaration loses to it.

### Each slide paints as one piece

`isolation: isolate` on every slide is not tidiness, and it is part of the
contract: **a theme may style anything inside a slide, and cannot lift it out.**

Without it a slide is not a stacking context, so a descendant carrying a
`z-index` (a theme writing `img { position: relative; z-index: 1 }` is enough,
and a real one does) is composited against a far ancestor instead. The incoming
image then paints above the outgoing slide whatever z-index the plugin gives it,
and the fade becomes a hard cut.

Measured mid-fade, outgoing slide at 0.78 opacity: **4 %** of the pixels
differed from the settled result without the isolation, **95 %** with it.

## How it works

1. The block's server render emits the shell, slide 1, and an empty placeholder for the element
   that will drive the rotation. Nothing else is in the page. **There is no `<noscript>` copy of
   the other slides**, and that is the argument rather than an omission: with scripting off this
   block is a plain image, indistinguishable from an image block. A crawler sees the first
   photograph and not the others, which for a rotating hero is the right trade.
2. `data-init__delay.500ms="@get(…)"` opens the stream once the page has settled. The request
   carries no signals (`payload: {}`) and is not restarted when the tab is hidden
   (`openWhenHidden: true`), both measured to matter -- see `render.php`.
3. The server answers with one `datastar-patch-elements` (the remaining slides), one
   `datastar-patch-signals` (how many, and that the burst landed), and one more
   `datastar-patch-elements` carrying the element that drives the rotation, marked
   `data-ulcar-burst` -- **then closes**. Measured: 3 585 bytes, 44 ms. No loop, no `sleep`,
   no worker held. The signals and the marker are sent even when nothing is left to rotate, so
   that the page can tell "no slides" from "no answer".
4. The rotation runs in the browser: `data-on-interval` steps a signal, `data-attr:hidden` on
   each slide follows it, and the stylesheet cross-fades the slide that leaves over the one that
   arrives.

Sending the cadence in the burst rather than in the initial markup is deliberate: the HTML can be
frozen by a caching layer, the burst never is. Change the interval and every visitor gets it,
purge or no purge. The list of images, on the other hand, is signed into the page: a deleted
image drops out of the burst without a purge, an added one needs the page rendered again.

## Layout

| Path | What it is |
|---|---|
| `ultralight-carousel-via-sse.php` | Header, version guard, constants. Must parse on ancient PHP; see the comment at the top. |
| `includes/class-slides.php` | Ids to HTML, attachment filtering, HMAC token. Shared by the block and the endpoint so they cannot drift. |
| `includes/class-sse-endpoint.php` | The REST route and the takeover of its output. |
| `includes/class-block.php` | Block registration, editor translations, the per-request instance counter. |
| `includes/class-settings.php` | The three site-wide settings, through the Settings API. |
| `includes/class-assets.php` | Registers the Datastar runtime as a script module, and the filter that points it elsewhere. |
| `includes/class-csp.php` | Bridges the site's CSP nonce to Datastar's CSP mode. |
| `blocks/carousel/` | `block.json`, the server render, the editor script, the front-end diagnostics (`view.js`), the styles. |
| `uninstall.php` | Removes the one option, on every site of a network. |
| `includes/datastar-php/` | The Datastar PHP SDK, namespace-prefixed. See its `UPSTREAM.md`. |
| `assets/vendor/datastar/` | The Datastar browser runtime, verbatim. See its `UPSTREAM.md`. |
| `bin/vendor-datastar.sh` | What produces those two directories. |

## No build step

There is none, on purpose. The editor script is written against `wp.element.createElement`
rather than JSX, and the two `*.asset.php` files are written by hand. What is published is what
runs: easier to review, and one fewer thing that can fall out of sync.

If the editor UI ever outgrows three controls, moving to `@wordpress/scripts` is a reversible
decision.

## Refreshing the vendored dependencies

```sh
bin/vendor-datastar.sh          # then read the diff
```

Bump `ULCAR\Assets::DATASTAR_VERSION` when the browser bundle moves: it is what names the file.

## Licence

GPL v2 or later. The two bundled Datastar components are MIT; their licences are kept beside
them.

<p align="center">
  <a href="https://digital-strategy.ec.europa.eu/en/policies/ai-labels"><img src="https://raw.githubusercontent.com/ltruchot/ultralight-carousel-via-sse/main/.github/ai-generated-label.svg" alt="AI generated" width="160"></a>
</p>
