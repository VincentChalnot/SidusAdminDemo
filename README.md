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
- FrankenPHP (Caddy) — no exposed ports, no TLS termination; fronted by an external Traefik
- Bulma (self-hosted via [AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html),
  no Node/webpack build step) + a small custom Symfony form theme
  (`templates/form/bulma_layout.html.twig`)
- `sidus/admin-bundle` ^5.0, `sidus/datagrid-bundle` ^4.0, `sidus/filter-bundle` ^6.0 — see
  [CHANGELOG.md](CHANGELOG.md) for the version bumps made while modernizing this demo

Production image
-----------------

`frankenphp/Dockerfile` has a `frankenphp_prod` target (`composer install --no-dev` with an
optimized/classmap-authoritative autoloader, prod `php.ini`, and the Symfony prod cache —
container, routes, Twig, `assets:install`/`importmap:install`/`asset-map:compile` — pre-warmed
into the image, so containers boot instantly with no first-request compile cost). `compose.yaml`
only builds/uses `frankenphp_dev` — this project deliberately has no prod `compose.yaml` (see the
top-level ask that shaped this repo's Docker setup); deploy the `frankenphp_prod` image directly,
or build a deployment-specific compose/manifest around it, injecting the same runtime environment
variables `compose.yaml` lists below (`APP_SECRET`, `DATABASE_URL`, `TRUSTED_PROXIES`, ...) for
real.

At container start, `frankenphp/docker-entrypoint.sh` waits for the database to be reachable,
runs `doctrine:migrations:migrate --no-interaction`, then `app:fixtures:init` (a no-op if data is
already present — safe to restart the container without piling up duplicate demo data) before
handing off to FrankenPHP. This only runs for the actual `frankenphp` server CMD, not for
one-off `bin/console ...` exec's into a running container.

[`.github/workflows/docker-image.yml`](.github/workflows/docker-image.yml) builds that target on
every push/PR and pushes it to `ghcr.io/vincentchalnot/sidusadmindemo` on pushes to `master` and
on `vX.Y.Z` tags.

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
