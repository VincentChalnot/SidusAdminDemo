# Changelog

## Symfony 8.1, Sidus bundles 5–7 + DoctrineFilterBundle 1, worker mode on — 2026-10-21

### Platform and dependencies

- PHP 8.4, **Symfony 8.1** (8.1.9), DoctrineBundle 3.3 / ORM 3.7 / DBAL 4.5, PHPUnit 12; `symfony.lock` pruned
  and refreshed.
- `sidus/filter-bundle` ^6 → **^7.0**, `sidus/datagrid-bundle` ^4 → **^5.0**, `sidus/admin-bundle` ^5 → **^6.0**
  and the new **`sidus/doctrine-filter-bundle` ^1.0** (the Doctrine provider is no longer part of the datagrid).
  The four bundles are registered in `config/bundles.php`.

### Configuration (the whole News section)

- `config/datagrid/News.yaml`: inline `list:` with the `doctrine` provider; `sortable` columns (`author` sorts on
  `author.fullName`), `default_sort`; filters `title` (text), `author` (text over `author.fullName` and
  `author.email`), `publicationDate` (`date_range`), `publicationStatus` (expanded multiple `choice`, default
  `validated` + `published`), `categories` (`relation`), `deleted` (`exact`, locked to `false`); the create link
  is an action with `options: {admin_action: create}`; `theme: bulma_1`.
- `config/admin/News.yaml`: `requirements: {id: '\d+'}` on the id routes.
- **Themes are opt-in**: `sidus_admin.theme: bulma_1`, `twig.form_themes: ['@SidusAdmin/form/bulma_1_layout.html.twig']`,
  `theme: bulma_1` on the datagrid. `templates/bundles/**` and `templates/form/bulma_layout.html.twig` are
  **deleted** (the bundles ship Bulma themes now); `templates/base.html.twig` extends
  `@SidusAdmin/theme/bulma_1/base.html.twig` and only adds the navbar, the footer and the assets. The cell
  templates `templates/Datagrid/*` are unchanged.
- New list URLs: `?filters[title]=…&sort=-publicationDate&page=2` (was `?form[filters][title]=…&form[sortable][config][column]=…&page=2`);
  an invalid `page`, `sort` or filter value is a violation with a `200` and the default state, never an error.
- `Assert\Choice(choices: [...])` in `News` (named argument).

### Deprecations fixed (prod log empty on MariaDB 11.4)

`doctrine.orm.controller_resolver.auto_mapping` removed (deprecated since DoctrineBundle 3.1); the initial
migration and `doctrine_migrations` are non-transactional (MySQL/MariaDB commit after every DDL statement);
`config/routes/dev/web_profiler.yaml` uses `*.php` route resources.

### Fixtures

`app:fixtures:init` marked about **90%** of the news as deleted (`numberBetween(0, 999) > 95`, a typo for a 0..99
range); it is now about 5%, so the default list shows several hundred of the 1000 news instead of ~37.

### Worker mode is on by default

`ENV FRANKENPHP_WORKER_MODE=on` in the `frankenphp_prod` stage (`-e FRANKENPHP_WORKER_MODE=off` still switches
back, the Caddyfile and dev default stay `off`). The `worker-mode` PHPUnit group (a never-rebooted kernel against
a fresh kernel per request) passes and is **no longer excluded** from the default run.
`scripts/smoke.sh` was rewritten for the new URLs and gained: sort buttons and order, pager links keeping
filters/sort, `page=abc` → `200`, alternating filters in a loop and concurrently (a page is always the one of its
own request), the create/edit/delete flow with a session (CSRF, flash message not leaking into another session),
lists unchanged afterwards. `scripts/assert-worker.sh` proves, from FrankenPHP's own metrics
(`CADDY_GLOBAL_OPTIONS=metrics`), that workers are active (`on`), absent (`off`) and recycled (`MAX_REQUESTS`
low); `scripts/prod-smoke.sh` calls it and passes `MAX_REQUESTS`.

### Image build

The builder runs `assets:install`, `importmap:install`, `asset-map:compile` and `cache:warmup` itself instead of
`composer run-script post-install-cmd`: with `sidus/doctrine-filter-bundle` 1.0, **`bin/console cache:clear` fails
in prod** (`DoctrineMetadataCacheWarmer must load metadata first`): the bundle's non-optional `ListWarmer` loads the
Doctrine metadata while the container is built, so DoctrineBundle's optional metadata warmer (priority 1000, run
afterwards by `cache:clear` in the same container) refuses to run. `cache:warmup` is fine. The composer
`auto-scripts` entry `cache:clear` has the same problem in `APP_ENV=prod`. Revert to the composer auto-script once
the bundle is fixed.

## Production image, worker-mode plumbing, CI and Bulma importmap — 2026-10-21

Everything that does not depend on the (upcoming) Symfony 8.1 + rewritten-bundles port; the app stays on
Symfony 7.4 and the current `sidus/*` bundles.

### Production image (`frankenphp/Dockerfile`, target `frankenphp_prod`)

- **Multi-stage, flat, 745 MB → 290 MB.** `app_builder` runs `composer install --no-dev --prefer-dist
  --classmap-authoritative` (no `.git` clones of the `sidus/*` packages), `importmap:install`,
  `asset-map:compile` and the prod cache warmup. `prod_rootfs` purges the compiler toolchain, perl and
  pear/pecl that `dunglas/frankenphp` inherits from the official PHP image (`install-php-extensions` marks
  every package as manually installed, so `apt-get autoremove` cannot do it: packages are purged by name,
  followed by an `ldd`/`php -m` assertion that nothing needed went away). `frankenphp_prod` is `FROM scratch` +
  one `COPY` of that rootfs, so deleted files really leave the image (file capabilities such as FrankenPHP's
  `cap_net_bind_service` survive the copy). No composer, no git, no compiler, no dev dependency.
- **Non-root** (`app`, uid/gid 10001), read-only code, writable `var/`, `/data` and `/config`.
- **`HEALTHCHECK`** on the new `GET /healthz` route (`App\Action\HealthAction`, no database) served on a
  probe-only plain-HTTP listener (`HEALTHCHECK_PORT`, default 2090) so that it works whatever `SERVER_NAME` is.
- **Prod config**: `php.ini-production` + `app.prod.ini` (`opcache.validate_timestamps=0`, opcache preload via the
  new `config/preload.php`, `expose_php=Off`, realpath cache), `APP_ENV=prod`, monolog to stderr as JSON,
  OCI labels, pinned base (`dunglas/frankenphp:1.12-php8.4-trixie`, `composer/composer:2.8-bin`).
- **No secrets baked in**: the build-time placeholders for `APP_SECRET`/`DATABASE_URL`/`TRUSTED_PROXIES` are no
  longer `ENV`s of an image stage, they only exist inside one `RUN`; verified absent from the final image.
- `frankenphp_dev` is kept for `compose.yaml` (composer, git, zip and the Xdebug option moved to it).
- `.dockerignore` also excludes `.env` and the locally generated `public/assets`, `public/bundles`,
  `assets/vendor`.

### Caddyfile (one file for dev and prod)

- `SERVER_NAME` (default `:80`, plain HTTP behind a proxy; a real domain gets automatic HTTPS), zstd/br/gzip,
  access log on stdout, security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`; HSTS only when Caddy terminates TLS), immutable one-year cache for compiled `/assets/*`,
  `Cache-Control: no-store` for everything PHP renders.
- **Worker mode switch**: `FRANKENPHP_WORKER_MODE=on|off` (default **off**), `FRANKENPHP_WORKER_NUM`,
  `MAX_REQUESTS` (worker recycling, mapped to symfony/runtime's `FRANKENPHP_LOOP_MAX`). `runtime/frankenphp-symfony`
  was **not** installed: `symfony/runtime` 7.4 ships `FrankenPhpWorkerRunner` and detects workers natively (the
  package is documented as "for Symfony < 7.4" by FrankenPHP and constrains `symfony/runtime` to `^5.4|^6|^7`, which
  would block the Symfony 8 port); `extra.runtime` is unchanged (`disable_dotenv: true`, no `APP_RUNTIME` needed).
  The switch is deliberately **not** called `FRANKENPHP_WORKER`: FrankenPHP defines `$_SERVER['FRANKENPHP_WORKER']`
  in workers and symfony/runtime tests it for truthiness, so even `FRANKENPHP_WORKER=off` in the environment
  switches the runtime to worker mode and every classic request fails with "frankenphp_handle_request() called
  while not in worker mode".
- With the bundles of that time, worker mode was broken (the second News list request served by a worker
  answered `500`, `LogicException: Pager already applied`); the plumbing itself worked. **Superseded by the entry
  above: worker mode is now on by default**, `ENV FRANKENPHP_WORKER_MODE=on` in the `frankenphp_prod` stage.

### Entrypoint

`RUN_MIGRATIONS=0` / `LOAD_FIXTURES=0` opt out of the migrations / fixtures (the database wait is skipped when both
are off), fail-fast check for the required `APP_SECRET`, `DATABASE_URL` and `TRUSTED_PROXIES`, `exec` of the server
(PID 1, graceful `docker stop`), POSIX sh.

### Bulma through AssetMapper

`assets/styles/bulma.css` (a 678 KB vendored copy) is gone: Bulma 1.0.4 (latest on npm) is the `bulma/css/bulma.min.css`
importmap package (version tracked in `importmap.php`, downloaded by `importmap:install` — part of the composer
auto-scripts and of the image build —, hashed by `asset-map:compile`) and is imported from `assets/app.js`, so
`importmap()` renders the `<link rel="stylesheet">` before `app.css`. FontAwesome **stays on the jsDelivr CDN**
(now pinned with SRI): `importmap:require` only downloads JS/CSS entries (a `.woff2` is requested as `…/+esm` and
404s), and the stylesheet needs 8 webfont files; the only importmap-able alternative is FontAwesome's SVG-with-JS
build (`js/all.min.js`, 1.5 MB / 545 KB gzipped for 8 icons). `templates/bundles/*` and
`templates/form/bulma_layout.html.twig` are untouched.

### Tests and CI

- New PHPUnit functional suite (`tests/Functional`, 19 tests) on SQLite: home, `/healthz`, News list default
  filters / title, status and author filters / sorting / paging / combined / no leak across requests, and
  create/read/edit/delete. A `worker-mode` group (excluded by default, fails today) replays requests on a never-rebooted
  kernel. Fixed on the way: `config/packages/test/framework.yaml` used `session.storage_id`, removed in Symfony 7, so
  the `test` environment could not boot; added `symfony/browser-kit` and `symfony/css-selector` (dev).
- `scripts/smoke.sh` (HTTP smoke test, replays the request sequence and requires byte-identical pages to detect
  state leaks) and `scripts/prod-smoke.sh` / `make prod-smoke` (build + throwaway MariaDB on a user-defined network
  with plain docker + cleanup). `make test`.
- `.github/workflows/docker-image.yml` → `ci.yml`: `test` → `image` (build `frankenphp_prod` with GHA cache, hygiene
  checks, MariaDB service container, `scripts/smoke.sh`) → `publish` (GHCR on `master` and `v*.*.*` tags only, the
  sole job with `packages: write`).

### Known, left as is

- `app:fixtures:init` soft-deletes ~90 % of the generated news (`numberBetween(0, 999) > 95`), so the default list
  shows ~37 of 1000 rows. Looks like a typo for a 5 % ratio; untouched.

## Production image fixes — 2026-07-21

Two gaps in the `frankenphp_prod` build/runtime:

- **Missing `asset-map:compile` at build time**: the image ran `assets:install`/`importmap:install`
  (via composer's auto-scripts) but never compiled/versioned the AssetMapper assets themselves
  (`app.js`, `app.css`, `bulma.css`) into `public/assets/`. AssetMapper only resolves assets
  on the fly in `dev`; without this, `prod` requests for `{{ asset(...) }}`/`importmap()` output
  would 404. Added an explicit `RUN php bin/console asset-map:compile` build step (same
  build-time-only placeholder env vars as the rest of the build). Verified: hashed URLs
  (`/assets/app--H0CbZF.js`, `/assets/styles/app-m0m9lv7.css`) now present in the rendered HTML
  and served with `manifest.json`/`importmap.json` written under `public/assets/`.
- **No migrations/fixtures at container start**: the prod image had no entrypoint, so a freshly
  deployed container serving against an empty database would 500 on every route (no schema) with
  no automated way to bootstrap it. Added `frankenphp/docker-entrypoint.sh`: waits for the
  database to become reachable (`dbal:run-sql 'SELECT 1'`, bounded retry loop), runs
  `doctrine:migrations:migrate --no-interaction`, then `app:fixtures:init` - only ahead of the
  actual `frankenphp` server CMD, not for one-off `bin/console` execs into a running container.
- `InitFixturesCommand` (`app:fixtures:init`) made idempotent (early-returns if `News` already
  has rows) so the entrypoint - and anyone re-running it manually - can't pile up duplicate demo
  data on every container restart. Verified: 1000 `news` rows after first boot, still exactly
  1000 after a full container restart, with the command logging "Fixtures already loaded,
  skipping." instead of re-inserting.

Verified end-to-end against a real MariaDB container over a plain Docker network, from a
completely empty database, through both the initial boot (wait → migrate → fixtures → serving)
and a restart (idempotent no-ops both times), plus the existing dev stack (`compose.yaml`)
re-verified unaffected by the `InitFixturesCommand` change.

## `sidus/admin-bundle` and `sidus/filter-bundle` tag immutability fixes — 2026-07-21

Packagist blocked three more updates for the same reason as the `sidus/datagrid-bundle` incident
below: stable tags force-moved after publication.

- `sidus/filter-bundle` `v6.0.0` (`4020dfb` → `6685e2e`, a tag moved forward onto the commit that
  added `CHANGELOG.md`) and `v6.0.2` (`3df11a6` → `ae26d7a`, a `git commit --amend` adding a
  changelog entry). Both tags restored to their originally-published commits. The `6.0.2` fix
  (PHP 8.4 implicit-nullable-parameter deprecations) is re-released as `v6.0.3` — no code change,
  only the changelog entry moved. `v6.0.0`'s dropped changelog content needed no new tag: it's
  already an ancestor of the (untouched, never-flagged) `v6.0.1`.
- `sidus/admin-bundle` `v5.0.5` (`a1b1f2` → `e1cce5b`, same `commit --amend` pattern). Restored to
  `a1b1f2`; the fix (same PHP 8.4 deprecations) re-released as `v5.0.6`.

This demo bumped `sidus/filter-bundle` `^6.0.2` → `^6.0.3` and `sidus/admin-bundle` `^5.0.5` →
`^5.0.6`; `composer.lock`/`vendor/` resynced. Full stack rebuilt and smoke-tested (home, News
datagrid list/create/edit/read/delete) with zero exceptions.

## Production Docker image + CI — 2026-07-21

- Added a `frankenphp_prod` build target to `frankenphp/Dockerfile`: `composer install --no-dev`
  with an optimized/classmap-authoritative autoloader, prod `php.ini`
  (`frankenphp/conf.d/app.prod.ini` — JIT on, `opcache.validate_timestamps=0`, errors off), and
  the Symfony prod cache (container, routes, Twig, `assets:install`/`importmap:install`)
  pre-warmed into the image at build time using build-only placeholder env vars (never used to
  reach a real database; overridden by real runtime env vars at deploy time — see the Dockerfile
  comment). `compose.yaml` is untouched and still only builds `frankenphp_dev` — no prod compose
  file, per this repo's dev-only-compose convention.
- Verified locally: `docker build --target frankenphp_prod` succeeds, and the resulting image
  boots, migrates, loads fixtures and serves `/`, `/news/`, create/edit/read with zero exceptions
  against a real MariaDB container over a plain Docker network (no bind mounts).
- Added `.github/workflows/docker-image.yml`: builds the `frankenphp_prod` target on every push
  and pull request against `master`, and additionally pushes to
  `ghcr.io/vincentchalnot/sidusadmindemo` on pushes to `master` and on `vX.Y.Z` tags (branch/semver/
  short-SHA tags via `docker/metadata-action`, GHA layer caching via `cache-from`/`cache-to:
  type=gha`).

## `sidus/datagrid-bundle` tag immutability fix — 2026-07-21

Packagist blocked an update to `v4.0.1`: its git tag had been force-moved after publication
(`c410d7a` → `542d25e`) by a stray `git commit --amend` that only added `CHANGELOG.md`, then a
re-tag/force-push during the previous session's release cleanup. No code changed, but stable
Packagist tags are immutable by design and correctly rejected the swap.

Fixed at the source: `v4.0.1` restored to its originally-published commit (`c410d7a`, no
changelog file, matching what Packagist already has); the changelog-only change is instead
released as a new `v4.0.2`. This demo's constraint bumped `sidus/datagrid-bundle` from `^4.0.1`
to `^4.0.2` and `composer.lock`/`vendor/` resynced to the new tag (`18d9b46`).

## Post-migration fixes — 2026-07-21

Follow-up fixes after switching `compose.yaml` to inline `${VAR:-default}` values and dropping
the versioned root `.env`.

- **Doctrine Migrations**: `doctrine/doctrine-migrations-bundle` was already required and
  registered but had no config and no `migrations/` directory, so the only documented setup path
  (`doctrine:schema:create`) bypassed migrations entirely. Added `config/packages/doctrine_migrations.yaml`
  and generated the initial migration (`migrations/Version20260721191233.php`, diffed from the
  actual entity mappings: `author`, `category`, `news`, `news_category`). Setup is now
  `doctrine:migrations:migrate --no-interaction`; README/Makefile updated accordingly.
- **Mixed content behind Traefik**: `framework.yaml` never configured `trusted_proxies`/
  `trusted_headers`, so Symfony ignored Traefik's `X-Forwarded-Proto: https` and generated
  `http://` URLs (profiler/WDT AJAX calls, `_wdt`/`_profiler`) on an `https://` page, which
  browsers block as mixed content. Added `trusted_proxies: '%env(TRUSTED_PROXIES)%'` and
  `trusted_headers` (`x-forwarded-for/host/proto/port`) to `framework.yaml`. Verified by curling
  the container directly with a forwarded `X-Forwarded-Proto: https` header and confirming
  generated links switch to `https://`.
- `TRUSTED_PROXIES` default in `compose.yaml` tightened from `172.16.0.0/12,127.0.0.0/8,::/0`
  (trusted literally every IPv6 address) to the three private RFC1918 ranges
  (`10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`), which cover any Docker bridge network without
  trusting arbitrary internet hosts.
- **`DATABASE_URL` `serverVersion`**: inlining the default dropped the required `mariadb-` prefix
  (`serverVersion=11.4` instead of `serverVersion=mariadb-11.4.0`). Without it, `doctrine/dbal`
  misdetects the platform as MySQL instead of MariaDB, and MySQL-flavored schema introspection
  against a MariaDB `information_schema` response throws `AssertionError: assert($options !== null)`
  in `AbstractSchemaManager::introspectTable0()` — this broke `doctrine:migrations:status`/`migrate`
  schema-diffing entirely (and would have broken `doctrine:schema:validate` the same way). Fixed
  to `serverVersion=mariadb-${MARIADB_VERSION:-11.4}.0`.

## Symfony 7.4 / PHP 8.4 modernization — 2026-07-21

Full upgrade of the demo from Symfony 4.3/PHP 7.1 to **Symfony 7.4**/**PHP 8.2+** (tested on
8.4), plus a Docker/Traefik-based dev environment and a Bootstrap → Bulma UI migration.

### Sidus bundles

- **`sidus/admin-bundle`**: `^5.0` → `^5.0.4`. Released
  [5.0.4](https://github.com/VincentChalnot/SidusAdminBundle/blob/v5.x/CHANGELOG.md) (patch, no BC
  break): `DoctrineHelper` called the removed `Doctrine\Common\Util\ClassUtils::getClass()`,
  crashing every save/delete action under Doctrine ORM 3.
- **`sidus/datagrid-bundle`**: `^4.0`. Already Symfony 7.4-compatible as-is (v4.0.0); no changes
  needed.
- **`sidus/filter-bundle`**: `^5.0` → `^6.0.1`. This is a **major** version bump — released
  [6.0.0](https://github.com/VincentChalnot/SidusFilterBundle/blob/v6.x/CHANGELOG.md) with BC
  breaks required for Doctrine ORM 3 (removed the bundle's own `Pagination\DoctrineORMPaginator`
  and `Pagination\CountOutputWalker` in favor of Doctrine ORM's native paginator), then
  [6.0.1](https://github.com/VincentChalnot/SidusFilterBundle/blob/v6.x/CHANGELOG.md) (patch) for
  a `Doctrine\DBAL\Platforms\AbstractPlatform::getName()` call removed in `doctrine/dbal` 4, which
  crashed every `LIKE`/`NOT LIKE` filter.
- **`sidus/base-bundle`**: **dropped**. The package is abandoned upstream; every feature it
  provided (service-per-route wiring, generic compiler passes, form `block_prefix` support,
  iterable `ChoiceType` data) is now built into mainline Symfony. Routing for `App\Action\*` moved
  to plain `#[Route]` PHP attributes.
- **`cleverage/permission-bundle`**: **dropped**. Last released in 2021 (PHP 7.1-era), the demo
  never configured any actual permission on the `News` entity, and the app is now public/no-auth
  by design — the dependency added nothing and its repository isn't one this account can push
  fixes to.

### App / Symfony

- `composer.json`: PHP `>=8.2`, `symfony/*` `7.4.*`, `doctrine/orm` `^3.3`, `doctrine/dbal` (via
  ORM) 4.x, `symfony/runtime` (with `extra.runtime.disable_dotenv: true`, see below),
  `symfony/asset-mapper`, `fakerphp/faker` (replacing the abandoned `fzaninotto/faker`). Direct
  `vcs` repositories were added for the three `sidus/*` packages so Composer resolves brand-new
  tags immediately instead of waiting on Packagist re-indexing; `preferred-install` keeps
  `sidus/*` installed from git source in `vendor/`, not as downloaded dist archives.
- `src/Kernel.php`, `public/index.php`, `bin/console`: rewritten for `symfony/runtime` (no more
  `config/bootstrap.php`, no more `Symfony\Component\Debug\Debug`, no more `RouteCollectionBuilder`).
- Entities (`News`, `Author`, `Category`): converted from Doctrine annotations to PHP 8 attributes
  — **required**, Doctrine ORM 3 dropped the annotation mapping driver entirely. Added
  `src/Repository/*Repository.php` (`ServiceEntityRepository`) since ORM 3's `ContainerRepositoryFactory`
  requires them to be registered services.
- `SensioFrameworkExtraBundle` removed; `App\Action\HomeAction` now uses `#[Route]` +
  `Symfony\Bridge\Twig\Attribute\Template` (native since Symfony 6.2).
- `config/packages/security.yaml`: modernized syntax, no access control rules — the demo is fully
  public by design.
- Docker: replaced the old nginx+php-fpm `docker/` setup with a single root-level `compose.yaml`
  running FrankenPHP (`frankenphp/Dockerfile`, `frankenphp/Caddyfile`). No dev/prod split, no
  ports published to the host, no TLS on the Caddy/FrankenPHP side (`auto_https off`) — the
  container sits behind an external Traefik reverse proxy addressed via `${APP_DOMAIN}` /
  `${TRAEFIK_NETWORK}` labels/env vars.
- `.env` separation: the root `.env` is consumed **only** by Docker Compose; Symfony never reads
  any `.env*` file (`symfony/runtime`'s `disable_dotenv` option) and receives `APP_ENV`,
  `APP_SECRET`, `DATABASE_URL` as real container environment variables injected by
  `compose.yaml`.

### Frontend

- Bootstrap 4 → [Bulma](https://bulma.io) 1.0.4, self-hosted through AssetMapper (no CDN
  dependency at runtime, no Node/webpack build step).
- New Symfony form theme `templates/form/bulma_layout.html.twig` (based on `form_div_layout.html.twig`,
  the framework-agnostic base) replacing `bootstrap_4_layout.html.twig`.
- Bundle-shipped Bootstrap templates overridden at the app level via Symfony's standard
  `templates/bundles/<Bundle>/...` mechanism (kept under their original logical filenames, e.g.
  `DataGrid/bootstrap4.html.twig`, so no bundle/service config had to change):
  `templates/bundles/SidusDataGridBundle/{DataGrid,Pager,Form}/*`,
  `templates/bundles/SidusAdminBundle/Action/{edit,delete}.html.twig`.
- `templates/base.html.twig` rewritten with a Bulma navbar (incl. mobile burger toggle,
  `assets/app.js`).
- New home page (`templates/Action/home.html.twig`): a Bulma hero with a clear call-to-action
  linking straight to the News datagrid example, replacing the previous near-blank placeholder.

### Verification

- `composer install` on PHP 8.4, `lint:yaml`/`lint:twig` clean.
- Full stack booted via `docker compose -f compose.yaml up -d --build`: schema creation, fixtures
  load, and manual + browser-driven smoke tests of the home page, News datagrid (list, sort,
  filter, pagination), create/edit/read/delete actions (including a real form POST → persist →
  read-back round trip) all pass with zero uncaught exceptions.
