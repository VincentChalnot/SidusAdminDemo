#!/usr/bin/env bash
#
# HTTP smoke test of a running Sidus/AdminDemo instance (the production image, or any other deployment).
#
#   scripts/smoke.sh [BASE_URL]            default: http://127.0.0.1:8080
#
# Environment:
#   SMOKE_WAIT    seconds to wait for /healthz to answer 200 before giving up (default 180)
#   SMOKE_ROUNDS  how many times the whole request sequence is replayed (default 4)
#
# What it does:
#   1. waits for /healthz (200 + body "ok"),
#   2. requests home, the News list, a filtered, a sorted and a paged list, and a compiled asset, and
#      asserts status 200 + expected markers in the page + the cache/security headers,
#   3. replays the very same sequence SMOKE_ROUNDS times (forward, then reversed, ...): every page must be
#      byte-for-byte identical to its first rendering. The data does not change, so any difference means
#      that state leaked from one request into another (typical of FrankenPHP worker mode with stateful
#      services: "Pager already applied", a sort/filter sticking to the next request, ...).
#
# Exit code: 0 when everything passed, 1 otherwise.
#
# The request formats below follow Sidus/DataGridBundle's GET form (filters under form[filters][...],
# a sort button is form[sortable][<column index>], paging is ?page=N). Adapt the PAGES table if the
# bundles change them.

set -uo pipefail

BASE_URL="${1:-${BASE_URL:-http://127.0.0.1:8080}}"
BASE_URL="${BASE_URL%/}"
SMOKE_WAIT="${SMOKE_WAIT:-180}"
SMOKE_ROUNDS="${SMOKE_ROUNDS:-4}"

failures=0
fail() {
	echo "  FAIL: $*" >&2
	failures=$((failures + 1))
}

sha256() {
	if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi
}

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

# name|path|marker that must appear in the body (fixed string)
PAGES=(
	'home|/|Sidus/AdminBundle Demo'
	'list|/news/|Displaying'
	'filtered|/news/?form[filters][title]=qui&form[filterSubmitButton]=|value="qui"'
	'sorted|/news/?form[sortable][1]=|name="form[sortable][config][column]"'
	'sorted-desc|/news/?form[sortable][config][column]=title&form[sortable][config][direction]=0&form[sortable][1]=|name="form[sortable][config][column]"'
	'paged|/news/?page=2|aria-current="page"'
	'filtered-sorted-paged|/news/?form[filters][title]=qui&form[sortable][1]=&page=2|Displaying'
	'healthz|/healthz|ok'
)

# fetch <name> <path>: stores status in $workdir/<name>.status, headers in .headers, body in .body
fetch() {
	local name="$1" path="$2"
	curl --globoff --silent --show-error --max-time 30 \
		--output "$workdir/$name.body" --dump-header "$workdir/$name.headers" \
		--write-out '%{http_code}' "$BASE_URL$path" >"$workdir/$name.status" 2>"$workdir/$name.curlerr" ||
		echo 000 >"$workdir/$name.status"
}

echo "== Waiting for $BASE_URL/healthz (up to ${SMOKE_WAIT}s)"
deadline=$((SECONDS + SMOKE_WAIT))
until [ "$(curl --silent --max-time 5 --output /dev/null --write-out '%{http_code}' "$BASE_URL/healthz" 2>/dev/null)" = 200 ]; do
	if [ "$SECONDS" -ge "$deadline" ]; then
		echo "FAIL: $BASE_URL/healthz did not answer 200 within ${SMOKE_WAIT}s" >&2
		exit 1
	fi
	sleep 2
done
echo "   healthy after $((SECONDS - (deadline - SMOKE_WAIT)))s"

echo "== Round 1: status + markers"
declare -A reference
for entry in "${PAGES[@]}"; do
	IFS='|' read -r name path marker <<<"$entry"
	fetch "$name" "$path"
	status="$(cat "$workdir/$name.status")"
	if [ "$status" != 200 ]; then
		fail "$name ($path): HTTP $status $(cat "$workdir/$name.curlerr")"
		continue
	fi
	if ! grep -qF -- "$marker" "$workdir/$name.body"; then
		fail "$name ($path): marker not found in the body: $marker"
		continue
	fi
	reference[$name]="$(sha256 <"$workdir/$name.body")"
	echo "   ok   $name  $path"
done

echo "== Round 1: content and headers"
# The list must actually show rows and a pager; the filter must narrow it down.
if [ -s "$workdir/list.body" ]; then
	rows="$(grep -c '<tr' "$workdir/list.body" || true)"
	[ "$rows" -ge 2 ] && echo "   ok   list has $rows <tr> (header included)" || fail "list: no table rows"
	grep -q 'class="pagination' "$workdir/list.body" && echo "   ok   list has a pager" || fail "list: no pager"
fi
total() { grep -oE 'of [0-9]+ results' "$workdir/$1.body" | head -1 | grep -oE '[0-9]+'; }
# "sorted" is the unfiltered set (a submitted form drops the default status filters); the title filter must narrow it.
if [ -n "$(total sorted)" ] && [ -n "$(total filtered)" ]; then
	if [ "$(total filtered)" -lt "$(total sorted)" ]; then
		echo "   ok   title filter narrows the list: $(total filtered) < $(total sorted) results"
	else
		fail "title filter does not narrow the list: $(total filtered) results vs $(total sorted) unfiltered"
	fi
else
	fail "could not read 'of N results' from the sorted/filtered pages"
fi
if [ -n "${reference[sorted]:-}" ] && [ "${reference[sorted]:-}" = "${reference[sorted-desc]:-x}" ]; then
	fail "sorted and sorted-desc are identical: the sort direction is ignored"
fi

header() { grep -i "^$2:" "$workdir/$1.headers" | tail -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'; }
case "$(header list cache-control)" in
	*no-store*) echo "   ok   HTML Cache-Control: $(header list cache-control)" ;;
	*) fail "list: Cache-Control should contain no-store, got '$(header list cache-control)'" ;;
esac
[ "$(header list x-content-type-options)" = nosniff ] && echo "   ok   X-Content-Type-Options: nosniff" || fail "list: X-Content-Type-Options header missing"
[ -n "$(header list referrer-policy)" ] && echo "   ok   Referrer-Policy: $(header list referrer-policy)" || fail "list: Referrer-Policy header missing"
if [ -n "$(header list strict-transport-security)" ] && [ "${BASE_URL#https://}" = "$BASE_URL" ]; then
	fail "list: HSTS must not be sent over plain HTTP"
fi

# Bulma is an AssetMapper (importmap) package: its compiled, content-hashed URL is in the home page head.
bulma="$(grep -oE '/assets/vendor/bulma/css/bulma\.min-[A-Za-z0-9_-]+\.css' "$workdir/home.body" | head -1)"
if [ -z "$bulma" ]; then
	fail "home: no /assets/vendor/bulma/css/bulma.min-<hash>.css <link> found"
else
	fetch bulma "$bulma"
	if [ "$(cat "$workdir/bulma.status")" != 200 ]; then
		fail "bulma asset $bulma: HTTP $(cat "$workdir/bulma.status")"
	elif ! grep -qF 'bulma.io' "$workdir/bulma.body"; then
		fail "bulma asset $bulma: not Bulma's stylesheet"
	else
		echo "   ok   asset $bulma"
		case "$(header bulma cache-control)" in
			*immutable*) echo "   ok   asset Cache-Control: $(header bulma cache-control)" ;;
			*) fail "asset: Cache-Control should be immutable, got '$(header bulma cache-control)'" ;;
		esac
	fi
fi
# The app overrides stylesheet must be linked after Bulma's.
if ! awk '/bulma\.min-/ {b=NR} /styles\/app-/ {a=NR} END {exit !(b && a && b < a)}' "$workdir/home.body"; then
	fail "home: app.css must be linked after Bulma"
fi

echo "== Rounds 2..$SMOKE_ROUNDS: replaying the sequence, every page must stay identical"
names=()
for entry in "${PAGES[@]}"; do names+=("${entry%%|*}"); done
declare -A path_of
for entry in "${PAGES[@]}"; do
	IFS='|' read -r name path _ <<<"$entry"
	path_of[$name]="$path"
done
for ((round = 2; round <= SMOKE_ROUNDS; round++)); do
	if ((round % 2 == 0)); then order=("${names[@]}"); else
		order=()
		for ((i = ${#names[@]} - 1; i >= 0; i--)); do order+=("${names[$i]}"); done
	fi
	for name in "${order[@]}"; do
		[ -n "${reference[$name]:-}" ] || continue # already failed in round 1
		fetch "$name" "${path_of[$name]}"
		status="$(cat "$workdir/$name.status")"
		if [ "$status" != 200 ]; then
			fail "round $round, $name (${path_of[$name]}): HTTP $status (state leak?)"
		elif [ "$(sha256 <"$workdir/$name.body")" != "${reference[$name]}" ]; then
			fail "round $round, $name (${path_of[$name]}): page differs from round 1 (state leak?)"
		fi
	done
	echo "   round $round done"
done

echo
if [ "$failures" -eq 0 ]; then
	echo "SMOKE OK: ${#PAGES[@]} pages x $SMOKE_ROUNDS rounds against $BASE_URL"
	exit 0
fi
echo "SMOKE FAILED: $failures failure(s) against $BASE_URL" >&2
exit 1
