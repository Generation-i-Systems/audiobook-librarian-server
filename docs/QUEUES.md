# Worker Management & Queue Monitoring

Background jobs (such as embedding generation, recommendation shelf recomputations, and AI metadata extraction) are processed asynchronously via Laravel queues (`default`, `embeddings`, `recommendations`).

## Laravel Horizon (Recommended Queue Manager & Supervisor)

This repository includes **Laravel Horizon**, which provides a real-time dashboard UI, auto-scaling worker process supervision, metrics, and queue monitoring for Redis-backed queues.

### 1. Running Horizon

To start Horizon as a background worker supervisor:

```bash
php artisan horizon
```

During development with `composer dev`, Horizon can also be run alongside `serve` and `vite`.

### 2. Horizon Dashboard

Access the visual monitoring dashboard at:
`http://localhost:8000/horizon` (or `https://your-domain.com/horizon`)

Features provided by Horizon:
- **Worker Management**: Automatically spawns, restarts, and keeps worker processes running.
- **Queue Monitoring**: Tracks queue throughput, wait times, pending jobs, and active workers.
- **Failed Job Management**: Inspect stack traces of failed jobs and retry them in one click.
- **Dedicated Supervisor Queues**: Pre-configured with a dedicated single-process supervisor for `embeddings` (ensuring safe file-store writes) and auto-scaled processes for `recommendations` and `default` queues.

### 3. Keeping Horizon Running in Production

Use **Supervisor** on Linux host deployments (`/etc/supervisor/conf.d/horizon.conf`):

```ini
[program:horizon]
process_name=%(program_name)s
command=php /path/to/audiobook-librarian-server/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/audiobook-librarian-server/storage/logs/horizon.log
stopwaitsecs=3600
```

### Alternative: Standard Queue Worker

If not using Redis/Horizon, run standard Laravel workers via systemd or supervisord:

```bash
php artisan queue:work --queue=embeddings,recommendations,default --tries=3
```
