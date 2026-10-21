#!/usr/bin/env bash
#
# Build the production image and smoke-test it locally against a throwaway MariaDB container.
# Plain `docker` only (no compose): it creates a user-defined network, a MariaDB container and the app
# container, runs scripts/smoke.sh against the published port, then removes the containers, the anonymous
# MariaDB volume and the network (the images are kept, so the next run is fast).
#
#   scripts/prod-smoke.sh              (or: make prod-smoke)
#
# Environment (all optional):
#   IMAGE                   image tag to build/use              (default sidusadmindemo:prod-smoke)
#   BUILD=0                 do not (re)build, use IMAGE as is   (default 1)
#   PORT                    host port on 127.0.0.1               (default 18080)
#   MARIADB_VERSION         mariadb image tag                    (default 11.4)
#   FRANKENPHP_WORKER_MODE  on|off passed to the container       (default off; "on" needs worker-safe bundles)
#   FRANKENPHP_WORKER_NUM   worker threads passed to the container
#   SMOKE_ROUNDS            see scripts/smoke.sh
#   KEEP=1                  leave the containers/network running (for debugging); remove them yourself:
#                           docker rm -f -v <app> <db> && docker network rm <network>

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

IMAGE="${IMAGE:-sidusadmindemo:prod-smoke}"
BUILD="${BUILD:-1}"
PORT="${PORT:-18080}"
MARIADB_VERSION="${MARIADB_VERSION:-11.4}"
KEEP="${KEEP:-0}"

random_hex() { od -An -N16 -tx1 /dev/urandom | tr -d ' \n'; }

id="$(random_hex | cut -c1-8)"
NETWORK="sidusadmindemo-smoke-$id"
DB="sidusadmindemo-smoke-db-$id"
APP="sidusadmindemo-smoke-app-$id"
DB_PASSWORD="$(random_hex)"

cleanup() {
	status=$?
	if [ "$status" -ne 0 ] && docker inspect "$APP" >/dev/null 2>&1; then
		echo "---- last application logs ($APP)" >&2
		docker logs --tail 60 "$APP" >&2 || true
	fi
	if [ "$KEEP" = 1 ]; then
		echo "KEEP=1: leaving $APP, $DB and network $NETWORK in place" >&2
		return
	fi
	docker rm -f -v "$APP" "$DB" >/dev/null 2>&1 || true
	docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

if [ "$BUILD" != 0 ]; then
	echo "== Building $IMAGE (target frankenphp_prod)"
	docker build -f frankenphp/Dockerfile --target frankenphp_prod -t "$IMAGE" .
fi

echo "== Starting throwaway MariaDB $MARIADB_VERSION on network $NETWORK"
docker network create "$NETWORK" >/dev/null
docker run -d --name "$DB" --network "$NETWORK" \
	-e MARIADB_DATABASE=symfony -e MARIADB_USER=symfony -e MARIADB_PASSWORD="$DB_PASSWORD" \
	-e MARIADB_RANDOM_ROOT_PASSWORD=1 \
	"mariadb:$MARIADB_VERSION" >/dev/null

echo "== Starting $IMAGE (published on 127.0.0.1:$PORT)"
docker run -d --name "$APP" --network "$NETWORK" -p "127.0.0.1:$PORT:80" \
	-e APP_SECRET="$(random_hex)" \
	-e DATABASE_URL="mysql://symfony:$DB_PASSWORD@$DB:3306/symfony?serverVersion=mariadb-$MARIADB_VERSION.0" \
	-e TRUSTED_PROXIES=127.0.0.1 \
	${FRANKENPHP_WORKER_MODE:+-e FRANKENPHP_WORKER_MODE="$FRANKENPHP_WORKER_MODE"} \
	${FRANKENPHP_WORKER_NUM:+-e FRANKENPHP_WORKER_NUM="$FRANKENPHP_WORKER_NUM"} \
	"$IMAGE" >/dev/null

scripts/smoke.sh "http://127.0.0.1:$PORT"

# The image HEALTHCHECK must agree with the smoke test.
for _ in $(seq 1 30); do
	health="$(docker inspect --format '{{.State.Health.Status}}' "$APP")"
	[ "$health" = healthy ] && break
	sleep 1
done
echo "== Container health: $health ($(docker exec "$APP" id))"
[ "$health" = healthy ]
