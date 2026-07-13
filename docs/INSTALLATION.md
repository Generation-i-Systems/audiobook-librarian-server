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
