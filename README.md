# Audiobook Librarian

A Laravel-based audiobook library and management system for personal and family use. Features a
RESTful API, web interface (admin & user), and console utilities for maintaining and repairing
your audiobook collection.

## Table of Contents

- [Features](#features)
- [Setup](#setup)
- [Importing Books](#importing-books)
- [Running Tests](#running-tests)
- [Documentation](#documentation)
- [License](#license)

## Features

- RESTful API for book, author, series, genre, and user management ([API Documentation](docs/API.md))
- Web UI for browsing, searching, downloading, and managing audiobooks
- Admin dashboard for managing users, books, and repair jobs
- Library repair tooling for fixing metadata, covers, and missing/duplicate directories ([details](docs/LIBRARY_REPAIR.md))
- Queue-based download manager, with optional Laravel Horizon supervision ([details](docs/QUEUES.md))
- Reading progress tracking, badges, and listening statistics
- User authentication (API & web), including Google OAuth
- QR-code mobile app connection for self-hosted instances ([details](docs/MOBILE_APP_CONNECTION.md))
- Optional multi-library hosting from a single running server, by hostname ([details](docs/HOST_PROFILES.md))
- **AI-powered book import & metadata processing**, with multi-provider support (Google Gemini, Anthropic Claude, OpenAI ChatGPT), cost estimation, and confidence-based manual review ([setup guide](docs/AI_PROCESSING_SETUP.md))
- Import-from-file: import books directly from audio files via a browser file picker, with cover and metadata extraction ([details](docs/import-from-file.md))
- Modal-based metadata autofill (Google Books, Audible) with AJAX search and selection
- Optional Whisper speech-to-text for the apps' ebook/audiobook text sync, as a docker-compose profile ([details](docs/TRANSCRIPTION.md))
- Optional vector-embedding recommendation engine ("Similar to Recent Books", "New For You") ([details](docs/RECOMMENDATIONS.md))

## Setup

### HTTPS requirement for mobile clients

Use an HTTPS URL with a valid certificate for every server entered in the mobile app; it does not
support arbitrary cleartext `http://` servers. See [Mobile App Connection](docs/MOBILE_APP_CONNECTION.md).

### Native install

1. Clone the repo and install PHP 8.3, Composer, Node.js/npm, and FFmpeg/FFprobe.
2. Copy `.env.example` to `.env` and choose a Laravel SQL connection. SQLite is the sample default;
   create `database/database.sqlite` before migrating, or configure PostgreSQL/MySQL credentials.
3. Run `composer install --no-dev --optimize-autoloader`, `npm ci`, and `npm run build`.
4. Run `php artisan key:generate`, `php artisan migrate --force`, and `php artisan storage:link`.
5. Create an admin with `php artisan app:create-admin-user`, then start the server with `php artisan serve`.

See [Cross-platform installation](docs/INSTALLATION.md) for production HTTPS, backups, and
background-process setup.

For production and self-hosted installs, also configure the scheduler and queue worker — see
[Cross-platform installation](docs/INSTALLATION.md#required-background-processes) for the required
and optional background jobs (including the nightly Library Repair scan).

### Direct downloads on your network (optional)

If the server sits behind Cloudflare, a tunnel or another CDN, phones on the same Wi-Fi otherwise
download every book out through your internet connection and back. A second HTTPS listener on the
LAN (port 8443) lets the apps download straight from the server; they fall back to `APP_URL`
whenever it doesn't answer. For a native install:

1. Create a long-lived self-signed certificate, with the LAN address from `LOCAL_URL` as its
   subject alternative name. Phones connecting to an IP send no server name, so Caddy picks the
   certificate by that IP; without it, another site on the same IP (on any port) can win and the
   handshake fails. Don't use Caddy's `tls internal`: its key changes on every renewal and the
   apps pin the key.

   ```bash
   sudo mkdir -p /etc/ablibrarian/local-tls
   sudo openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
       -keyout /etc/ablibrarian/local-tls/key.pem -out /etc/ablibrarian/local-tls/cert.pem \
       -days 3650 -subj "/CN=Audiobook Librarian local transfer" \
       -addext "subjectAltName=IP:192.168.1.20"
   ```

2. Let the web server read both files and PHP read `cert.pem` only (adjust `caddy` to your web
   server's group):

   ```bash
   sudo chown -R root:caddy /etc/ablibrarian/local-tls
   sudo chmod 755 /etc/ablibrarian /etc/ablibrarian/local-tls
   sudo chmod 644 /etc/ablibrarian/local-tls/cert.pem
   sudo chmod 640 /etc/ablibrarian/local-tls/key.pem
   ```

3. Add a listener that serves only the download routes. For Caddy (change `root` to this
   checkout's `public` directory and the socket to your PHP-FPM socket):

   ```caddy
   :8443 {
       tls /etc/ablibrarian/local-tls/cert.pem /etc/ablibrarian/local-tls/key.pem
       root * /path/to/server/public

       @blocked not remote_ip 192.168.0.0/16 10.0.0.0/8 172.16.0.0/12 127.0.0.1 ::1
       respond @blocked "Forbidden" 403

       @transfer path_regexp ^/api/v1/(health/ping|books/[^/]+/download/.+)$
       handle @transfer {
           php_fastcgi unix//run/php/php8.3-fpm.sock
       }
       handle {
           respond 404
       }
   }
   ```

   Run `sudo caddy validate --config /etc/caddy/Caddyfile` and `sudo systemctl reload caddy`.
   For nginx, use [`docker/nginx/local-transfer.conf`](docker/nginx/local-transfer.conf).

4. Set the address phones on your Wi-Fi use to reach the server (give it a DHCP reservation) and
   the certificate path in `.env` (both are listed in `.env.example`), then run
   `php artisan config:cache` if you cache configuration:

   ```bash
   LOCAL_URL=https://192.168.1.20:8443
   LOCAL_TLS_CERT=/etc/ablibrarian/local-tls/cert.pem
   ```

Don't forward port 8443 in your router. Docker images generate the certificate and publish the
listener themselves; only `LOCAL_URL` is needed. See [Direct downloads](docs/LOCAL_TRANSFER.md)
for how the key pinning works and how to check the listener with `curl`.

### Docker (demo / easy deploy elsewhere)

Prefer a container? See [docker/README.md](docker/README.md) for a
zero-config `docker compose up` setup (SQLite by default, MySQL/PostgreSQL
optional). This is purely additive — it doesn't change the regular install
steps above. See also [Cross-platform installation](docs/INSTALLATION.md) for
macOS/Windows/Linux specifics and portable storage configuration.

### Troubleshooting

- **Missing dependencies**: Run `composer install`
- **Permission issues**: Ensure `storage` and `bootstrap/cache` directories are writable
- **Environment configuration**: Verify your `.env` file has all required settings

## Importing Books

```bash
# Install the import tool
ln -s $(pwd)/bin/import-bk ~/bin/import-bk

# Import current directory
cd /path/to/audiobook
import-bk

# Import specific books
import-bk /path/book1 /path/book2

# See all options
import-bk --help
```

See [Import Book Documentation](docs/import-book.md) for detailed usage, and
[Console Commands](docs/COMMANDS.md) for the `php artisan book:import` batch-import command and
other admin-facing Artisan commands.

## Running Tests

```bash
composer test
```

See [Testing](docs/TESTING.md) for suite-scoped commands, coverage, and external-service
integration tests.

## Documentation

- [API Documentation](docs/API.md) — REST API reference and OpenAPI spec
- [Console Commands](docs/COMMANDS.md) — Artisan commands for admins
- [Cross-platform Installation](docs/INSTALLATION.md) — Docker/native install details per OS
- [AI Processing Setup](docs/AI_PROCESSING_SETUP.md) — AI provider configuration and model comparison
- [Library Repair & Needs Review](docs/LIBRARY_REPAIR.md)
- [Mobile App Connection](docs/MOBILE_APP_CONNECTION.md)
- [Direct Downloads on Your Network](docs/LOCAL_TRANSFER.md)
- [Host-Based Library Profiles](docs/HOST_PROFILES.md)
- [Vector Database & Recommendations](docs/RECOMMENDATIONS.md)
- [Speech-to-text for text sync](docs/TRANSCRIPTION.md)
- [Worker Management & Queue Monitoring](docs/QUEUES.md)
- [Backup System](docs/BACKUP_SYSTEM.md)
- [Testing](docs/TESTING.md)
- [Favorites System](docs/FAVORITES.md)
- [Badges & Achievements API](docs/BADGES_API_DOCUMENTATION.md)
- [docs/index.md](docs/index.md) for the full documentation index

Older design/planning documents and one-off migration/session summaries that no longer reflect the
current codebase live in [docs/legacy](docs/legacy/) for historical reference only.

## License

[GNU AGPL-3.0](LICENSE)

### Third-party assets

Genre icons under `public/images/genres` are sourced from [OpenMoji](https://openmoji.org) SVG
artwork (CC BY-SA 4.0) and stored locally; they are not covered by this project's own AGPL-3.0
license.
