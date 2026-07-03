#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# ---------------------------------------------------------------------------
# APP_KEY: generate one on first boot if missing so the container is usable
# out of the box. Persist it via a mounted .env or APP_KEY env var to keep
# encrypted data (sessions, cookies) stable across restarts.
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] APP_KEY not set, generating one for this container run..."
    export APP_KEY
    APP_KEY=$(php artisan key:generate --show)
fi

# ---------------------------------------------------------------------------
# SQLite: create the database file if it doesn't exist yet. This is the
# zero-config default so the image runs with no external database service.
# ---------------------------------------------------------------------------
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_DATABASE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    mkdir -p "$(dirname "$DB_DATABASE")"
    if [ ! -f "$DB_DATABASE" ]; then
        echo "[entrypoint] Creating SQLite database at $DB_DATABASE"
        touch "$DB_DATABASE"
    fi
    chown www-data:www-data "$DB_DATABASE" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Wait for an external database (MySQL/PostgreSQL) to accept connections,
# if configured. SQLite needs no such wait.
# ---------------------------------------------------------------------------
if [ "${DB_CONNECTION:-sqlite}" = "mysql" ]; then
    echo "[entrypoint] Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
    for _ in $(seq 1 30); do
        if mysqladmin ping -h "${DB_HOST:-mysql}" -P "${DB_PORT:-3306}" \
            -u "${DB_USERNAME:-root}" --password="${DB_PASSWORD:-}" --silent 2>/dev/null; then
            break
        fi
        sleep 2
    done
elif [ "${DB_CONNECTION:-sqlite}" = "pgsql" ]; then
    echo "[entrypoint] Waiting for PostgreSQL at ${DB_HOST:-pgsql}:${DB_PORT:-5432}..."
    for _ in $(seq 1 30); do
        if PGPASSWORD="${DB_PASSWORD:-}" pg_isready -h "${DB_HOST:-pgsql}" -p "${DB_PORT:-5432}" \
            -U "${DB_USERNAME:-postgres}" -q 2>/dev/null; then
            break
        fi
        sleep 2
    done
fi

# Fix ownership in case a bind-mounted volume reset it.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------------
# Non-destructive schema migration only. This never drops/truncates data —
# see CLAUDE.md database safety rules. Fresh installs get their schema
# created; existing installs get new migrations applied.
# ---------------------------------------------------------------------------
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[entrypoint] Running database migrations..."
    php artisan migrate --force
fi

php artisan storage:link 2>/dev/null || true

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
else
    php artisan config:clear
fi

exec "$@"
