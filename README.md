Sidus/AdminDemo
===================

Demo application built on **Symfony 7.4** and [**Bulma**](https://bulma.io), showcasing
[Sidus/AdminBundle](https://github.com/VincentChalnot/SidusAdminBundle),
[Sidus/DataGridBundle](https://github.com/VincentChalnot/SidusDataGridBundle) and
[Sidus/FilterBundle](https://github.com/VincentChalnot/SidusFilterBundle): a full CRUD admin
section (list, create, read, edit, delete) generated from plain YAML configuration, with sortable,
paginated, filterable listings — no scaffolding, no generated controllers.

The demo is intentionally public: there is no authentication, no access control, and no admin
login. Everything is reachable by anyone.

Getting started
---------------

The dev stack runs [FrankenPHP](https://frankenphp.dev) behind an external
[Traefik](https://traefik.io) reverse proxy — Traefik itself is **not** part
of this repo's `compose.yaml`; it's an assumed pre-existing shared reverse
proxy on the host, and the docker network it publishes (name taken from
`TRAEFIK_NETWORK`, default `proxy`) must already exist before you start this
project.

Install the project (Docker containers, dependencies with Composer, etc.)
with `make install`

Then get a shell access with `make shell`

Inside the container you need to:

- Create the schema `php bin/console doctrine:migrations:migrate --no-interaction`

- Install fixtures `php bin/console app:fixtures:init`

There is no `localhost` port mapping: the `php` container publishes nothing
to the host and is only reachable through Traefik, which terminates TLS and
routes `Host(`${APP_DOMAIN}`)` to it. With the defaults baked into
`compose.yaml`:

=> [https://admin-demo.local.sidus.fr/](https://admin-demo.local.sidus.fr/)

You are now all set! 🙌

Stack
-----

- PHP 8.4, Symfony 7.4, Doctrine ORM 3 / DBAL 4, Doctrine Migrations (no `schema:create`/`schema:update`
  in normal use — the schema lives in `migrations/`)
- FrankenPHP (Caddy) — no exposed ports in dev, no TLS termination (fronted by an external Traefik); the
  production image can also serve HTTPS itself, see below
- [Bulma](https://bulma.io) 1.0.4 as an [AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html)
  importmap package (`php bin/console importmap:require bulma/css/bulma.min.css`: the version is tracked in
  `importmap.php`, `importmap:install` downloads it into the git-ignored `assets/vendor/`, `asset-map:compile`
  hashes it for prod; no Node/webpack build step) + a small custom Symfony form theme
  (`templates/form/bulma_layout.html.twig`). FontAwesome is still loaded from jsDelivr (version-pinned, with
  SRI): AssetMapper's `importmap:require` can only download JS/CSS files, not the webfonts the stylesheet needs
- `sidus/admin-bundle` ^5.0, `sidus/datagrid-bundle` ^4.0, `sidus/filter-bundle` ^6.0 — see
  [CHANGELOG.md](CHANGELOG.md) for the version bumps made while modernizing this demo

Tests
-----

`vendor/bin/phpunit` runs a functional suite (Symfony `WebTestCase`) against a deterministic SQLite
database that is rebuilt before every test (no MariaDB needed): home page, `/healthz`, the News
datagrid (default filters, title/status/author filters, sorting, paging, all combined) and
create/read/edit/delete. `make test` runs it inside the dev container. To run it against another
database, export `DATABASE_URL` (a real environment variable wins over `phpunit.xml.dist`; Doctrine
appends `_test` to the database name in the `test` environment).

A separate `worker-mode` test group replays requests on a never-rebooted kernel, i.e. FrankenPHP
worker semantics. It is excluded by default because the current, stateful `sidus/*` bundles fail it
(`LogicException: Pager already applied`): `vendor/bin/phpunit --group worker-mode`.

Production image
-----------------

`frankenphp/Dockerfile`, target **`frankenphp_prod`**, is the one image that gets deployed
(`ghcr.io/vincentchalnot/sidusadmindemo`). `compose.yaml` only builds/uses `frankenphp_dev` — this
project deliberately has no prod `compose.yaml`: run the image directly or wrap it in your own
compose/manifest.

- **Built in stages**: a builder stage runs `composer install --no-dev --classmap-authoritative`,
  `importmap:install`, `asset-map:compile` and warms the Symfony prod cache. The final image is a flat
  copy of a cleaned root filesystem: **no composer, no git, no compiler/build tools** (the toolchain
  inherited from the FrankenPHP base image is purged), no dev dependency, no `.git`.
- **Non-root**: runs as `app` (uid/gid `10001`). The application is read-only for it; only `var/`
  (cache), `/data` and `/config` (Caddy state: certificates, autosaved config) are writable. FrankenPHP
  carries `cap_net_bind_service`, so `:80`/`:443` work without root (if you `--cap-drop=ALL`, add back
  `--cap-add=NET_BIND_SERVICE` or listen on a high port with `SERVER_NAME=:8080`). When you mount a
  volume on `/data` or `/config`, make it writable by uid `10001`.
- **No secrets baked in**: the container cache is compiled with throw-away placeholders that only exist
  inside one build `RUN` (not even an `ENV`); `APP_SECRET`, `DATABASE_URL` and `TRUSTED_PROXIES` are read
  from the runtime environment, and the entrypoint refuses to start without them.
- **Pinned base**: `dunglas/frankenphp:1.12-php8.4-trixie` (FrankenPHP 1.12.x / Caddy 2.11 / PHP 8.4 on
  Debian 13, glibc) and `composer/composer:2.8-bin`, as `ARG`s at the top of the Dockerfile. Rebuild to
  pick up patch releases; moving to a new minor/major or PHP line is a conscious edit there.
- **Prod PHP config**: `php.ini-production` + `frankenphp/conf.d/app.prod.ini` (`opcache.validate_timestamps=0`,
  opcache preload of the compiled container via `config/preload.php`, JIT, `expose_php=Off`), `APP_ENV=prod`,
  logs on stderr as JSON, OCI labels.

```sh
docker run -d --name sidus-admin-demo -p 8080:80 \
  -e APP_SECRET="$(openssl rand -hex 32)" \
  -e DATABASE_URL="mysql://user:password@db-host:3306/symfony?serverVersion=mariadb-11.4.0" \
  -e TRUSTED_PROXIES=127.0.0.1 \
  ghcr.io/vincentchalnot/sidusadmindemo:latest
```

(`DATABASE_URL` needs the `mariadb-` prefix in `serverVersion`, otherwise DBAL assumes MySQL and schema
introspection breaks.) Runtime environment:

| Variable | Default | Description |
|---|---|---|
| `APP_SECRET` | *required* | Symfony secret (`framework.secret`). Generate your own, never reuse a value from this README. |
| `DATABASE_URL` | *required* | Doctrine DSN. |
| `TRUSTED_PROXIES` | *required* (may be empty) | Comma-separated IPs/CIDRs of the reverse proxies whose `X-Forwarded-*` headers are trusted (e.g. Traefik's network). |
| `APP_ENV` | `prod` | Symfony environment. |
| `SERVER_NAME` | `:80` | Caddy site address. `:80` = plain HTTP for any `Host` (behind Traefik or another TLS-terminating proxy, `auto_https` never kicks in). A real domain such as `demo.example.com` makes Caddy obtain and renew a certificate automatically (publish ports 80, 443 and `443/udp`, keep `/data` persistent) and enables HSTS. |
| `RUN_MIGRATIONS` | `1` | `0` skips `doctrine:migrations:migrate` at start. |
| `LOAD_FIXTURES` | `1` | `0` skips `app:fixtures:init` (already a no-op when data exists). The database wait is skipped when both are `0`. |
| `FRANKENPHP_WORKER_MODE` | `off` | `on` runs the app in FrankenPHP worker mode, see below. |
| `FRANKENPHP_WORKER_NUM` | FrankenPHP default (2 × CPUs) | Worker threads when the worker mode is on. |
| `MAX_REQUESTS` | `500` | Requests handled by one worker before it is recycled (worker mode). |
| `HEALTHCHECK_PORT` | `2090` | Port of the probe-only listener used by the `HEALTHCHECK`. |
| `FRANKENPHP_CONFIG` | – | Extra lines for the Caddy `frankenphp { }` block, e.g. `num_threads 8`. |
| `CADDY_GLOBAL_OPTIONS` | – | Extra Caddy global options. |

At start, `frankenphp/docker-entrypoint.sh` (for the `frankenphp` server command only, not for one-off
`docker run ... php bin/console ...`) checks the three required variables, waits for the database,
runs the migrations and the fixtures (each can be disabled, see above), then `exec`s FrankenPHP so it
is PID 1: `docker stop` triggers a graceful shutdown.

### Healthcheck

The image declares a `HEALTHCHECK` on `GET /healthz` (`App\Action\HealthAction`: answers `200 ok`
through the front controller without touching the database, so a database outage does not restart the
app). It is served on a probe-only plain-HTTP listener (`HEALTHCHECK_PORT`, never published, answers
nothing but `/healthz`) that goes through the same `php_server`/worker as real traffic, so it keeps
working whatever `SERVER_NAME` is, including a domain with automatic HTTPS.

### Worker mode

`FRANKENPHP_WORKER_MODE` is a switch (`off`/`on`) read by the Caddyfile; the image default is a single line,
`ENV FRANKENPHP_WORKER_MODE=off`, in the `frankenphp_prod` stage of `frankenphp/Dockerfile`. With `on`, the
Symfony kernel boots once per worker thread and serves up to `MAX_REQUESTS` requests before the worker is
recycled. `symfony/runtime` 7.4 detects FrankenPHP workers natively, no extra package is needed
(`runtime/frankenphp-symfony` is only for Symfony < 7.4 and does not support Symfony 8).

**Worker mode is off by default and currently broken**: every service must be reset between requests, and the
current `sidus/*` bundles are not worker-safe (the second request to the News list on the same worker answers
`500 Pager already applied`). To debug a worker-related problem, `-e FRANKENPHP_WORKER_MODE=off` disables it
without rebuilding. To check whether the bundles are worker-safe:
`FRANKENPHP_WORKER_MODE=on make prod-smoke` and `vendor/bin/phpunit --group worker-mode`.

### Running the image locally

`make prod-smoke` (i.e. `scripts/prod-smoke.sh`) builds the image, starts a throwaway MariaDB container
and the image on a temporary user-defined network, runs `scripts/smoke.sh` against it and removes
everything (containers, anonymous volume, network; the images stay). By hand, with plain Docker:

```sh
docker network create sidus-demo
docker run -d --name sidus-demo-db --network sidus-demo \
  -e MARIADB_DATABASE=symfony -e MARIADB_USER=symfony -e MARIADB_PASSWORD=symfony \
  -e MARIADB_RANDOM_ROOT_PASSWORD=1 mariadb:11.4
docker build -f frankenphp/Dockerfile --target frankenphp_prod -t sidusadmindemo:prod .
docker run -d --name sidus-demo --network sidus-demo -p 127.0.0.1:8080:80 \
  -e APP_SECRET=local-only-not-a-secret \
  -e DATABASE_URL="mysql://symfony:symfony@sidus-demo-db:3306/symfony?serverVersion=mariadb-11.4.0" \
  -e TRUSTED_PROXIES=127.0.0.1 \
  sidusadmindemo:prod
scripts/smoke.sh http://127.0.0.1:8080      # waits for /healthz, then checks the pages
docker inspect --format '{{.State.Health.Status}}' sidus-demo
docker rm -f -v sidus-demo sidus-demo-db && docker network rm sidus-demo
```

`scripts/smoke.sh` waits for `/healthz`, requests the home page, the News list, a filtered, a sorted and a
paged list (asserting `200`, page markers and the cache/security headers, plus the importmap-served Bulma
asset), then replays that sequence several times: every page must stay byte-identical, which is how state
leaking between requests is detected.

### CI

[`.github/workflows/ci.yml`](.github/workflows/ci.yml): `test` (PHP 8.4, composer install, lint, PHPUnit) →
`image` (builds `frankenphp_prod` with the GHA layer cache, checks it is non-root and free of
composer/git/compilers, starts it against a MariaDB service container and runs `scripts/smoke.sh`) →
`publish` (pushes to `ghcr.io/vincentchalnot/sidusadmindemo` on pushes to `master` and on `vX.Y.Z` tags
only; the only job with `packages: write`). Pull requests stop after `image`.

Environment variables
----------------------

Every variable Docker Compose needs has a sane development default baked directly into
`compose.yaml` via `${VAR:-default}` — there is **no `.env` file by default** and none is
versioned. Create a `.env` at the repository root only to override a default locally (e.g. a
different `APP_DOMAIN`, `TRAEFIK_NETWORK`, or `CERT_RESOLVER`); Compose loads it automatically,
anything you don't set keeps its `compose.yaml` default. That file is git-ignored — never commit
real secrets to it.

Symfony itself never reads `.env` (or any `.env*` file): `symfony/runtime` is configured with
`disable_dotenv: true` in `composer.json`, so `APP_ENV`/`APP_SECRET`/`DATABASE_URL`/`TRUSTED_PROXIES`
only exist as the real process environment variables that `compose.yaml` injects into the `php`
container's `environment:` block. Running `bin/console`/`public/index.php` outside of that
container requires exporting those variables yourself.
