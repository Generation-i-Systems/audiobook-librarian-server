#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# ---------------------------------------------------------------------------
# APP_KEY: generate one on first boot if missing so the container is usable
# out of the box. Keep the generated key on the persistent storage volume so
# encrypted data remains readable across container restarts.
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    key_file="/var/www/html/storage/app/.app-key"
    mkdir -p "$(dirname "$key_file")"
    if [ ! -s "$key_file" ]; then
        echo "[entrypoint] APP_KEY not set, generating one in persistent storage..."
        # In a subshell: a umask left in place here made config:cache below write
        # root-only cache files that php-fpm (www-data) cannot read.
        (umask 077 && php artisan key:generate --show > "$key_file")
    fi
    APP_KEY=$(cat "$key_file")
    export APP_KEY
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
    chown www-data:www-data "$(dirname "$DB_DATABASE")" "$DB_DATABASE" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Wait for an external database (MySQL/PostgreSQL) to accept connections,
# if configured. SQLite needs no such wait.
# ---------------------------------------------------------------------------
if [ "${DB_CONNECTION:-sqlite}" = "mysql" ]; then
    echo "[entrypoint] Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
    for _ in $(seq 1 30); do
        if mysqladmin --skip-ssl ping -h "${DB_HOST:-mysql}" -P "${DB_PORT:-3306}" \
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

# ---------------------------------------------------------------------------
# LAN listener certificate (docs/LOCAL_TRANSFER.md): self-signed and long-lived,
# kept on the storage volume so the key the apps pin survives rebuilds. The apps
# learn its key over APP_URL, so no name or CA is needed.
# ---------------------------------------------------------------------------
tls_dir="/var/www/html/storage/app/local-tls"
if [ ! -s "$tls_dir/cert.pem" ] || [ ! -s "$tls_dir/key.pem" ]; then
    echo "[entrypoint] Generating the LAN listener certificate in $tls_dir"
    mkdir -p "$tls_dir"
    (
        umask 077
        openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
            -keyout "$tls_dir/key.pem" -out "$tls_dir/cert.pem" -days 3650 \
            -subj "/CN=Audiobook Librarian local transfer" 2>/dev/null
    )
    chmod 644 "$tls_dir/cert.pem"
fi

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

# Fix ownership in case a bind-mounted volume reset it. Last, because the artisan
# commands above run as root and create files there (laravel.log, the config and
# route caches) that php-fpm, running as www-data, must be able to write and read.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
