#!/usr/bin/env bash
#
# Proves, from FrankenPHP's own Prometheus metrics, whether a running container really serves requests in worker mode
# (do not trust the environment variable: ask the server).
#
#   scripts/assert-worker.sh <container> on|off [recycling]
#
#   on          at least one worker thread exists and has served requests (frankenphp_total_workers,
#               frankenphp_worker_request_count)
#   off         no worker exists (classic mode)
#   recycling   (with "on") workers were also restarted: at least one frankenphp_worker_restarts, i.e. MAX_REQUESTS
#               (FRANKENPHP_LOOP_MAX) reached and the worker script started again
#
# The container must have been started with CADDY_GLOBAL_OPTIONS=metrics (it exposes frankenphp_* on the Caddy admin
# endpoint, localhost:2019, which is never published). Send some requests before calling this script.

set -uo pipefail

container="${1:?usage: assert-worker.sh <container> on|off [recycling]}"
expected="${2:?usage: assert-worker.sh <container> on|off [recycling]}"
recycling="${3:-}"

metrics="$(docker exec "$container" curl -fsS http://localhost:2019/metrics 2>&1)" || {
	echo "FAIL: cannot read the metrics of $container (started without CADDY_GLOBAL_OPTIONS=metrics?): $metrics" >&2
	exit 1
}
# Sum of a metric over its labels.
sum() { printf '%s\n' "$metrics" | awk -v m="$1" '$1 ~ "^"m"({|$)" {s += $NF} END {print s + 0}'; }

workers="$(sum frankenphp_total_workers)"
requests="$(sum frankenphp_worker_request_count)"
restarts="$(sum frankenphp_worker_restarts)"
threads="$(sum frankenphp_total_threads)"
echo "frankenphp: threads=$threads worker_threads=$workers worker_requests=$requests worker_restarts=$restarts"

case "$expected" in
	on)
		if [ "$workers" -lt 1 ] || [ "$requests" -lt 1 ]; then
			echo "FAIL: worker mode expected, but worker_threads=$workers worker_requests=$requests" >&2
			exit 1
		fi
		if [ "$recycling" = recycling ] && [ "$restarts" -lt 1 ]; then
			echo "FAIL: worker recycling expected (MAX_REQUESTS), but worker_restarts=$restarts" >&2
			exit 1
		fi
		echo "OK: worker mode is active ($workers worker threads served $requests requests${recycling:+, $restarts restarts})"
		;;
	off)
		if [ "$workers" -ne 0 ] || [ "$requests" -ne 0 ]; then
			echo "FAIL: classic mode expected, but worker_threads=$workers worker_requests=$requests" >&2
			exit 1
		fi
		echo "OK: no worker, classic mode"
		;;
	*)
		echo "usage: assert-worker.sh <container> on|off [recycling]" >&2
		exit 2
		;;
esac
