Sidus/AdminDemo
===================

Demo application built on **Symfony 8.1** and [**Bulma**](https://bulma.io), showcasing
[Sidus/AdminBundle](https://github.com/VincentChalnot/SidusAdminBundle),
[Sidus/DataGridBundle](https://github.com/VincentChalnot/SidusDataGridBundle),
[Sidus/FilterBundle](https://github.com/VincentChalnot/SidusFilterBundle) and
[Sidus/DoctrineFilterBundle](https://github.com/VincentChalnot/SidusDoctrineFilterBundle): a full CRUD admin
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

- PHP 8.4, Symfony 8.1, Doctrine ORM 3 / DBAL 4 (DoctrineBundle 3), Doctrine Migrations (no `schema:create`/`schema:update`
  in normal use — the schema lives in `migrations/`)
- FrankenPHP (Caddy) — no exposed ports in dev, no TLS termination (fronted by an external Traefik); the
  production image can also serve HTTPS itself, see below
- [Bulma](https://bulma.io) 1.0.4 as an [AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html)
  importmap package (`php bin/console importmap:require bulma/css/bulma.min.css`: the version is tracked in
  `importmap.php`, `importmap:install` downloads it into the git-ignored `assets/vendor/`, `asset-map:compile`
  hashes it for prod; no Node/webpack build step). The markup (layout, navbar, forms, datagrid, pager, flash
  messages) comes from the bundles' own **`bulma_1` themes**, see "Configuration" below: the demo carries no
  copy of the bundles' templates, only `templates/base.html.twig` (navbar, footer, assets) and a few datagrid
  cell templates. FontAwesome is still loaded from jsDelivr (version-pinned, with SRI): AssetMapper's
  `importmap:require` can only download JS/CSS files, not the webfonts the stylesheet needs
- `sidus/admin-bundle` ^6.0, `sidus/datagrid-bundle` ^5.0, `sidus/filter-bundle` ^7.0 and
  `sidus/doctrine-filter-bundle` ^1.0 — see [CHANGELOG.md](CHANGELOG.md) for the version bumps made while
  modernizing this demo

Configuration
-------------

The whole News section is described by two YAML files, [`config/datagrid/News.yaml`](config/datagrid/News.yaml)
(the list: provider, columns, sort, filters, pagination, actions) and
[`config/admin/News.yaml`](config/admin/News.yaml) (the actions and their routes). The bundles' Bulma themes
are **opt-in**, nothing is picked up by default:

| Where | Setting |
|---|---|
| `config/packages/admin.yaml` | `sidus_admin.theme: bulma_1` (layout, flash messages, action buttons) and `base_template: base.html.twig` (the demo's own base template, which extends `@SidusAdmin/theme/bulma_1/base.html.twig`) |
| `config/packages/twig.yaml` | `twig.form_themes: ['@SidusAdmin/form/bulma_1_layout.html.twig']` |
| `config/datagrid/News.yaml` | `theme: bulma_1` (table, pager, sort buttons, filter form) |

The list URL is a plain GET query, so every state is a link you can bookmark:

```
/news/?filters[title]=qui&filters[publicationStatus][]=draft&sort=-publicationDate&page=2
/news/?sort=title                 ascending; a leading "-" is descending
/news/?filters[publicationStatus]= an empty value clears a default (here: validated + published)
```

An absent filter keeps its default, an invalid `page`/`sort`/filter value never errors: the page answers `200`,
shows the violation and falls back to the default. Sort buttons submit `filters[sort]`, pager links carry the
current filters and sort.

Tests
-----

`vendor/bin/phpunit` runs a functional suite (Symfony `WebTestCase`) against a deterministic SQLite
database that is rebuilt before every test (no MariaDB needed): home page, `/healthz`, the News
datagrid (default filters, filters, sorting through the sort buttons, pager links keeping the filters, invalid
input) and create/read/edit/delete. `make test` runs it inside the dev container. To run it against another
database, export `DATABASE_URL` (a real environment variable wins over `phpunit.xml.dist`; Doctrine
appends `_test` to the database name in the `test` environment).

The `worker-mode` test group replays the same requests on a never-rebooted kernel, i.e. FrankenPHP worker
semantics, and compares them with a fresh kernel per request; it runs with the rest of the suite
(`vendor/bin/phpunit --group worker-mode` runs only it).

Production image
-----------------

`frankenphp/Dockerfile`, target **`frankenphp_prod`**, is the one image that gets deployed
(`ghcr.io/vincentchalnot/sidusadmindemo`). `compose.yaml` only builds/uses `frankenphp_dev` — this
project deliberately has no prod `compose.yaml`: run the image directly or wrap it in your own
compose/manifest.

- **Built in stages**: a builder stage runs `composer install --no-dev --classmap-authoritative` (its
  auto-scripts: prod `cache:clear`, `assets:install`, `importmap:install`), then `asset-map:compile`. The final image is a flat
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
| `FRANKENPHP_WORKER_MODE` | `on` | `on`/`off`: FrankenPHP worker mode, see below. `off` is classic mode (the kernel boots on every request). |
| `FRANKENPHP_WORKER_NUM` | FrankenPHP default (2 × CPUs) | Worker threads when the worker mode is on. |
| `MAX_REQUESTS` | `500` | Requests handled by one worker before it is recycled (worker mode; passed to FrankenPHP as `FRANKENPHP_LOOP_MAX`). |
| `HEALTHCHECK_PORT` | `2090` | Port of the probe-only listener used by the `HEALTHCHECK`. |
| `FRANKENPHP_CONFIG` | – | Extra lines for the Caddy `frankenphp { }` block, e.g. `num_threads 8`. |
| `CADDY_GLOBAL_OPTIONS` | – | Extra Caddy global options. `metrics` exposes FrankenPHP's Prometheus metrics on the (unpublished) admin endpoint, see "Worker mode". |

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

Worker mode is **on by default** in the production image: the Symfony kernel boots once per worker thread and
serves up to `MAX_REQUESTS` requests before the worker script is restarted (recycling bounds memory growth).
`FRANKENPHP_WORKER_MODE` is a switch (`on`/`off`) read by the Caddyfile; the image default is a single line,
`ENV FRANKENPHP_WORKER_MODE=on`, in the `frankenphp_prod` stage of `frankenphp/Dockerfile` (the Caddyfile and the
dev target default to `off`). `symfony/runtime` 8.1 detects FrankenPHP workers natively, no extra package is
needed (`runtime/frankenphp-symfony` is only for Symfony < 7.4).

It is safe with the current bundles (filter 7, doctrine-filter 1, datagrid 5, admin 6): the `worker-mode` test
group and the worker smoke runs below show no state carried from one request to the next. To debug a
worker-related problem, `-e FRANKENPHP_WORKER_MODE=off` switches back to classic mode without rebuilding. Each
worker thread holds its own kernel: measured with `docker stats`, the default 48 workers (2 × 24 CPUs) take
about 200 MiB against 110 MiB in classic mode; set `FRANKENPHP_WORKER_NUM` to what the host should afford.

**Checking that worker mode is really active**: start the container with `-e CADDY_GLOBAL_OPTIONS=metrics`,
which exposes `frankenphp_*` metrics on the Caddy admin endpoint (`localhost:2019` inside the container, never
published): `docker exec <container> curl -s localhost:2019/metrics | grep ^frankenphp_` shows
`frankenphp_total_workers`, `frankenphp_worker_request_count` and `frankenphp_worker_restarts`.
`scripts/assert-worker.sh <container> on|off [recycling]` asserts them, and `make prod-smoke` calls it. A low
`MAX_REQUESTS` proves the recycling (`MAX_REQUESTS=5 FRANKENPHP_WORKER_NUM=3 make prod-smoke` requires worker
restarts), `FRANKENPHP_WORKER_MODE=off make prod-smoke` that the classic mode still passes. In the
test suite, `vendor/bin/phpunit --group worker-mode` replays requests on a never-rebooted kernel.

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

`scripts/smoke.sh` waits for `/healthz`, then:

- requests the home page, the News list, filtered / sorted / paged lists (`?filters[title]=…&sort=-publicationDate&page=2`),
  invalid input (`page=abc`: a violation, still `200`) and the importmap-served Bulma asset, asserting the status,
  page markers and the cache/security headers;
- checks the datagrid: the sort buttons and the order they give, pager links that keep filters and sort;
- replays the sequence several times: every page must stay byte-identical, which is how state leaking between
  requests is detected;
- alternates six different filter/sort/page requests in a loop, then sends them concurrently: a page must always
  be the one of its own request;
- runs a create / edit / delete flow with a session (CSRF tokens, flash message, which must not show up in
  another session) and checks that the lists are unchanged afterwards.

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
