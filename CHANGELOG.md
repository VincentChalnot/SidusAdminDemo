# Changelog

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
