# Docker Deployment

This lets you run the server in a container for a demo, or as an easy way for
someone else to stand it up in a new environment. It is **additive** — the
regular (non-Docker) install path (`composer install`, `.env`, `php artisan
serve`, etc. — see the main [README](../README.md#setup)) is unchanged and
still works exactly as before.

The image is a single container running nginx + php-fpm + a queue worker +
the Laravel scheduler (via supervisor). By default it uses **SQLite**, so
there is no database container to configure — clone, build, and run.

## Quick start (SQLite, zero external services)

```bash
cp .env.docker.example .env.docker
docker compose --env-file .env.docker up -d --build
open http://localhost:8080
```

`--env-file .env.docker` is required on every `docker compose` invocation in
this project, because Compose only auto-loads a file literally named `.env`,
and we deliberately use `.env.docker` so it can't collide with (or be
confused for) the non-Docker `.env`.

## HTTPS is required for app connections

The Docker service listens only on `127.0.0.1` by default. `http://localhost:8080`
is suitable only for same-machine development and health checks. Do not expose that
HTTP listener to the Internet or configure it in the mobile app.

For any device connection, place a TLS-terminating reverse proxy (for example Caddy,
nginx, or Traefik) in front of the container, set `APP_URL` in `.env.docker` to the
public `https://` URL, and enter that same `https://` URL in the app. The proxy should
forward to `http://127.0.0.1:8080` on the Docker host.

This repository includes an optional Caddy profile. Set matching `APP_URL` and `PUBLIC_HOST`
values in `.env.docker`, point DNS at this host, and run:

```bash
docker compose --env-file .env.docker --profile https up -d --build
```

Caddy listens on ports 80 and 443, obtains the certificate, and proxies to the application
container. See [cross-platform installation](../docs/INSTALLATION.md) for host-specific storage
guidance.

Data persistence:

- The SQLite database file lives on the `app-database` named volume.
- Laravel `storage/` (logs, cached files, sessions) lives on `app-storage`.
- Book files are bind-mounted from the host paths set by
  `HOST_BOOK_STORAGE_PATH` / `HOST_DELETED_BOOKS_PATH` in `.env.docker`
  (defaults to `./docker-data/books` and `./docker-data/trash`). Point these
  at your real library location to import existing books.

Health check: `curl http://localhost:8080/up` (Laravel's built-in health
route) or `http://localhost:8080/api/health` for the detailed check
(database, storage volumes, data-format validation).

## Using MySQL or PostgreSQL instead of SQLite

Layer one of the DB overlays on top of the base compose file. Set
`DB_PASSWORD` (and `DB_ROOT_PASSWORD` for MySQL) in `.env.docker` first —
these are required and have no default.

```bash
# MySQL
docker compose --env-file .env.docker \
  -f docker-compose.yml -f docker-compose.mysql.yml up -d --build

# PostgreSQL
docker compose --env-file .env.docker \
  -f docker-compose.yml -f docker-compose.pgsql.yml up -d --build
```

Each overlay adds its own database container with a persistent volume
(`mysql-data` / `pgsql-data`) and points the app at it. Switching backends on
an existing deployment does not migrate data between databases — it's meant
for choosing your database up front, not for live migration.

## Multi-book-library "profiles"

The app supports host-based library profiles (`LIBRARY_PROFILE_*` env vars,
see `.env.example`). These work the same way inside the container — set them
in `.env.docker` if you need multiple libraries served from one instance.

## What the entrypoint does on every start

`docker/entrypoint.sh` (see that file for the exact logic):

1. Generates `APP_KEY` if one isn't set (ephemeral unless you pin `APP_KEY`
   in `.env.docker` — pin it if you care about session/cookie stability
   across restarts).
2. Creates the SQLite file if it doesn't exist yet (SQLite mode only), or
   waits for MySQL/PostgreSQL to accept connections (external DB modes).
3. Runs `php artisan migrate --force`. **This only applies pending
   migrations — it never drops, truncates, or resets data**, consistent
   with this repo's database safety rules (see the root `CLAUDE.md`). Set
   `RUN_MIGRATIONS=false` in `.env.docker` to disable this if you're
   managing migrations separately.
4. Links `storage:link`, caches config/routes/views in production mode.

## Safety notes (read before pointing this at real data)

- **Never set `DB_HOST`/`DB_CONNECTION` in `.env.docker` to point at your
  live production database** unless that's explicitly what you intend. The
  default setup creates its own isolated SQLite/MySQL/Postgres instance
  precisely so a demo container can't touch real data by accident.
- Book storage (`BOOK_STORAGE_PATH`, `DELETED_BOOKS_PATH`) is bind-mounted
  from the host — see [UNTESTABLE_REGRESSIONS.md](../UNTESTABLE_REGRESSIONS.md)
  section 4 (filesystem-dependent features) and section 9 (background queue
  jobs that read real files). Mounting the wrong host path, or a path with
  different permissions than expected, is not something automated tests
  catch — verify the mount manually after first boot.
- The queue worker and scheduler run as supervisor-managed processes inside
  the same container as the web server. For a small/demo deployment this is
  fine; for higher-throughput production use, consider splitting them into
  separate containers/replicas running the same image with an overridden
  command (`php artisan queue:work`, `php artisan schedule:work`).

## Building without Compose

```bash
docker build -t audiobook-librarian-server .
docker run -p 8080:80 --env-file .env.docker \
  -v ablibrarian-storage:/var/www/html/storage \
  -v ablibrarian-database:/var/www/html/database \
  -v /path/to/your/books:/data/books \
  -v /path/to/your/trash:/data/trash \
  audiobook-librarian-server
```
