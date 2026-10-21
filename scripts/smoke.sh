#!/usr/bin/env bash
#
# HTTP smoke test of a running Sidus/AdminDemo instance (the production image, or any other deployment).
#
#   scripts/smoke.sh [BASE_URL]            default: http://127.0.0.1:8080
#
# Environment:
#   SMOKE_WAIT      seconds to wait for /healthz to answer 200 before giving up (default 180)
#   SMOKE_ROUNDS    how many times the whole page sequence is replayed (default 4)
#   SMOKE_LOOPS     iterations of the alternating-filters loop (default 20)
#   SMOKE_PARALLEL  concurrent requests of the parallel check (default 8, per variant)
#
# What it does:
#   1. waits for /healthz (200 + body "ok"),
#   2. requests home, the News list, filtered / sorted / paged lists, invalid inputs (a violation: still 200) and a
#      compiled asset, and asserts status 200 + expected markers + the cache/security headers,
#   3. checks the datagrid itself: sort buttons, the sort order, the pager links that must keep filters and sort,
#   4. replays the same sequence SMOKE_ROUNDS times (forward, then reversed, ...): every page must be byte-for-byte
#      identical to its first rendering. The data does not change, so any difference means that state leaked from one
#      request into another (typical of FrankenPHP worker mode with stateful services),
#   5. requests different filters alternately in a loop, then many of them concurrently: a response must never show
#      the filter, the rows or the sort of another request,
#   6. runs the whole create / edit / delete flow with a session (CSRF tokens, flash message) and checks that the
#      flash message is not shown to another session and that the lists are unchanged afterwards.
#
# Exit code: 0 when everything passed, 1 otherwise.
#
# The request shape follows Sidus/DataGridBundle 5: ?filters[<name>]=...&sort=[-]<column>&page=N. Adapt the PAGES
# and VARIANTS tables if the bundles change it.

set -uo pipefail

BASE_URL="${1:-${BASE_URL:-http://127.0.0.1:8080}}"
BASE_URL="${BASE_URL%/}"
SMOKE_WAIT="${SMOKE_WAIT:-180}"
SMOKE_ROUNDS="${SMOKE_ROUNDS:-4}"
SMOKE_LOOPS="${SMOKE_LOOPS:-20}"
SMOKE_PARALLEL="${SMOKE_PARALLEL:-8}"

failures=0
fail() {
	echo "  FAIL: $*" >&2
	failures=$((failures + 1))
}
ok() { echo "   ok   $*"; }

sha256() {
	if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi
}

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

# name|path|marker that must appear in the body (fixed string)
PAGES=(
	'home|/|Sidus/AdminBundle Demo'
	'list|/news/|Displaying'
	'filtered|/news/?filters[title]=qui|value="qui"'
	'filtered-status|/news/?filters[publicationStatus][]=draft|Displaying'
	'sorted|/news/?sort=title|aria-sort="ascending"'
	'sorted-desc|/news/?sort=-title|aria-sort="descending"'
	'sorted-author|/news/?sort=author|aria-sort="ascending"'
	'paged|/news/?page=2|aria-current="page"'
	'filtered-sorted-paged|/news/?filters[title]=qui&sort=-publicationDate&page=2|Displaying'
	'invalid-page|/news/?page=abc|Expected a positive integer'
	'invalid-page-zero|/news/?page=0|Displaying'
	'invalid-sort|/news/?sort=nothing|Displaying'
	'invalid-date|/news/?filters[publicationDate][from]=not-a-date|Displaying'
	'create-form|/news/create|form_News_create'
	'healthz|/healthz|ok'
)

# Requests that differ from each other in their filters, sort or page (loop and parallel checks).
VARIANTS=(
	'/news/?filters[title]=qui'
	'/news/?filters[title]=aut&sort=title'
	'/news/?filters[author]=a&page=2'
	'/news/?sort=-author&page=3'
	'/news/?filters[publicationStatus]=&sort=id'
	'/news/?filters[publicationStatus][]=draft&filters[title]=e&sort=-title'
)

# fetch <name> <path> [curl options...]: status in $workdir/<name>.status, headers in .headers, body in .body
fetch() {
	local name="$1" path="$2"
	shift 2
	curl --globoff --silent --show-error --max-time 30 \
		--output "$workdir/$name.body" --dump-header "$workdir/$name.headers" \
		--write-out '%{http_code}' "$@" "$BASE_URL$path" >"$workdir/$name.status" 2>"$workdir/$name.curlerr" ||
		echo 000 >"$workdir/$name.status"
}
status_of() { cat "$workdir/$1.status"; }
header() { grep -i "^$2:" "$workdir/$1.headers" | tail -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'; }
total() { grep -oE 'of [0-9]+ results' "$workdir/$1.body" | head -1 | grep -oE '[0-9]+'; }
titles() { grep -oE 'class="has-text-weight-semibold">[^<]*' "$workdir/$1.body" | sed 's/^[^>]*>//'; }
title_input() { grep -oE '<input[^>]*name="filters\[title\]"[^>]*>' "$workdir/$1.body" | grep -oE 'value="[^"]*"' | head -1; }
urldecode() { local s="${1//+/ }"; printf '%b' "${s//%/\\x}"; }

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
declare -A reference path_of
names=()
for entry in "${PAGES[@]}"; do
	IFS='|' read -r name path marker <<<"$entry"
	names+=("$name")
	path_of[$name]="$path"
	fetch "$name" "$path"
	status="$(status_of "$name")"
	if [ "$status" != 200 ]; then
		fail "$name ($path): HTTP $status $(cat "$workdir/$name.curlerr")"
		continue
	fi
	if ! grep -qF -- "$marker" "$workdir/$name.body"; then
		fail "$name ($path): marker not found in the body: $marker"
		continue
	fi
	reference[$name]="$(sha256 <"$workdir/$name.body")"
	ok "$name  $path"
done

echo "== Round 1: the datagrid"
# Rows and pager.
if [ -s "$workdir/list.body" ]; then
	rows="$(grep -c 'class="sidus-datagrid-row"' "$workdir/list.body" || true)"
	[ "$rows" -ge 1 ] && ok "list has $rows rows" || fail "list: no rows"
	grep -q 'class="pagination' "$workdir/list.body" && ok "list has a pager" || fail "list: no pager"
fi
# The title filter must narrow the default list; the input keeps what was asked.
if [ -n "$(total list)" ] && [ -n "$(total filtered)" ]; then
	if [ "$(total filtered)" -lt "$(total list)" ]; then
		ok "title filter narrows the list: $(total filtered) < $(total list) results"
	else
		fail "title filter does not narrow the list: $(total filtered) results vs $(total list)"
	fi
else
	fail "could not read 'of N results' from the list/filtered pages"
fi
[ "$(title_input filtered)" = 'value="qui"' ] && ok "the title input keeps the filter" || fail "filtered: the title input does not keep 'qui'"
[ -z "$(title_input list)" ] && ok "the default list has no title filter" || fail "list: unexpected title filter $(title_input list)"
# Sort buttons: one submit button per sortable column (id, title, publicationDate, publicationStatus, author).
buttons="$(grep -cE '<button type="submit" name="filters\[sort\]"' "$workdir/list.body" || true)"
[ "$buttons" -eq 5 ] && ok "5 sort buttons" || fail "list: expected 5 sort buttons, found $buttons"
# The default sort is the publication date, newest first.
grep -qE '<th aria-sort="descending">\s*<button[^>]*value="publicationDate"' "$workdir/list.body" && ok "default sort: publicationDate descending" || fail "list: the default sort (publicationDate desc) is not marked"
# Sort order: the first 4 characters of the titles (lowercased, compared bytewise: C locale, like the database collation
# for letters and spaces) never decrease (resp. increase) down the page.
for pair in 'sorted:' 'sorted-desc:r'; do
	name="${pair%%:*}"
	rev="${pair#*:}"
	[ -n "${reference[$name]:-}" ] || continue
	prefixes="$(titles "$name" | cut -c1-4 | tr '[:upper:]' '[:lower:]')"
	if [ -n "$prefixes" ] && printf '%s\n' "$prefixes" | LC_ALL=C sort -c"$rev" 2>/dev/null; then
		ok "$name: titles are ordered ($(printf '%s' "$prefixes" | tr '\n' ',' | cut -c1-60)...)"
	else
		fail "$name: titles are not ordered: $(printf '%s' "$prefixes" | tr '\n' ',')"
	fi
done
if [ -n "${reference[sorted]:-}" ] && [ "${reference[sorted]:-}" = "${reference[sorted-desc]:-x}" ]; then
	fail "sorted and sorted-desc are identical: the sort direction is ignored"
fi
# The pager of a filtered, sorted page keeps the filter and the sort in every link.
if [ -s "$workdir/filtered-sorted-paged.body" ]; then
	links="$(grep -oE 'class="pagination-[a-z]+[^"]*"[^>]*href="[^"]*"' "$workdir/filtered-sorted-paged.body" | grep -oE 'href="[^"]*"' | sed 's/^href="//;s/"$//;s/&amp;/\&/g')"
	count=0
	bad=0
	while IFS= read -r link; do
		[ -n "$link" ] || continue
		count=$((count + 1))
		decoded="$(urldecode "$link")"
		case "$decoded" in
			*'filters[title]=qui'*'sort=-publicationDate'* | *'sort=-publicationDate'*'filters[title]=qui'*) ;;
			*) bad=$((bad + 1)); echo "     pager link without filter/sort: $decoded" >&2 ;;
		esac
	done <<<"$links"
	if [ "$count" -ge 3 ] && [ "$bad" -eq 0 ]; then ok "$count pager links keep filters[title] and sort"; else fail "pager links: $count found, $bad lose the filters or the sort"; fi
	# Follow "next": still 200, still filtered, the next page is current.
	next="$(grep -oE 'class="pagination-next"[^>]*href="[^"]*"' "$workdir/filtered-sorted-paged.body" | grep -oE 'href="[^"]*"' | sed 's/^href="//;s/"$//;s/&amp;/\&/g')"
	if [ -n "$next" ]; then
		fetch next "$next"
		if [ "$(status_of next)" = 200 ] && grep -q 'aria-current="page" aria-label="3"' "$workdir/next.body" && [ "$(title_input next)" = 'value="qui"' ]; then
			ok "the pager's next link leads to page 3, still filtered"
		else
			fail "the pager's next link ($next) does not lead to page 3 of the same filter"
		fi
	else
		fail "filtered-sorted-paged: no next link"
	fi
fi
# A violation is shown, and the list is the one of the default.
if [ -s "$workdir/invalid-page.body" ]; then
	[ "$(total invalid-page)" = "$(total list)" ] && ok "page=abc: violation shown, the list is the default one" || fail "page=abc: the list differs from the default"
fi

echo "== Round 1: headers and assets"
case "$(header list cache-control)" in
	*no-store*) ok "HTML Cache-Control: $(header list cache-control)" ;;
	*) fail "list: Cache-Control should contain no-store, got '$(header list cache-control)'" ;;
esac
[ "$(header list x-content-type-options)" = nosniff ] && ok "X-Content-Type-Options: nosniff" || fail "list: X-Content-Type-Options header missing"
[ -n "$(header list referrer-policy)" ] && ok "Referrer-Policy: $(header list referrer-policy)" || fail "list: Referrer-Policy header missing"
if [ -n "$(header list strict-transport-security)" ] && [ "${BASE_URL#https://}" = "$BASE_URL" ]; then
	fail "list: HSTS must not be sent over plain HTTP"
fi
# Bulma is an AssetMapper (importmap) package: its compiled, content-hashed URL is in the home page head.
bulma="$(grep -oE '/assets/vendor/bulma/css/bulma\.min-[A-Za-z0-9_-]+\.css' "$workdir/home.body" | head -1)"
if [ -z "$bulma" ]; then
	fail "home: no /assets/vendor/bulma/css/bulma.min-<hash>.css <link> found"
else
	fetch bulma "$bulma"
	if [ "$(status_of bulma)" != 200 ]; then
		fail "bulma asset $bulma: HTTP $(status_of bulma)"
	elif ! grep -qF 'bulma.io' "$workdir/bulma.body"; then
		fail "bulma asset $bulma: not Bulma's stylesheet"
	else
		ok "asset $bulma"
		case "$(header bulma cache-control)" in
			*immutable*) ok "asset Cache-Control: $(header bulma cache-control)" ;;
			*) fail "asset: Cache-Control should be immutable, got '$(header bulma cache-control)'" ;;
		esac
	fi
fi
# The app overrides stylesheet must be linked after Bulma's.
if ! awk '/bulma\.min-/ {b=NR} /styles\/app-/ {a=NR} END {exit !(b && a && b < a)}' "$workdir/home.body"; then
	fail "home: app.css must be linked after Bulma"
fi

echo "== Rounds 2..$SMOKE_ROUNDS: replaying the sequence, every page must stay identical"
for ((round = 2; round <= SMOKE_ROUNDS; round++)); do
	if ((round % 2 == 0)); then order=("${names[@]}"); else
		order=()
		for ((i = ${#names[@]} - 1; i >= 0; i--)); do order+=("${names[$i]}"); done
	fi
	for name in "${order[@]}"; do
		# The create form carries a CSRF token that changes on every request: not byte-comparable.
		[ "$name" = create-form ] && continue
		[ -n "${reference[$name]:-}" ] || continue # already failed in round 1
		fetch "$name" "${path_of[$name]}"
		status="$(status_of "$name")"
		if [ "$status" != 200 ]; then
			fail "round $round, $name (${path_of[$name]}): HTTP $status (state leak?)"
		elif [ "$(sha256 <"$workdir/$name.body")" != "${reference[$name]}" ]; then
			fail "round $round, $name (${path_of[$name]}): page differs from round 1 (state leak?)"
		fi
	done
	echo "   round $round done"
done

echo "== Alternating filters, sorts and pages: ${#VARIANTS[@]} variants x $SMOKE_LOOPS iterations"
declare -A vref
for i in "${!VARIANTS[@]}"; do
	fetch "variant$i" "${VARIANTS[$i]}"
	if [ "$(status_of "variant$i")" != 200 ]; then
		fail "variant ${VARIANTS[$i]}: HTTP $(status_of "variant$i")"
		continue
	fi
	vref[$i]="$(sha256 <"$workdir/variant$i.body")"
done
# The variants really differ from each other (otherwise the loop proves nothing).
distinct="$(for i in "${!vref[@]}"; do echo "${vref[$i]}"; done | sort -u | wc -l)"
[ "$distinct" -eq "${#VARIANTS[@]}" ] && ok "the ${#VARIANTS[@]} variants render ${distinct} different pages" || fail "the variants render only $distinct different pages"
leaks=0
for ((n = 1; n <= SMOKE_LOOPS; n++)); do
	for i in "${!VARIANTS[@]}"; do
		# Alternate the order so that a variant follows every other one.
		j=$(((i + n) % ${#VARIANTS[@]}))
		[ -n "${vref[$j]:-}" ] || continue
		fetch loop "${VARIANTS[$j]}"
		if [ "$(status_of loop)" != 200 ] || [ "$(sha256 <"$workdir/loop.body")" != "${vref[$j]}" ]; then
			leaks=$((leaks + 1))
			fail "iteration $n, ${VARIANTS[$j]}: HTTP $(status_of loop), or a page that is not the one of this request (leak)"
		fi
	done
done
[ "$leaks" -eq 0 ] && ok "$((SMOKE_LOOPS * ${#VARIANTS[@]})) sequential requests: every page is the one of its own request"

echo "== Concurrent requests: $SMOKE_PARALLEL x ${#VARIANTS[@]} at once"
pids=()
for ((n = 0; n < SMOKE_PARALLEL; n++)); do
	for i in "${!VARIANTS[@]}"; do
		(
			curl --globoff --silent --max-time 60 --output "$workdir/par.$i.$n.body" --write-out '%{http_code}' "$BASE_URL${VARIANTS[$i]}" >"$workdir/par.$i.$n.status" 2>/dev/null || echo 000 >"$workdir/par.$i.$n.status"
		) &
		pids+=($!)
		# At most 16 curl processes at a time.
		if [ "${#pids[@]}" -ge 16 ]; then wait "${pids[0]}" 2>/dev/null; pids=("${pids[@]:1}"); fi
	done
done
wait
mixed=0
for ((n = 0; n < SMOKE_PARALLEL; n++)); do
	for i in "${!VARIANTS[@]}"; do
		[ -n "${vref[$i]:-}" ] || continue
		if [ "$(cat "$workdir/par.$i.$n.status")" != 200 ] || [ "$(sha256 <"$workdir/par.$i.$n.body")" != "${vref[$i]}" ]; then
			mixed=$((mixed + 1))
			fail "concurrent request ${VARIANTS[$i]} (#$n): HTTP $(cat "$workdir/par.$i.$n.status"), or the page of another request"
		fi
	done
done
[ "$mixed" -eq 0 ] && ok "$((SMOKE_PARALLEL * ${#VARIANTS[@]})) concurrent requests: no page shows another request's data"

echo "== Create / edit / delete (session, CSRF, flash message)"
jar="$workdir/cookies"
stamp="smoke-$(date +%s)-$$"
token_of() { grep -oE '<input[^>]*_token[^>]*>' "$workdir/$1.body" | head -1 | grep -oE 'value="[^"]*"' | sed 's/^value="//;s/"$//'; }
location_of() { header "$1" location; }

fetch create_get /news/create -b "$jar" -c "$jar"
token="$(token_of create_get)"
if [ "$(status_of create_get)" != 200 ] || [ -z "$token" ]; then
	fail "create form: HTTP $(status_of create_get), CSRF token '${token:-missing}'"
else
	fetch create_post /news/create -b "$jar" -c "$jar" -X POST \
		--data-urlencode "form_News_create[title]=$stamp" \
		--data-urlencode "form_News_create[content]=Created by the smoke test." \
		--data-urlencode "form_News_create[publicationDate]=2026-03-14T09:30" \
		--data-urlencode "form_News_create[publicationStatus]=published" \
		--data-urlencode "form_News_create[_token]=$token"
	loc="$(location_of create_post)"
	id="$(printf '%s' "$loc" | grep -oE '[0-9]+$' || true)"
	if [ "$(status_of create_post)" != 302 ] || [ -z "$id" ]; then
		fail "create: expected a redirection to the new news, got HTTP $(status_of create_post) Location '$loc'"
	else
		ok "created news #$id (redirected to $loc)"
		fetch after_create "$loc" -b "$jar" -c "$jar"
		if [ "$(status_of after_create)" = 200 ] && grep -q 'notification is-success' "$workdir/after_create.body"; then
			ok "the flash message is shown once after the save"
		else
			fail "after create: HTTP $(status_of after_create), no success flash message"
		fi
		# Another visitor (no cookie) must never see that flash message, nor a second display for the same session.
		fetch other_session /news/ 
		if grep -q 'notification is-success' "$workdir/other_session.body"; then fail "the flash message leaked into another session"; else ok "no flash message in another session"; fi
		fetch same_session /news/ -b "$jar" -c "$jar"
		if grep -q 'notification is-success' "$workdir/same_session.body"; then fail "the flash message is displayed twice"; else ok "the flash message is not displayed twice"; fi

		# The new news is listed...
		fetch find_new "/news/?filters[title]=$stamp&filters[publicationStatus]="
		[ "$(titles find_new)" = "$stamp" ] && ok "the new news is listed (and only it)" || fail "the new news is not found by its title: '$(titles find_new)'"

		# ... edit it.
		fetch edit_get "/news/edit/$id" -b "$jar" -c "$jar"
		token="$(token_of edit_get)"
		if [ "$(status_of edit_get)" != 200 ] || [ -z "$token" ]; then
			fail "edit form: HTTP $(status_of edit_get), CSRF token '${token:-missing}'"
		else
			fetch edit_post "/news/edit/$id" -b "$jar" -c "$jar" -X POST \
				--data-urlencode "form_News_edit[title]=$stamp-edited" \
				--data-urlencode "form_News_edit[content]=Edited by the smoke test." \
				--data-urlencode "form_News_edit[publicationDate]=2026-03-14T09:30" \
				--data-urlencode "form_News_edit[publicationStatus]=published" \
				--data-urlencode "form_News_edit[_token]=$token"
			if [ "$(status_of edit_post)" = 302 ]; then
				fetch find_edited "/news/?filters[title]=$stamp&filters[publicationStatus]="
				[ "$(titles find_edited)" = "$stamp-edited" ] && ok "the edit is visible in the next request" || fail "edit: the list shows '$(titles find_edited)' instead of '$stamp-edited'"
			else
				fail "edit: expected a redirection, got HTTP $(status_of edit_post)"
			fi
		fi

		# ... and delete it.
		fetch delete_get "/news/delete/$id" -b "$jar" -c "$jar"
		token="$(token_of delete_get)"
		if [ "$(status_of delete_get)" != 200 ] || [ -z "$token" ]; then
			fail "delete form: HTTP $(status_of delete_get), CSRF token '${token:-missing}'"
		else
			fetch delete_post "/news/delete/$id" -b "$jar" -c "$jar" -X POST --data-urlencode "form_News_delete[_token]=$token"
			fetch read_gone "/news/read/$id"
			if [ "$(status_of delete_post)" = 302 ] && [ "$(status_of read_gone)" = 404 ]; then
				ok "deleted: the news is gone (404)"
			else
				fail "delete: HTTP $(status_of delete_post), then the read page answers $(status_of read_gone) instead of 404"
			fi
		fi
	fi
fi
# The write flow left no trace in the lists.
for name in list filtered-sorted-paged sorted; do
	[ -n "${reference[$name]:-}" ] || continue
	fetch "$name" "${path_of[$name]}"
	[ "$(status_of "$name")" = 200 ] && [ "$(sha256 <"$workdir/$name.body")" = "${reference[$name]}" ] || fail "$name differs from its first rendering after the create/edit/delete flow"
done
ok "the lists are the same after the flow"

echo
if [ "$failures" -eq 0 ]; then
	echo "SMOKE OK: ${#PAGES[@]} pages x $SMOKE_ROUNDS rounds, ${#VARIANTS[@]} variants x $SMOKE_LOOPS sequential + $SMOKE_PARALLEL concurrent, CRUD flow, against $BASE_URL"
	exit 0
fi
echo "SMOKE FAILED: $failures failure(s) against $BASE_URL" >&2
exit 1
