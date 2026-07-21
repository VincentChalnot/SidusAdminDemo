#!/bin/sh
set -e

# Only run migrations/fixtures when actually booting the app server (the
# frankenphp_prod CMD), not for one-off `php bin/console ...` exec's into a
# running or ephemeral container - those should run whatever was asked for,
# nothing more.
if [ "$1" = 'frankenphp' ]; then
	echo "Waiting for the database to be reachable..."
	attempts=60
	until [ "$attempts" -eq 0 ] || php bin/console dbal:run-sql 'SELECT 1' >/dev/null 2>&1; do
		attempts=$((attempts - 1))
		sleep 1
	done
	if [ "$attempts" -eq 0 ]; then
		echo "Database never became reachable, aborting" >&2
		exit 1
	fi
	echo "Database is reachable"

	echo "Running database migrations..."
	php bin/console doctrine:migrations:migrate --no-interaction

	echo "Loading demo fixtures (app:fixtures:init is a no-op if data is already present)..."
	php bin/console app:fixtures:init
fi

exec "$@"
