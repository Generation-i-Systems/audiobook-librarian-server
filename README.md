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
- Optional vector-embedding recommendation engine ("Similar to Recent Books", "New For You") ([details](docs/RECOMMENDATIONS.md))

## Setup

### HTTPS requirement for mobile clients

Use an HTTPS URL with a valid certificate for every server entered in the mobile app; it does not
support arbitrary cleartext `http://` servers. See [Mobile App Connection](docs/MOBILE_APP_CONNECTION.md).

### Native install

1. Clone the repo
2. Run `composer install`
3. Copy `.env.example` to `.env` and set DB/storage config
4. Run `php artisan migrate --seed`
5. Create an admin: `php artisan app:create-admin-user`
6. Start the server: `php artisan serve`

For production and self-hosted installs, also configure the scheduler and queue worker — see
[Cross-platform installation](docs/INSTALLATION.md#required-background-processes) for the required
and optional background jobs (including the nightly Library Repair scan).

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
- [Host-Based Library Profiles](docs/HOST_PROFILES.md)
- [Vector Database & Recommendations](docs/RECOMMENDATIONS.md)
- [Worker Management & Queue Monitoring](docs/QUEUES.md)
- [Backup System](docs/BACKUP_SYSTEM.md)
- [Testing](docs/TESTING.md)
- [Favorites System](docs/FAVORITES.md)
- [Badges & Achievements API](docs/BADGES_API_DOCUMENTATION.md)
- [docs/index.md](docs/index.md) for the full documentation index

Older design/planning documents and one-off migration/session summaries that no longer reflect the
current codebase live in [docs/legacy](docs/legacy/) for historical reference only.

## License

MIT

Genre icons are sourced from OpenMoji SVG artwork and stored locally under `public/images/genres`.
