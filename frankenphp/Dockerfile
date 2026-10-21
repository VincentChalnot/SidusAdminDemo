#syntax=docker/dockerfile:1

# ==============================================================================================
# Sidus/AdminDemo - FrankenPHP (Caddy embedded) image.
#
#   frankenphp_dev   local development (compose.yaml): bind-mounted sources, composer, git, xdebug option
#   frankenphp_prod  the ONE production image published to GHCR: self-contained, immutable, non-root,
#                    no composer, no git, no compiler, no dev dependency (see the stage comments)
#
# Stage graph:
#   frankenphp_upstream ─> frankenphp_base ─┬─> frankenphp_dev
#                                           ├─> app_builder  (composer install --no-dev, assets, cache)
#                                           └─> prod_rootfs  (base - toolchain + app + runtime user)
#                                                  └─> frankenphp_prod (FROM scratch: flat rootfs)
#
#   docker build -f frankenphp/Dockerfile --target frankenphp_prod -t sidusadmindemo:prod .
# ==============================================================================================

# ---- Pinned build inputs (bump deliberately, keep this block as the single place) -----------
# PHP_VERSION        PHP minor line (compose.yaml passes the same ARG to the dev target).
# FRANKENPHP_VERSION dunglas/frankenphp minor line. 1.12 = FrankenPHP 1.12.x on Caddy 2.11.x;
#                    resolved to FrankenPHP v1.12.6 / PHP 8.4.26 / Debian 13 "trixie" when written.
#                    The "-trixie" suffix pins the Debian release (glibc, NOT Alpine/musl: FrankenPHP
#                    recommends glibc for production performance). Patch releases (security fixes) are
#                    picked up on rebuild; a new minor/major or PHP line is a conscious edit here.
# COMPOSER_VERSION   Composer minor line (official composer/composer:<v>-bin image: just the phar).
ARG PHP_VERSION=8.4
ARG FRANKENPHP_VERSION=1.12
ARG COMPOSER_VERSION=2.8

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-trixie AS frankenphp_upstream
FROM composer/composer:${COMPOSER_VERSION}-bin AS composer_upstream


# ---- Base: runtime OS + the PHP extensions the app needs, shared by every later stage ------
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

# PHP extensions required at runtime:
#   - pdo_mysql: Doctrine DBAL/ORM driver for MariaDB/MySQL
#   - intl:      Symfony Intl/Form/Validator, StringUtils::slugify (Transliterator)
#   - apcu:      userland cache Symfony picks up for cache.system when available
#   - opcache:   bytecode cache (enabled in the upstream image already; listed for explicitness)
RUN install-php-extensions apcu intl opcache pdo_mysql

ENV PHP_INI_SCAN_DIR=":${PHP_INI_DIR}/app.conf.d"

COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --link frankenphp/worker /etc/frankenphp/worker

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]


# ---- Dev: dev php.ini, composer + git, optional Xdebug, live code reload ----------------------
FROM frankenphp_base AS frankenphp_dev

# Opt-in Xdebug: docker compose build --build-arg WITH_XDEBUG=1 php
ARG WITH_XDEBUG=0

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer_upstream /composer /usr/local/bin/composer

# git: Composer installs the sidus/* VCS packages from source in dev. file/unzip: MIME guessing, dist archives.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip \
	&& rm -rf /var/lib/apt/lists/* \
	&& install-php-extensions zip \
	&& mv "${PHP_INI_DIR}/php.ini-development" "${PHP_INI_DIR}/php.ini" \
	&& if [ "${WITH_XDEBUG}" = "1" ]; then install-php-extensions xdebug; fi

COPY --link frankenphp/conf.d/app.dev.ini ${PHP_INI_DIR}/app.conf.d/app.dev.ini

# --watch reloads the FrankenPHP worker/server on source changes for local dev.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch"]


# ---- Builder: composer install --no-dev, AssetMapper, Symfony prod cache ---------------------
# Nothing from this stage reaches the final image except /app (see prod_rootfs): composer, git, the
# composer cache and the build-time placeholders below are all left behind.
FROM frankenphp_base AS app_builder

ENV COMPOSER_ALLOW_SUPERUSER=1 \
	COMPOSER_NO_INTERACTION=1 \
	COMPOSER_MEMORY_LIMIT=-1

# NB: the build-time-only placeholders needed to compile the container cache (APP_SECRET, DATABASE_URL,
# TRUSTED_PROXIES) are deliberately NOT ENV instructions: they only exist inside the RUN below.
ENV APP_ENV=prod \
	APP_DEBUG=0

COPY --from=composer_upstream /composer /usr/local/bin/composer

RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip \
	&& rm -rf /var/lib/apt/lists/* \
	&& install-php-extensions zip

# 1. Dependencies only: this layer is invalidated by dependency changes, not by source changes.
#    --prefer-dist: dist archives, so vendor/ gets no .git clones (Composer falls back to a git clone if a download
#    fails; the .git directories are removed below anyway).
COPY --link composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache,sharing=locked \
	composer install --no-dev --prefer-dist --no-autoloader --no-scripts --no-progress

# 2. Only what is actually read at runtime.
COPY --link bin bin
COPY --link config config
COPY --link public public
COPY --link src src
COPY --link templates templates
COPY --link translations translations
COPY --link migrations migrations
COPY --link importmap.php importmap.php
COPY --link assets assets

# 3. Optimized, classmap-authoritative autoloader, assets:install and importmap:install (downloads the vendored
#    JS/CSS packages, e.g. Bulma, into assets/vendor/), the AssetMapper compile, then the prod cache warmup
#    (container, routes, Twig, Doctrine metadata, preload file).
#    These are the composer.json auto-scripts, spelled out because `cache:clear` is NOT usable in prod with
#    sidus/doctrine-filter-bundle 1.0: its non-optional ListWarmer loads the Doctrine metadata while the container is
#    built, then DoctrineBundle's optional metadata warmer (run by cache:clear) fails with "DoctrineMetadataCacheWarmer
#    must load metadata first". `cache:warmup` runs the warmers in priority order and works. Revert to
#    `composer run-script --no-dev post-install-cmd` once the bundle is fixed.
#    The placeholders are build-time ONLY: nothing connects to a database here, and the compiled cache only
#    holds %env()% *references*: the real APP_SECRET/DATABASE_URL/TRUSTED_PROXIES come from the runtime
#    environment (the entrypoint refuses to start without them).
RUN set -eux; \
	export APP_SECRET=build-time-placeholder-not-a-secret \
		DATABASE_URL="mysql://build:build@127.0.0.1:3306/build?serverVersion=mariadb-11.4.0" \
		TRUSTED_PROXIES=127.0.0.1; \
	composer dump-autoload --no-dev --classmap-authoritative; \
	php bin/console assets:install public; \
	php bin/console importmap:install; \
	php bin/console asset-map:compile; \
	php bin/console cache:warmup; \
	find vendor -type d -name .git -prune -exec rm -rf {} +; \
	rm -rf var/log/*; \
	chmod +x bin/console


# ---- Rootfs: the final filesystem, assembled and cleaned --------------------------------------
# dunglas/frankenphp inherits the PHP "build" toolchain from the official php image (gcc, g++, make,
# autoconf, re2c, ... ~250 MB) and install-php-extensions/pear/pecl. None of that has any business in
# production; purge it here, then add the application, the runtime user and the prod configuration.
FROM frankenphp_base AS prod_rootfs

ARG APP_UID=10001
ARG APP_GID=10001

RUN set -eux; \
	# install-php-extensions marks every installed package as "manual", so `apt-get autoremove` cannot do
	# this job: the toolchain (and perl, which only mailcap/dpkg-dev pull in) is purged by name. mailcap
	# (run-mailcap) is useless here; /etc/mime.types comes from media-types, which stays.
	apt-get purge -y \
		autoconf dpkg-dev g++ gcc make pkg-config re2c cpp binutils mailcap perl m4 patch pkgconf pkgconf-bin libpkgconf3 \
		gcc-14 g++-14 cpp-14 gcc-14-x86-64-linux-gnu g++-14-x86-64-linux-gnu cpp-14-x86-64-linux-gnu \
		gcc-x86-64-linux-gnu g++-x86-64-linux-gnu cpp-x86-64-linux-gnu binutils-x86-64-linux-gnu binutils-common libbinutils \
		libc6-dev libc-dev-bin libcrypt-dev linux-libc-dev rpcsvc-proto libstdc++-14-dev libgcc-14-dev \
		libasan8 libtsan2 liblsan0 libubsan1 libhwasan0 libitm1 libgomp1 libquadmath0 libatomic1 libcc1-0 \
		libctf0 libctf-nobfd0 libgprofng0 libsframe1 libisl23 libmpc3 libmpfr6 libjansson4 \
		libdpkg-perl libperl5.40 perl-modules-5.40 libgdbm6t64 libgdbm-compat4t64; \
	apt-get clean; \
	rm -rf /var/lib/apt/lists/* /var/cache/apt /var/log/apt /var/log/dpkg.log /tmp/* /usr/src/php* \
		/usr/local/include /usr/local/php \
		/usr/local/bin/install-php-extensions /usr/local/bin/docker-php-ext-* /usr/local/bin/docker-php-source \
		/usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/pear /usr/local/bin/peardev \
		/usr/local/bin/pecl /usr/local/bin/phpdbg /usr/local/bin/php-cgi /usr/local/bin/phar /usr/local/bin/phar.phar \
		/usr/local/lib/php/PEAR /usr/local/lib/php/PEAR.php /usr/local/lib/php/Archive /usr/local/lib/php/Console \
		/usr/local/lib/php/OS /usr/local/lib/php/Structures /usr/local/lib/php/System.php /usr/local/lib/php/XML \
		/usr/local/lib/php/build /usr/local/lib/php/data /usr/local/lib/php/doc /usr/local/lib/php/test \
		/usr/local/lib/php/pearcmd.php /usr/local/lib/php/peclcmd.php; \
	groupadd --system --gid "${APP_GID}" app; \
	useradd --system --uid "${APP_UID}" --gid app --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin app; \
	mv "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"; \
	rm -f "${PHP_INI_DIR}/php.ini-development"

# Build-time safety net: the purge above must not have taken a shared library or a PHP extension the server needs.
RUN set -eu; \
	for f in /usr/local/bin/frankenphp /usr/local/bin/php /usr/local/lib/libphp.so /usr/local/lib/php/extensions/*/*.so; do \
		if ldd "$f" | grep -q 'not found'; then echo "Missing shared library for $f:" >&2; ldd "$f" >&2; exit 1; fi; \
	done; \
	for ext in pdo_mysql intl apcu 'Zend OPcache' mbstring ctype iconv; do \
		php -m | grep -qi "$ext" || { echo "PHP extension $ext is missing" >&2; exit 1; }; \
	done

COPY --link frankenphp/conf.d/app.prod.ini ${PHP_INI_DIR}/app.conf.d/app.prod.ini
COPY --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

# The application is root-owned and read-only for the runtime user; only var/ (cache pools, logs) and the
# Caddy state directories (/data: certificates/ACME, /config: autosaved config) are writable by it.
# (frankenphp already carries cap_net_bind_service, so a non-root user can listen on :80/:443.)
RUN rm -rf /app && mkdir -p /app /data/caddy /config/caddy
COPY --from=app_builder /app /app
RUN mkdir -p /app/var/cache /app/var/log \
	&& chown -R app:app /app/var /data /config


# ---- Production: flat, single-layer image of the cleaned rootfs --------------------------------
# FROM scratch + COPY of the whole rootfs flattens the layers: files deleted in prod_rootfs are really
# gone from the image (a RUN rm in a derived layer would only hide them and keep the size). Image
# metadata is not inherited through a COPY, hence the explicit ENV/WORKDIR/... below.
FROM scratch AS frankenphp_prod

# Re-declared: global ARGs are not visible inside a stage unless declared again (used by the label below).
ARG PHP_VERSION
ARG FRANKENPHP_VERSION

COPY --from=prod_rootfs / /

ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
	PHP_INI_DIR=/usr/local/etc/php \
	PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d" \
	XDG_CONFIG_HOME=/config \
	XDG_DATA_HOME=/data \
	GODEBUG=cgocheck=0 \
	APP_ENV=prod

# Runtime configuration defaults. None of the secrets/endpoints (APP_SECRET, DATABASE_URL,
# TRUSTED_PROXIES) has a default here, by design: provide them at `docker run` time.
#
# WORKER MODE SWITCH - one line. "on" = FrankenPHP worker mode: the kernel is booted once per worker thread and serves
# up to MAX_REQUESTS requests (safe: sidus/* 5-7 are immutable, nothing survives a request). Run with
# -e FRANKENPHP_WORKER_MODE=off (or change this line) to boot the kernel for every request, e.g. to debug.
ENV FRANKENPHP_WORKER_MODE=on
ENV MAX_REQUESTS=500
ENV HEALTHCHECK_PORT=2090

WORKDIR /app

# Non-root, numeric so that Kubernetes' runAsNonRoot can verify it.
USER 10001:10001

EXPOSE 80 443 443/udp

# /healthz goes through the same php_server/worker as real traffic (see the Caddyfile) on a probe-only
# plain-HTTP listener, whatever SERVER_NAME is. --start-period covers the DB wait + migrations + fixtures.
HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --start-interval=2s --retries=3 \
	CMD curl -fsS -o /dev/null --max-time 4 "http://127.0.0.1:${HEALTHCHECK_PORT:-2090}/healthz" || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

LABEL org.opencontainers.image.title="Sidus/AdminDemo" \
	org.opencontainers.image.description="Demo application for the Sidus admin/datagrid/filter bundles (Symfony, FrankenPHP)" \
	org.opencontainers.image.source="https://github.com/VincentChalnot/SidusAdminDemo" \
	org.opencontainers.image.url="https://github.com/VincentChalnot/SidusAdminDemo" \
	org.opencontainers.image.licenses="MIT" \
	org.opencontainers.image.vendor="Vincent Chalnot" \
	org.opencontainers.image.base.name="docker.io/dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-trixie"
