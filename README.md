# Audiobook Librarian

## Quick Start

### Import Books

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

See [Import Book Documentation](docs/import-book.md) for detailed usage.

## Library Repair & Needs Review Workflow

- Nightly `library:repair-scan` command inspects audiobook directories for:
    - Missing directories referenced in MySQL
    - Duplicate `directory_path` assignments
    - Orphaned folders containing audio but no book
    - Nested audio folders that can be auto-promoted into `directory_path`
    - Numbered-suffix directories (e.g., `_01`, `_02`) that exist without a matching base directory
- Issues now record only the minimum metadata necessary (book IDs, directory paths). Derived details—like AudiobookBay links—are generated in the controller/view layer at render time.
- Admin UI lives at **`/admin/library-repair`** (linked in the admin navbar). It defaults to showing pending issues with a “Show resolved” checkbox, includes inline book edit shortcuts, and exposes per-issue **Rescan** plus **Import Missing Directory** actions (the latter replaces the old generic “Mark resolved” button for missing directories).
- Missing-directory issues display dynamic AudiobookBay search links that are built client-side from normalized author/title data.
- Issues detected on books automatically flag them for review; staff can clear or retain reasons from the book edit form.
- Use `php artisan library:repair-scan --help` for all options (issue filters, JSON output, disabling auto-fixes). Running the scan also clears stale issues where books have been removed.
- Database setup: run `php artisan migrate` to create the `library_repair_issues` table (book_id, issue_type, status, directory_path, metadata JSON, auto_resolved flag, resolved_at, resolution_notes). The table is required for both the CLI command and the admin UI.
- When adding Library Repair to an existing install, also schedule `library:repair-scan` inside `bootstrap/app.php` (example entry included) so nightly scans populate the table.

## Service Architecture

### Host-Based Library Profiles (Single Runtime, Multiple Libraries)

- The same running server can serve multiple isolated library variants based on incoming host name.
- Configure host-to-profile routing in `config/library_profiles.php` using environment variables.
- Each profile can override:
    - database connection
    - `books` disk root / `BOOK_STORAGE_PATH`
    - source mode metadata (`local`, `librivox`, etc.)
- The routing is request-scoped through `App\Http\Middleware\ResolveLibraryProfileFromHost`, so the API contract remains identical while data source and file roots change by host.

Environment variables:

- `LIBRARY_PROFILE_DEFAULT`
- `LIBRARY_PROFILE_FALLBACK_TO_DEFAULT`
- `LIBRARY_PROFILE_MAIN_HOSTS`
- `LIBRARY_PROFILE_MAIN_DB_CONNECTION`
- `LIBRARY_PROFILE_MAIN_BOOK_STORAGE_PATH`
- `LIBRARY_PROFILE_MAIN_SOURCE_MODE`
- `LIBRARY_PROFILE_LIBRIVOX_HOSTS`
- `LIBRARY_PROFILE_LIBRIVOX_DB_CONNECTION`
- `LIBRARY_PROFILE_LIBRIVOX_BOOK_STORAGE_PATH`
- `LIBRARY_PROFILE_LIBRIVOX_SOURCE_MODE`

### Document Storage Services

- **MySqlService**: Primary storage service for all book data and user information
- **MongoService**: Legacy service used only for migration purposes
- **FirestoreService**: Archived service (moved to `app/Services/Legacy`)

### Testing Safety

- Tests use SQLite in-memory database by default to prevent data loss
- Safety checks prevent tests from accidentally using production MySQL database
- `PersistentDatabaseTestCase` available for tests that need persistent data

### External Metadata Lookup Integration Tests

These tests hit real external services (network required) and are excluded from the default PHPUnit suites.

Run them explicitly:

```bash
composer test:external-metadata
```

Notes:

- AudiobookBay tests do not require credentials.
- Hardcover tests require `HARDCOVER_API_TOKEN` to be configured (otherwise they will be skipped).

## MongoDB to MySQL Migration

### Features

- Comprehensive data migration from MongoDB to MySQL
- Support for all book metadata including series relationships
- Preservation of file paths and directory structures
- Handling of complex relationships (authors, narrators, genres, series)
- Data integrity checks and validation

### Migration Command

Run the migration with:

```bash
php artisan app:migrate-mongo-to-mysql
```

### Data Structure

- **Books**: Full book metadata including title, description, publication year, etc.
- **Series**: Book series information with proper relationships
- **Pivot Tables**:
    - `book_series`: Maps books to series with `series_number` for ordering
    - `author_book`: Maps books to authors
    - `book_narrator`: Maps books to narrators
    - `book_genre`: Maps books to genres

## MongoDB Atlas Search Series Autocomplete

### API Endpoints

- `GET /api/v1/series/autocomplete?query=Super&limit=5`
    - Returns fuzzy, prefix-matched series names using Atlas Search autocomplete aggregation on the `seriesName` field.
    - Example response:
        ```json
        { "data": ["Super Powereds", "Super Heroes"] }
        ```
    - Parameters:
        - `query` (required): The search string for the series name.
        - `limit` (optional, default 10): Maximum results.

- `GET /admin/series-autocomplete?query=Super` (Admin-only endpoint)
    - Admin-accessible endpoint for series autocomplete in the book form.
    - Used by jQuery UI autocomplete in the admin book form.
    - Example response:
        ```json
        { "data": ["Super Powereds", "Super Heroes"] }
        ```
    - Parameters:
        - `query` or `term` (required): The search string for the series name.

### Implementation

- Uses MongoDB `$search` aggregation with `autocomplete` and `fuzzy` options.
- Service: `MongoService::autocompleteSeries`
- Controllers:
    - `BookApiController@autocompleteSeries` (API endpoint)
    - `BookController@autocompleteSeries` (Admin endpoint)
- Interface: `DocumentStoreServiceInterface::autocompleteSeries`
- Tests:
    - `BookApiAutocompleteSeriesTest` (API endpoint)
    - `BookControllerSeriesAutocompleteTest` (Admin endpoint)

## Unified External Cover Image Integration

### Features

- Seamless integration of external cover images (Audible, Google Books) into the book form UI
- Automatic download and storage of external cover images when books are created or updated
- Consistent UI for selecting between different cover image sources
- Auto-selection of cover images after autofill operations
- Error handling for failed cover image downloads

### Implementation

- Service: `ExternalCoverService` handles downloading and naming of external cover images
- UI: External cover images are integrated into the "Select Cover Image" block
- JavaScript: Enhanced form.js with auto-selection and error handling for cover images
- Controller: BookController uses ExternalCoverService for cover image management

See [CHANGELOG.md](CHANGELOG.md) for details.

## Reading Progress & Statistics Requirements

See `docs/requirements/reading-progress-and-stats.md`.

A Laravel-based audiobook library and management system for personal and family use. Features a RESTful API, web interface (admin & user), and powerful console utilities for maintaining and repairing your audiobook collection.

---

## Table of Contents

- [Features](#features)
- [API Specification](#api-specification)
- [Console Commands & Jobs](./docs/COMMANDS.md)
- [Web Interface](#web-interface)
    - [Admin](#admin)
    - [User](#user)
- [Setup](#setup)
- [License](#license)

---

## Features

- RESTful API for book, author, series, genre, and user management
- Web UI for browsing, searching, downloading, and managing audiobooks
- Admin dashboard for managing users, books, and repair jobs
- Repair commands for fixing metadata, covers, and cleaning up missing files
- Queue-based download manager
- Reading progress tracking
- User authentication (API & web)
- Dynamic add/remove for authors, series, genres
- Directory resync for metadata
- **AI-Powered Book Processing** with multi-provider support
    - Google Gemini models (free + paid tiers)
    - Anthropic Claude models (paid tier)
    - OpenAI ChatGPT models (paid tier)
    - Automatic metadata extraction from directory paths, filenames, and audio tags
    - Cost estimation and rate limiting
    - Confidence-based processing with manual review options
- Import-from-file feature for adding books directly from audio files
    - Browser interface for selecting files/directories
    - Automatic metadata extraction from audio files
    - Cover image extraction and import
    - One-click import with form pre-filling
- Modal-based metadata autofill with AJAX search, selection, and UI update
    - Google Books integration for book metadata
    - Audible integration for audiobook-specific metadata including narrators
- All JS logic is linted, syntax checked, and tested with Jest
- Autofill modal logic is fully tested (see `tests/js/autofillModal.test.js`)
- Autofill modal JS changes include improved error handling and input validation
- New Jest test added for autofill modal logic to ensure correct functionality
- Autofill modal code adheres to lint/test/format requirements for consistency and maintainability
- Note: If ESLint config issues arise, ensure `.eslintrc.json` is properly configured and restart the development server

---

## API Specification

**Base URL:** `/api/`

**OpenAPI Specification:** [`/api-docs/openapi.json`](/api-docs/openapi.json)

### Authentication & Headers

- Most endpoints require authentication via Laravel Sanctum personal access tokens (`Authorization: Bearer <personal-access-token>`).
- Responses are JSON. Errors use standard HTTP codes with a JSON error message.

### Authentication

Authentication is handled by Laravel Sanctum. Obtain a Personal Access Token using the login endpoint and include it in the `Authorization: Bearer <token>` header for protected endpoints. Tokens are stored hashed in the database and validated by custom `ApiAuth` middleware.

### User

- `GET /user` — Get current authenticated user object (requires auth)
- `GET /me` — Get current user profile (name and email only; requires auth)

### Books

- `GET /books` — List all books with pagination
    - **Query Parameters:**
        - `per_page` (default: 15): Results per page
        - `page` (default: 1): Page number
    - **Response:** Paginated list of books

- `GET /books/{book}` — Get book details
    - **Response:** Full book details with authors, series, genres, etc.

- `GET /books/{book}/cover` — Returns book cover image or 404
- `GET /books/{book}/download` — Returns audio file (requires permission)

- `GET /books/browse` — Browse books by categories
    - **Query Parameters:**
        - `type` (required): One of `genre`, `author`, `series`
        - `search`: Filter by name
        - `per_page` (default: 15)
        - `page` (default: 1)
    - **Response:** Paginated list of categories

- `GET /books/search` — Search books with advanced filters
    - **Query Parameters:**
        - `title`: Filter by book title
        - `author`: Filter by author name
        - `series`: Filter by series name
        - `genre`: Filter by genre name
        - `per_page` (default: 15)
        - `page` (default: 1)
    - **Response:** Paginated list of books matching criteria

### Autocomplete Endpoints

- `GET /authors/autocomplete` — Autocomplete for author names
    - **Query Parameters:**
        - `query` (required): Search string
    - **Response:** Array of matching author names

- `GET /narrators/autocomplete` — Autocomplete for narrator names
    - **Query Parameters:**
        - `query` (required): Search string
    - **Response:** Array of matching narrator names

- `GET /genres/autocomplete` — Autocomplete for genre names
    - **Query Parameters:**
        - `query` (required): Search string
    - **Response:** Array of matching genre names

### Series

- `GET /series/{series}/books` — List books in a series
    - **Query Parameters:**
        - `per_page` (default: 15): Results per page
        - `page` (default: 1): Page number
    - **Response:** Paginated list of books in the series

### Authors

- `GET /authors` — List all authors
    - **Query Parameters:**
        - `per_page` (default: 15): Results per page
        - `page` (default: 1): Page number
    - **Response:** Paginated list of authors

- `GET /authors/{author}` — Get author details
    - **Response:** Author details with statistics

- `GET /authors/{author}/books` — List books by author
    - **Query Parameters:**
        - `per_page` (default: 15): Results per page
        - `page` (default: 1): Page number
    - **Response:** Paginated list of books by the author

- `GET /authors/{author}/series` — List series by author
    - **Query Parameters:**
        - `per_page` (default: 15): Results per page
        - `page` (default: 1): Page number
    - **Response:** Paginated list of series by the author

### Genres

- `GET /genres` — List all genres
    - **Query Parameters:**
        - `per_page` (default: 15): Results per page
        - `page` (default: 1): Page number
    - **Response:** Paginated list of genres

- `GET /genres/{genre}` — Get genre details
    - **Response:** Genre details with statistics

- `GET /genres/{genre}/authors` — List authors by genre
    - **Query Parameters:**
        - `per_page` (default: 15): Results per page
        - `page` (default: 1): Page number
    - **Response:** Paginated list of authors in the genre

- `GET /genres/{genre}/books` — List books by genre
    - **Query Parameters:**
        - `per_page` (default: 15): Results per page
        - `page` (default: 1): Page number
    - **Response:** Paginated list of books in the genre

### Messages

- `POST /messages`
    - **Body:** `{ "message": "..." }`
    - **Response:** `{ "status": "sent" }`

#### API Response Example (Book)

```json
{
    "id": 123,
    "title": "Book Title",
    "description": "Book description...",
    "directory_path": "Authors/Author Name/Book Title",
    "release_date": "2024-01-01",
    "cover_image": "covers/book-123.jpg",
    "language": "en",
    "source": "local",
    "duration": 36000,
    "publisher": "Publisher Name",
    "authors": [
        {
            "id": 1,
            "name": "Author Name"
        }
    ],
    "series": [
        {
            "id": 2,
            "name": "Series Name",
            "pivot": {
                "series_number": "1"
            }
        }
    ],
    "genres": [
        {
            "id": 1,
            "name": "Sci-Fi"
        }
    ],
    "narrators": [
        {
            "id": 1,
            "name": "Narrator Name"
        }
    ]
}
```

#### Error Response Example

```json
{
    "error": "Not Found"
}
```

#### Pagination Details

Most list endpoints return paginated responses. The structure is:

```json
{
    "data": [
        /* array of resource objects, e.g. books */
    ],
    "links": {
        "first": "/api/books?page=1",
        "last": "/api/books?page=10",
        "prev": null,
        "next": "/api/books?page=2"
    },
    "meta": {
        "current_page": 1,
        "last_page": 10,
        "per_page": 15,
        "total": 150,
        "from": 1,
        "to": 15,
        "path": "/api/books"
    }
}
```

- `data`: Array of items for this page.
- `meta.current_page`: Current page number.
- `meta.last_page`: Last available page.
- `meta.per_page`: Items per page (default: 15).
- `meta.total`: Total number of items matching the query.
- `meta.from`/`meta.to`: Range of items in this page.
- `meta.path`: Base API endpoint.
- `links`: URLs for navigation (null if not available).
- Use `?page=N&per_page=M` to control pagination.

#### Example: Requesting Page 2

```
GET /api/books?page=2&per_page=10
```

#### Example: Error Response (Validation)

```json
{
    "error": "Validation Error",
    "details": {
        "email": ["The email field is required."]
    }
}
```

---

## AI-Powered Book Processing

The system includes advanced AI-driven metadata extraction using **Google Gemini**, **Anthropic Claude**, and **OpenAI ChatGPT** models. Automatically extract and improve book metadata from directory paths, filenames, and audio file tags.

### Quick Start

```bash
# Free tier processing (recommended to start)
php artisan books:process-ai --model=gemini-2.5-flash-lite

# Best paid value option
php artisan books:process-ai --model=gpt-4o-mini

# Premium quality processing
php artisan books:process-ai --model=claude-3-5-haiku
```

### Key Features

- **🆓 Free Tier Available**: Gemini models offer up to 1,000 free requests/day
- **💰 Cost Control**: Automatic cost estimation and confirmation prompts
- **🎯 Smart Processing**: Confidence-based auto-application with manual review
- **⚡ Rate Limiting**: Respects API limits across all providers
- **🔄 Fallback Support**: Basic extraction if AI processing fails

### Cost Comparison (per 1,000 books)

- **Gemini 2.5 Flash Lite (Free)**: $0.00 ⭐ _Best for starting_
- **GPT-4o Mini**: ~$0.22 ⭐ _Best paid value_
- **Claude 3.5 Haiku**: ~$1.20 ⭐ _Best quality/cost_
- **GPT-4o**: ~$3.75 _Latest capabilities_
- **Claude 4 Opus**: ~$22.50 _Maximum quality_

📖 **For complete setup instructions, model comparisons, and advanced usage**: See [AI Processing Documentation](docs/AI_PROCESSING_SETUP.md)

---

## Web Interface

### Admin

- Dashboard for managing books, users, series, and genres
- Genre management now supports a dedicated emoji and local SVG icon per genre for the admin table and genre API responses
- Run repair jobs from the web
- View logs and system status
- Import books directly from audio files
    - Browse file system and select audio files/directories
    - Extract metadata and cover images
    - One-click import with form pre-filling

### User

- Browse/search audiobooks
- Download audio (if permitted)
- Track reading/listening progress
- Request new books
- Follow authors/series

---

## Setup

1. Clone the repo
2. Run `composer install`
3. Copy `.env.example` to `.env` and set DB/storage config
4. Run `php artisan migrate --seed`
5. Create an admin: `php artisan make:admin`
6. Start the server: `php artisan serve`

---

## Running Tests

Run the full test suite with PHPUnit:

```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/phpunit tests/Feature/BookDirectoryParserTest.php

# Run with code coverage (requires Xdebug)
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html coverage-report

# Run with detailed output
./vendor/bin/phpunit --testdox
```

## Available Commands

### Book Parser

```bash
# Scan a directory for audiobooks and display results
php artisan books:test-parse /path/to/audiobooks

# Save results to JSON file
php artisan books:test-parse /path/to/audiobooks --output=json > books.json

# Limit results and show verbose output
php artisan books:test-parse /path/to/audiobooks --limit=10 --verbose

# Filter by file extensions
php artisan books:test-parse /path/to/audiobooks --extensions=m4b,mp3

# Set minimum file size (in bytes)
php artisan books:test-parse /path/to/audiobooks --min-size=1048576  # 1MB
```

### Repair: Cover Check

Validate and fix book cover images stored on the `books` disk. The command verifies each book's `cover_image` against files in its `directory_path`, tries to locate a local image, and can optionally fetch a missing cover from Audible.

```bash
# Basic run (checks and fixes locally when possible)
php artisan cover:check

# Preview actions without changing files or DB
php artisan cover:check --dry-run

# Limit processing to the first N books
php artisan cover:check --limit=25

# Attempt fetching from Audible when local cover is missing
php artisan cover:check --attempt-audible

# Combine options
php artisan cover:check --attempt-audible --limit=50
```

Options:

- `--attempt-audible`: If no suitable local cover is found, search Audible by book title and author, then download the cover via `ExternalCoverService`.
- `--dry-run`: Perform all checks and log intended changes without downloading files or updating the database.
- `--limit[=N]`: Process only the first N books. Use `0` (default) for no limit.

Behavior:

- Scans the book's directory for common image names (e.g., `cover.jpg`, `folder.png`) and sets `cover_image` when found.
- If none found and `--attempt-audible` is provided, performs an Audible search using `AudibleService` and downloads the best match cover.
- Saves downloaded images into the book's directory on the `books` disk (e.g., `cover_audible_<ASIN>.jpg`).
- Updates the `cover_image` field and clears `needs_review` flags on success; otherwise appends a reason to `needs_review_reasons`.

### Queue Processing

```bash
# Process queued jobs
php artisan queue:work

# Process a single job (for testing)
php artisan queue:work --once

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### API Service Client

The API service client allows you to make API calls as a specific user or admin user from the command line:

```bash
# Make API call with default admin user
php artisan api:client "/api/v1/books?page=1&per_page=10"

# Make API call with full URL
php artisan api:client "https://books.thelin.org/api/v1/books?date_added=recent&page=1&per_page=10"

# Make API call as specific user
php artisan api:client "/api/v1/books" --user=user-123

# Make POST request with JSON data
php artisan api:client "/api/v1/books" --method=POST --data='{"title":"New Book","author":"Author Name"}'

# Disable colored output
php artisan api:client "/api/v1/books" --no-color
```

**Options:**

- `--user=USER_ID`: Impersonate a specific user (defaults to first admin user)
- `--method=METHOD`: HTTP method (GET, POST, PUT, PATCH, DELETE)
- `--data=JSON`: JSON data for POST/PUT/PATCH requests
- `--no-color`: Disable colored JSON output

### Database Backup and Restore

The project includes automated MySQL backup and restore scripts:

```bash
# Create a database backup
./scripts/backup-mysql.sh

# Restore from a backup file
./scripts/restore-mysql-backup.sh /path/to/backup_file.sql.gz
```

**Backup Features:**

- Includes field names in SQL dumps (`--complete-insert`) for schema compatibility
- Single-row inserts (`--extended-insert=FALSE`) for better readability
- Automatic compression with gzip
- Integrity verification after backup
- Automatic cleanup of backups older than 30 days
- Comprehensive logging

**Restore Features:**

- Automatic confirmation skip if both `users` and `books` tables are empty
- Validation of backup file format (.sql.gz)
- Database credential extraction from .env file
- Error handling for missing tables or connection issues

## Documentation

- [Book Parser Documentation](./docs/book-parser.md) - Detailed guide on using the book parser
- [API Documentation](#api-specification) - Complete API reference
- [Web Interface](#web-interface) - User and admin interface documentation

## Development

1. Clone the repository
2. Install dependencies: `composer install`
3. Copy `.env.example` to `.env` and configure your environment
4. Generate application key: `php artisan key:generate`
5. Run migrations: `php artisan migrate`
6. Start the development server: `php artisan serve`

## Troubleshooting

- **Missing dependencies**: Run `composer install`
- **Permission issues**: Ensure storage and bootstrap/cache directories are writable
- **Environment configuration**: Verify your `.env` file has all required settings

---

## License

MIT

Genre icons are sourced from OpenMoji SVG artwork and stored locally under `public/images/genres`.
