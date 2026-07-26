# Cross-platform installation

## Supported paths

| Host OS | Recommended installation | Media-management support |
| --- | --- | --- |
| Linux | Docker Engine/Podman or native PHP | Full |
| macOS | Docker Desktop | Full after granting mounted-folder access |
| Windows | Docker Desktop with WSL 2 | Full after granting mounted-folder access |

The Docker images are Linux images, but Docker Desktop runs them on macOS and Windows. No Linux
knowledge is required for the normal Compose installation. Native PHP installations are supported
on all three operating systems; use a native PHP 8.3 runtime, Composer, a web server that terminates
TLS, and FFmpeg/FFprobe for media administration. On Windows, use native PHP or WSL 2 according to
where the audiobook folders are mounted.

## Public endpoint requirement

The mobile app only accepts HTTPS API endpoints. Point a public DNS name to the host, set
`APP_URL=https://library.example.com` and `PUBLIC_HOST=library.example.com`, then start the HTTPS
profile:

```bash
docker compose --env-file .env.docker --profile https up -d --build
```

Caddy obtains and renews the certificate and forwards traffic to the private application
container. Ports 80 and 443 must reach the host. Do not expose the internal HTTP application port
to mobile clients.

## Portable storage configuration

By default, books and backups are stored below Laravel's `storage/app` directory. To use an
existing media folder, set absolute paths appropriate to the host operating system:

```env
BOOK_STORAGE_PATH=/srv/audiobooks
DATABASE_BACKUP_PATH=/srv/audiobook-librarian/backups
IMPORT_ROOTS=/srv/imports,/srv/unsorted
```

On macOS use paths such as `/Volumes/Audiobooks`; on Windows Docker Desktop, share the host folder
with Docker and configure the corresponding container-visible mount path. Confirm read/write access
from the running container before importing or moving files.

## Native PHP installation

Install PHP 8.3, Composer, and FFmpeg on the host. Copy `.env.example` to `.env`, set a unique
`APP_KEY`, set `APP_URL` to the public `https://` URL, configure the database, and set the storage
paths above. Then install dependencies and initialize the application:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan config:cache
```

Serve the `public` directory through IIS, Apache, nginx, Caddy, or another HTTPS-capable web server.
Keep PHP-FPM/Apache/IIS and the queue worker private to the host; the TLS proxy is the only public
listener. Run `php artisan queue:work` and `php artisan schedule:work` through the host's service
manager (systemd, launchd, or Windows Task Scheduler/service wrapper).

## Required background processes

Every production or self-hosted install needs the Laravel scheduler. Some features also need a
queue worker. Docker installs already start both processes through supervisor in the application
container. Native installs must configure them explicitly.

### Docker / Compose installs

The default image runs these managed processes:

- `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`
- `php artisan schedule:work`

You do not need to add a host crontab when using the provided Docker image unless you override the
container command or split workers into separate services. After first boot, check the scheduler and
worker logs through Docker logs or the log files under `storage/logs`.

### Native installs

Use either a long-running scheduler service:

```bash
php artisan schedule:work
```

or a host cron entry that calls the scheduler once per minute:

```cron
* * * * * cd /path/to/audiobook-librarian-server && php artisan schedule:run >> /dev/null 2>&1
```

The helper script `./scripts/setup-cron.sh` adds that scheduler entry for the current checkout.

Run a queue worker as a separate managed service:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Restart queue workers after deployments so they load the new code:

```bash
php artisan queue:restart
```

## Scheduled jobs

The scheduler is the one required cron-style entry. Do not add separate cron entries for every
scheduled command below unless you intentionally bypass Laravel's scheduler.

Required scheduled maintenance:

| Command | Default cadence | Purpose |
| --- | --- | --- |
| `backup:database --verify` | Daily 02:00 and Sunday 03:00 | Creates verified database backups. Configure `DATABASE_BACKUP_PATH` and confirm backup storage permissions. |
| `accounts:purge-scheduled-deletions` | Daily | Permanently erases accounts whose cancellation window has expired. This is required for account deletion compliance. |
| `sessions:close-orphaned` | Daily 03:30 | Closes listening sessions that were started by a client but never ended, preventing stale session state. |
| `storage:fix-permissions` | Hourly | Repairs storage permissions for generated files, imports, downloads, and logs. |
| `logs:compress`, `log:rotate`, `log:clear --keep-last=14` | Daily | Compresses, rotates, and prunes application logs. |

Library maintenance that most local-library servers should run:

| Command | Default cadence | Purpose |
| --- | --- | --- |
| `books:validate-directories` | Daily 03:00 | Detects missing book directories and orphaned folders on the configured books disk. |
| `library:repair-scan --issue=missing_directory --issue=nested_audio --issue=duplicate_directory --issue=orphan_directory` | Daily 05:00 | Populates the admin Library Repair dashboard with actionable filesystem issues. |
| `imports:process-queue --limit=50` | Daily 02:30 | Processes queued imports from configured import roots. Needed if you use queued imports. |
| `imports:process-queue --cleanup --cleanup-days=30` | Monthly | Removes old completed import-queue records. |

Optional scheduled jobs:

| Command | Default cadence | Purpose |
| --- | --- | --- |
| `books:cache-file-chunk-hashes --all --limit=25 --max-load=2` | Not scheduled by default | Precomputes cached download-manifest chunk hashes during idle time. Tune `--limit` and `--max-load` for the host. |
| `abb:scrape-categories` | Daily 04:00 | Refreshes AudioBook Bay category data used for favorite-author discovery. Run only where that scraper is appropriate. |
| `favorites:send-notifications` | Daily 08:00 | Sends email notifications for new books by favorite authors. Requires working mail delivery. |
| `librivox:sync --language=English` | Daily 06:00 | Syncs the LibriVox catalog for deployments that expose LibriVox content. |

Examples for the optional chunk-hash precompute job:

```cron
# Work through uncached books in small batches whenever the host is quiet.
*/15 * * * * cd /path/to/audiobook-librarian-server && php artisan books:cache-file-chunk-hashes --all --limit=25 --max-load=2 >> storage/logs/chunk-hash-cache.log 2>&1

# Prioritize the newest local books.
0 * * * * cd /path/to/audiobook-librarian-server && php artisan books:cache-file-chunk-hashes --newest=50 --max-load=2 >> storage/logs/chunk-hash-cache.log 2>&1
```

Check the active schedule after configuration:

```bash
php artisan schedule:list
```

If this command fails because the configured cache/lock backend is unavailable, fix that backend
first. Scheduled commands that use `onOneServer()` or `withoutOverlapping()` rely on the cache store
for locks.
