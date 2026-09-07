# Contributing

## What runs, and where

| Command | What it covers | Needs |
|---|---|---|
| `composer install && composer exec -- phpunit` | Logic and boundaries: signature, attachment filtering, clamping, escaping, and the assertions that say the plugin opens no channel of its own. | PHP 8.1+ |
| `composer exec -- phpcs` | WordPress coding standards, as the plugin directory applies them. | PHP 8.1+ |
| `cd e2e && BASE_URL=… npm test` | A real browser against a real site: the burst, the rotation, the accessibility floor, what the endpoint refuses, and what the page says when the burst fails. `npm run test:all` adds WebKit, in a container. | A WordPress site; see `e2e/README.md` |

The first two run in CI on every push, and so does Plugin Check on the tree
that would be published. The third needs a site, so it is run by hand; there
is no substitute for it, because half of what this plugin promises is only
observable in a browser.

Before a release, run Plugin Check on the published tree yourself, with the
experimental checks on, and expect no findings at all: the directory's upload
form refuses a ZIP on any error, whether the line is ours or vendored.

## Two rules that are not negotiable

**A test is qualified by its failures, not by its successes.** Before trusting a
green run, break the thing it tests and watch it go red. The suites here were
built that way: eleven deliberate sabotages, ten caught on the first pass, and
the eleventh revealed a gap worth an extra test.

**Do not patch `includes/datastar-php/`.** It is vendored third-party code; read
its `UPSTREAM.md` and change `bin/vendor-datastar.sh` instead, so the next
refresh keeps the change.

## Refreshing Datastar

```sh
bin/vendor-datastar.sh    # then read the diff
```

Bump `ULCAR\Assets::DATASTAR_VERSION` when the browser bundle moves; it is what
names the file, and `tests/unit/SecurityTest.php` checks the file against the
checksum recorded beside it.
