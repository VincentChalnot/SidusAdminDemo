#!/bin/sh
# Entrypoint of the production image.
#
# When the container starts the app server (CMD = "frankenphp ..."), it first
#   1. checks the mandatory runtime configuration is present,
#   2. waits for the database,
#   3. runs the Doctrine migrations   (opt out with RUN_MIGRATIONS=0),
#   4. loads the demo fixtures        (opt out with LOAD_FIXTURES=0; a no-op if data already exists),
# then `exec`s the server so that it is PID 1 and receives SIGTERM/SIGINT directly (graceful shutdown).
# Any other command (`docker run ... php bin/console ...`, `sh`) is executed untouched.
#
# POSIX sh on purpose: the final image has no bash guarantee.
set -eu

# "0", "false", "no", "off" disable a switch; unset/empty/anything else enables it (default: enabled).
is_enabled() {
	case "$(printf '%s' "${1:-1}" | tr '[:upper:]' '[:lower:]')" in
		0 | false | no | off) return 1 ;;
		*) return 0 ;;
	esac
}

if [ "${1:-}" = 'frankenphp' ]; then
	# Be killable while we are still preparing (PID 1 ignores signals without a handler).
	trap 'echo "Interrupted during startup" >&2; exit 143' TERM INT

	# Fail fast and loud: without these, every request would end in a 500 and the logs would not say why.
	missing=''
	[ -n "${APP_SECRET:-}" ] || missing="$missing APP_SECRET"
	[ -n "${DATABASE_URL:-}" ] || missing="$missing DATABASE_URL"
	[ -n "${TRUSTED_PROXIES+x}" ] || missing="$missing TRUSTED_PROXIES(may be empty)"
	if [ -n "$missing" ]; then
		echo "Missing required environment variable(s):$missing" >&2
		exit 1
	fi

	if is_enabled "${RUN_MIGRATIONS:-1}" || is_enabled "${LOAD_FIXTURES:-1}"; then
		echo "Waiting for the database to be reachable..."
		attempts=60
		until output=$(php bin/console dbal:run-sql 'SELECT 1' 2>&1); do
			attempts=$((attempts - 1))
			if [ "$attempts" -le 0 ]; then
				echo "Database never became reachable, aborting. Last error:" >&2
				echo "$output" >&2
				exit 1
			fi
			sleep 1
		done
		echo "Database is reachable"
	fi

	if is_enabled "${RUN_MIGRATIONS:-1}"; then
		echo "Running database migrations (RUN_MIGRATIONS=0 to skip)..."
		php bin/console doctrine:migrations:migrate --no-interaction
	else
		echo "Skipping database migrations (RUN_MIGRATIONS=0)"
	fi

	if is_enabled "${LOAD_FIXTURES:-1}"; then
		echo "Loading demo fixtures (LOAD_FIXTURES=0 to skip; a no-op if data is already present)..."
		php bin/console app:fixtures:init
	else
		echo "Skipping demo fixtures (LOAD_FIXTURES=0)"
	fi

	# exec replaces this shell: drop our traps, the server installs its own signal handling.
	trap - TERM INT
fi

exec "$@"
