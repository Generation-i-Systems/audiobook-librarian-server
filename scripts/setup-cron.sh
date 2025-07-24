#!/bin/bash

# Setup Cron Job for Laravel Scheduler
# This script adds the Laravel scheduler to the user's crontab

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Setting up cron job for Laravel scheduler..."
echo "Project directory: $PROJECT_DIR"

# Create the cron entry
CRON_ENTRY="* * * * * cd $PROJECT_DIR && php artisan schedule:run >> /dev/null 2>&1"

# Check if cron entry already exists
if crontab -l 2>/dev/null | grep -q "php artisan schedule:run"; then
    echo "Laravel scheduler cron job already exists."
else
    # Add the cron entry
    (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
    echo "✓ Laravel scheduler cron job added successfully."
    echo "The scheduler will run every minute and check for scheduled tasks."
    echo ""
    echo "Scheduled backup times:"
    echo "  - Daily backup: 2:00 AM"
    echo "  - Weekly backup: Sunday 3:00 AM"
    echo ""
    echo "Backup location: /var/lib/mysql/laravel_backup/"
    echo "Backup logs: $PROJECT_DIR/storage/logs/backup-cron.log"
fi

echo ""
echo "Current crontab:"
crontab -l 2>/dev/null || echo "No crontab entries found."