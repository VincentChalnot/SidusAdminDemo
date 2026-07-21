#syntax=docker/dockerfile:1

# Versions
ARG PHP_VERSION=8.4
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS frankenphp_upstream

# https://docs.docker.com/build/building/multi-stage/#stop-at-a-specific-build-stage
# https://docs.docker.com/reference/compose-file/build/#target
#
# compose.yaml (local dev) builds and uses only the `frankenphp_dev` target;
# the GitHub Actions workflow (.github/workflows/docker-image.yml) builds and
# publishes the `frankenphp_prod` target below as the deployable image. There
# is deliberately no prod compose file - see README.md.

# Base FrankenPHP image: OS + PHP extensions shared by every later stage.
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

# Persistent OS deps + PHP extensions required by this app:
#   - pdo_mysql: Doctrine DBAL/ORM (doctrine/orm ^3, doctrine/doctrine-bundle) driver for MariaDB
#   - intl, zip: required by composer.json ("ext-*" requirements pulled in by Symfony/Doctrine)
#   - opcache, apcu: bytecode + userland caching used by Symfony in every env
#   - file, git: used by Symfony's MIME guesser and by Composer for VCS packages
RUN apt-get update && apt-get install -y --no-install-recommends \
		file \
		git \
	&& install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		pdo_mysql \
		zip \
	&& rm -rf /var/lib/apt/lists/*

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_INI_SCAN_DIR=":${PHP_INI_DIR}/app.conf.d"

COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# Dev FrankenPHP image: dev php.ini, optional Xdebug, live code reload.
FROM frankenphp_base AS frankenphp_dev

# Opt-in Xdebug: docker compose build --build-arg WITH_XDEBUG=1 php
ARG WITH_XDEBUG=0

ENV APP_ENV=dev
ENV XDEBUG_MODE=off

RUN mv "${PHP_INI_DIR}/php.ini-development" "${PHP_INI_DIR}/php.ini" \
	&& if [ "${WITH_XDEBUG}" = "1" ]; then install-php-extensions xdebug; fi

COPY --link frankenphp/conf.d/app.dev.ini ${PHP_INI_DIR}/app.conf.d/app.dev.ini

# --watch reloads the FrankenPHP worker/server on source changes for local dev.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch"]

# Prod FrankenPHP image: composer install --no-dev with an optimized,
# classmap-authoritative autoloader, and the Symfony prod cache (container,
# routes, Twig, `assets:install`/`importmap:install`/`asset-map:compile`)
# pre-warmed into the image itself so containers boot instantly with no
# first-request compile cost and no writable source volume. Migrations and
# demo fixtures run at container start (docker-entrypoint.sh) since they
# need a real, reachable database that doesn't exist at build time.
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod

RUN mv "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"

COPY --link frankenphp/conf.d/app.prod.ini ${PHP_INI_DIR}/app.conf.d/app.prod.ini

# Only what's actually read at runtime - keeps the image lean and the layer
# cache (composer install below) invalidated only by dependency changes.
COPY --link composer.json composer.lock symfony.lock ./
COPY --link bin bin
COPY --link config config
COPY --link public public
COPY --link src src
COPY --link templates templates
COPY --link translations translations
COPY --link migrations migrations
COPY --link importmap.php importmap.php
COPY --link assets assets

# The APP_SECRET/DATABASE_URL/TRUSTED_PROXIES values below are BUILD-TIME-ONLY
# placeholders (same defaults compose.yaml already uses for dev) needed to
# compile the container/routes/Twig cache - none of them touch a real
# database. Every one is overridden by the actual runtime value the
# deployment environment injects as a real process environment variable,
# which always takes precedence over an image ENV default.
RUN --mount=type=cache,target=/root/.composer/cache,sharing=locked \
	APP_ENV=prod \
	APP_SECRET=BuildTimePlaceholderNotARealSecret \
	DATABASE_URL="mysql://symfony:symfony@127.0.0.1:3306/symfony?serverVersion=mariadb-11.4.0" \
	TRUSTED_PROXIES=127.0.0.1 \
	composer install --no-dev --no-progress --no-interaction --optimize-autoloader --classmap-authoritative

# composer install's auto-scripts already ran importmap:install (downloads
# the vendored JS packages); asset-map:compile is the separate step that
# actually compiles/versions everything AssetMapper serves (app.js, Bulma,
# FontAwesome, importmap entries) into public/assets/ for prod - AssetMapper
# only resolves assets on the fly in dev, prod needs this pre-built.
RUN APP_ENV=prod \
	APP_SECRET=BuildTimePlaceholderNotARealSecret \
	DATABASE_URL="mysql://symfony:symfony@127.0.0.1:3306/symfony?serverVersion=mariadb-11.4.0" \
	TRUSTED_PROXIES=127.0.0.1 \
	php bin/console asset-map:compile

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

# Waits for the database, runs migrations, then loads demo fixtures
# (idempotent - see InitFixturesCommand) before handing off to the CMD below.
ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
