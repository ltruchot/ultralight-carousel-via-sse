# End-to-end suite

Drives a real browser against a real WordPress site. Nothing here is mocked:
what it measures is what a visitor gets.

## Running it

```sh
npm install
npx playwright install chromium firefox
BASE_URL=http://localhost:8888 npm test        # Chromium and Firefox
BASE_URL=http://localhost:8888 npm run test:all # the same, plus WebKit
```

`test:all` runs the whole suite inside the official Playwright image, because
WebKit needs three system libraries the other two engines do without and
installing those takes root. The image tag in `package.json` is pinned to the
exact `@playwright/test` version, and it has to stay that way: browsers in the
container are the ones that version expects.

`BASE_URL` is **required and has no default**. A runner that falls back to some
address when the variable is missing will one day measure a site nobody meant to
measure and report the result as if it came from the one under test.

## Fixture pages

The suite needs five pages carrying the block in different shapes. Set `WP_CLI`
to whatever runs WP-CLI against that site and they are created for you:

```sh
WP_CLI="wp"                       # a local install
WP_CLI="wp-env run cli wp"        # wp-env
WP_CLI="docker run … wp"          # a container of your own
```

Leave `WP_CLI` unset and the suite assumes the pages already exist, which is
what you want against a site you cannot drive from a shell.

The site needs **at least five image attachments** in its media library; the
suite uses real ones, so that it exercises the media library rather than a
catalogue it made up.

## What each file is for

| File | What it proves |
|---|---|
| `tests/carousel.spec.ts` | One image ships, the rest arrive in one burst, the rotation runs, and the shapes with zero, one and two carousels behave. |
| `tests/security.spec.ts` | The single channel accepts what it declared and nothing else: forged signatures, malformed parameters, other methods, other origins, undeclared parameters, and no third-party request. |
| `tests/accessibility.spec.ts` | WCAG 2.2.2 and its neighbours, including a test that deliberately breaks the page to prove axe is looking. |
| `tests/diagnostics.spec.ts` | What the page does when the burst goes wrong: the request carries no signals and is not re-issued when the tab shows; an empty burst is not reported as a failure; a refused one is reported with its status. The stream is replaced on the way in so each failure is the exact one under test. |
