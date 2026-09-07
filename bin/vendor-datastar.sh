#!/usr/bin/env bash
#
# Re-vendors the two upstream dependencies.
#
#   bin/vendor-datastar.sh
#
# What it does, and why it exists:
#
#   * downloads the Datastar PHP SDK at SDK_TAG, rewrites its namespace to ours,
#     and writes includes/datastar-php/ plus a loader and an UPSTREAM.md;
#   * downloads the Datastar browser bundle at JS_TAG into assets/vendor/.
#
# The namespace rewrite is the whole point. PHP does not isolate namespaces: if
# another plugin ships starfederation\datastar at a different version, whichever
# autoloader registers first wins, and the loser either fatals or -- worse,
# because it is silent -- runs against code it was not written for. Prefixing is
# the only fix in PHP, and for thirteen dependency-free files it is cheaper than
# a scoper in the build.
#
# This script is a development tool. It does not ship in the plugin ZIP; see
# .distignore.

set -euo pipefail

SDK_TAG="1.0.1"
JS_TAG="v1.0.3"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SDK_DIR="$ROOT/includes/datastar-php"
JS_DIR="$ROOT/assets/vendor/datastar"

die() { printf 'vendor-datastar: %s\n' "$1" >&2; exit 1; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# --- PHP SDK ----------------------------------------------------------------

printf 'Fetching datastar-php %s\n' "$SDK_TAG"
curl -fsSL "https://github.com/starfederation/datastar-php/archive/refs/tags/${SDK_TAG}.tar.gz" \
	-o "$work/sdk.tgz" || die "could not download the SDK tarball"
tar -xzf "$work/sdk.tgz" -C "$work"

src="$work/datastar-php-${SDK_TAG}/src"
[ -d "$src" ] || die "no src/ in the SDK tarball"

rm -rf "$SDK_DIR"
mkdir -p "$SDK_DIR"
cp -R "$src/." "$SDK_DIR/"
cp "$work/datastar-php-${SDK_TAG}/LICENSE.md" "$SDK_DIR/LICENSE.md"

# The upstream tarball carries its own .gitattributes. A dot file inside the
# plugin is an outright error for Plugin Check ("Hidden files are not
# permitted"), and .distignore cannot reach a nested one.
rm -f "$SDK_DIR/.gitattributes"

# Vendor only the code paths this plugin uses. Three event classes are for
# features it does not have; shipping them would mean asking a reviewer to read
# code that never runs. Removing a file the loader then never requires is safe
# in a way that editing one would not be.
for unused in events/ExecuteScript.php events/Location.php events/RemoveElements.php; do
	[ -f "$SDK_DIR/$unused" ] || die "expected to remove $unused, but it is not there -- upstream moved, re-read the diff"
	rm -f "$SDK_DIR/$unused"
done

# Rewrite the namespace, and add the direct-access guard WordPress expects on
# every PHP file. Those are the only two changes made to upstream code; the
# MIT headers are left exactly as they are.
find "$SDK_DIR" -name '*.php' -print0 | while IFS= read -r -d '' file; do
	perl -0pi -e 's/\bstarfederation\\datastar\b/ULCAR\\Datastar/g' "$file"
	# The guard goes AFTER the namespace declaration, never after `<?php`:
	# `namespace` has to be the first statement in a file, so a statement
	# placed above it is a parse error in every one of these files.
	perl -0pi -e "s{^(namespace [^;]+;\\n)}{\$1\ndefined( 'ABSPATH' ) || exit;\n}m" "$file"
done

# Four lines get a phpcs:ignore comment, and nothing else changes on them.
# Plugin Check reports them as errors, and an error blocks the upload form of
# the plugin directory whether the code is ours or not. Each comment says why
# the line is safe; the reasons are repeated in UPSTREAM.md.
annotate() {
	# annotate <file> <exact line content, regex-quoted by perl> <sniff> <why>
	local file="$1" needle="$2" sniff="$3" why="$4"
	grep -qF -- "$needle" "$file" || die "could not find '$needle' in $file -- upstream moved, re-read the diff"
	perl -pi -e 'BEGIN { $n = shift; $s = shift; $w = shift } if (index($_, $n) >= 0 && !$done) { my ($indent) = /^(\s*)/; $_ = "$indent// phpcs:ignore $s -- $w\n$_"; $done = 1 }' "$needle" "$sniff" "$why" "$file"
}
annotate "$SDK_DIR/ServerSentEventGenerator.php" \
	"\$protocol = \$_SERVER['SERVER_PROTOCOL'] ?? null;" \
	"WordPress.Security.ValidatedSanitizedInput" \
	"compared to a literal below and never output, stored or used to build anything."
annotate "$SDK_DIR/ServerSentEventGenerator.php" \
	"echo \$output;" \
	"WordPress.Security.EscapeOutput.OutputNotEscaped" \
	"this echo IS the SSE frame; escaping happened where the markup was composed, in ULCAR\\Slides."
annotate "$SDK_DIR/events/PatchElements.php" \
	"throw new Exception('An invalid value was passed into \`mode\`." \
	"WordPress.Security.EscapeOutput.ExceptionNotEscaped" \
	"the message lists the cases of a hard-coded enum; no user input reaches it."
annotate "$SDK_DIR/events/PatchElements.php" \
	"throw new Exception('An invalid value was passed into \`namespace\`." \
	"WordPress.Security.EscapeOutput.ExceptionNotEscaped" \
	"the message lists the cases of a hard-coded enum; no user input reaches it."

# readSignals() is replaced, not deleted. It reads $_GET and $_SERVER with no
# guards -- on a request without signals PHP 8 raises "Undefined array key",
# and with WP_DEBUG_DISPLAY on that warning prints INTO the event stream and
# makes it unparseable. WordPress plugins have a better source for request
# parameters anyway: WP_REST_Request, which validates them declaratively.
#
# A throwing stub rather than a deletion, so that the class keeps its shape and
# a future caller gets a sentence instead of a "call to undefined method".
python3 - "$SDK_DIR/ServerSentEventGenerator.php" <<'PYEOF'
import re, sys, pathlib

path = pathlib.Path(sys.argv[1])
source = path.read_text()

start = source.find('    public static function readSignals(): array')
if start == -1:
    sys.exit('vendor-datastar: readSignals() not found -- upstream moved, re-read the diff')

# Walk the braces of the method body so this survives reformatting upstream.
open_brace = source.index('{', start)
depth, end = 0, None
for i in range(open_brace, len(source)):
    if source[i] == '{':
        depth += 1
    elif source[i] == '}':
        depth -= 1
        if depth == 0:
            end = i + 1
            break
if end is None:
    sys.exit('vendor-datastar: could not find the end of readSignals()')

stub = """    public static function readSignals(): array
    {
        // Replaced while vendoring; see UPSTREAM.md. The original reads $_GET
        // and $_SERVER without guards, which can print a PHP warning into the
        // event stream. Read parameters from WP_REST_Request instead.
        throw new \\RuntimeException(
            'readSignals() is not available in this vendored copy of the Datastar SDK. Read request parameters from WP_REST_Request.'
        );
    }"""

path.write_text(source[:start] + stub + source[end:])
print('vendor-datastar: readSignals() replaced by a throwing stub')
PYEOF

# A loader instead of an autoloader: thirteen requires are easier to audit than
# six hundred lines of Composer's ClassLoader, and nothing here has a dependency.
{
	printf '<?php\n'
	printf '/**\n'
	printf " * Loads the vendored Datastar PHP SDK.\n"
	printf " *\n"
	printf " * Generated by bin/vendor-datastar.sh. Do not edit by hand.\n"
	printf " * Required only from the SSE endpoint, never at boot: these files use\n"
	printf " * enums, which are a parse error below PHP 8.1.\n"
	printf " *\n"
	printf " * @package UltralightCarouselViaSse\n"
	printf ' */\n\n'
	printf "defined( 'ABSPATH' ) || exit;\n\n"
	# Interfaces and traits first, then everything else: no autoloader means
	# load order is ours to get right.
	for f in events/EventInterface.php enums/EventType.php enums/ElementPatchMode.php \
	         enums/NamespaceType.php Consts.php ServerSentEventData.php events/EventTrait.php; do
		[ -f "$SDK_DIR/$f" ] && printf "require_once __DIR__ . '/%s';\n" "$f"
	done
	find "$SDK_DIR" -name '*.php' ! -name 'loader.php' -printf '%P\n' | sort | while IFS= read -r f; do
		case "$f" in
			events/EventInterface.php|enums/EventType.php|enums/ElementPatchMode.php|\
			enums/NamespaceType.php|Consts.php|ServerSentEventData.php|events/EventTrait.php) ;;
			*) printf "require_once __DIR__ . '/%s';\n" "$f" ;;
		esac
	done
} > "$SDK_DIR/loader.php"

sdk_sum="$(cd "$SDK_DIR" && find . -name '*.php' ! -name 'loader.php' -type f -print0 \
	| sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)"

cat > "$SDK_DIR/UPSTREAM.md" <<EOF
# Vendored: starfederation/datastar-php

| | |
|---|---|
| Upstream | https://github.com/starfederation/datastar-php |
| Tag | \`${SDK_TAG}\` |
| Licence | MIT (see \`LICENSE.md\`, kept verbatim) |
| Combined SHA-256 of the vendored PHP files | \`${sdk_sum}\` |

## What was changed, and nothing else

1. \`namespace starfederation\\datastar\` became \`namespace ULCAR\\Datastar\`
   (and the matching \`use\` statements). PHP does not isolate namespaces, so
   two plugins shipping the same unprefixed classes at different versions
   collide — either fatally, or silently, which is worse.
2. \`defined( 'ABSPATH' ) || exit;\` was added under the opening tag of each
   file, as the plugin directory expects of every PHP file.
3. Four lines carry a \`phpcs:ignore\` comment, each saying why the line is
   safe (see the table below). Plugin Check reports them as errors, and an
   error blocks the directory's upload form whether the code is ours or not.
   The lines themselves are untouched.
4. \`readSignals()\` was replaced by a stub that throws. The original reads
   \`\$_GET\` and \`\$_SERVER\` with no guards; on a request without signals PHP 8
   raises *Undefined array key*, and with \`WP_DEBUG_DISPLAY\` on that warning
   prints **into the event stream** and makes it unparseable. This plugin reads
   its parameters from \`WP_REST_Request\`, which validates them declaratively.
   A throwing stub rather than a deletion, so the class keeps its shape and a
   future caller gets a sentence instead of a fatal.
5. \`loader.php\` was generated. There is no Composer autoloader.

The MIT headers are untouched. \`readme.txt\` credits the project.

## What is deliberately NOT vendored

\`events/ExecuteScript.php\`, \`events/Location.php\` and \`events/RemoveElements.php\` are
removed: this plugin emits element and signal patches only, and shipping code that
never runs only gives a reviewer more to read. Upstream's \`.gitattributes\` is
removed too — a hidden file inside a plugin is an error for Plugin Check.

## What Plugin Check would report here, and why each line stands

Three findings come from this library and are inherent to what it does. The
code is **not** patched, because patching vendored code is how a fork starts;
each line carries a comment that names the sniff and the reason, which is what
keeps the directory's upload form from refusing the ZIP. (A fourth group, on
\`readSignals()\`, disappeared with the stub described above: the method no
longer reads anything.)

| Where | Finding | Why it stands |
|---|---|---|
| \`ServerSentEventGenerator::sendEvent()\` | \`EscapeOutput.OutputNotEscaped\` on \`echo \$output\` | That echo **is** the SSE frame. Escaping happens where the markup is composed, in \`ULCAR\\Slides\`, which is the only place that knows what is data and what is markup. |
| \`PatchElements::getMode()\` / \`getNamespace()\` | \`EscapeOutput.ExceptionNotEscaped\` (x2) | Exception messages built from a hard-coded enum. No user input reaches them. |
| \`ServerSentEventGenerator::headers()\` | \`MissingUnslash\`, \`InputNotSanitized\` on \`\$_SERVER['SERVER_PROTOCOL']\` | Compared against the literal \`'HTTP/1.1'\` to decide whether a \`Connection\` header is legal. The value is never echoed, stored, or used to build anything. |

## Refreshing

Run \`bin/vendor-datastar.sh\` from the repository root, then read the diff.
Two upstream defects are worked around in \`includes/class-sse-endpoint.php\`
rather than patched here — see the comments there before assuming a new release
fixed them.
EOF

# --- Browser bundle ---------------------------------------------------------

js_version="${JS_TAG#v}"
printf 'Fetching the Datastar bundle %s\n' "$JS_TAG"
mkdir -p "$JS_DIR"
for ext in js js.map; do
	curl -fsSL "https://cdn.jsdelivr.net/gh/starfederation/datastar@${JS_TAG}/bundles/datastar.${ext}" \
		-o "$JS_DIR/datastar-${js_version}.${ext}" || die "could not download datastar.${ext}"
done
curl -fsSL "https://raw.githubusercontent.com/starfederation/datastar/${JS_TAG}/LICENSE.md" \
	-o "$JS_DIR/LICENSE.md" || die "could not download the Datastar licence"

js_sum="$(sha256sum "$JS_DIR/datastar-${js_version}.js" | cut -d' ' -f1)"

cat > "$JS_DIR/UPSTREAM.md" <<EOF
# Vendored: the Datastar browser runtime

| | |
|---|---|
| Upstream | https://github.com/starfederation/datastar |
| Tag | \`${JS_TAG}\` |
| File | \`bundles/datastar.js\`, renamed to carry its version |
| Licence | MIT (see \`LICENSE.md\`, kept verbatim) |
| SHA-256 | \`${js_sum}\` |

The file is the official build, **byte for byte unmodified**. It is minified,
so \`datastar-${js_version}.js.map\` sits beside it: that is what makes the
code readable, as the plugin directory requires of bundled libraries.

It is served from this plugin and never from a CDN — guideline 8 forbids
loading code from third-party CDNs.

This is the free MIT core. The Pro attributes (\`data-animate\`,
\`data-persist\`, \`data-view-transition\`, \`data-match-media\`,
\`data-on-raf\`, \`data-query-string\`, …) are **not** MIT and must never appear
in this plugin's markup.

## Refreshing

Run \`bin/vendor-datastar.sh\` from the repository root, then bump
\`Assets::DATASTAR_VERSION\`, which is what names the file.
EOF

printf 'Done. SDK %s, bundle %s.\n' "$SDK_TAG" "$JS_TAG"
