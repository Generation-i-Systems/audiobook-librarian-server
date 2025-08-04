#!/bin/bash

# MySQL Backup Script for Audiobook Librarian
# This script creates nightly backups of the MySQL database

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$PROJECT_DIR/.env"

# Load environment variables
if [ -f "$ENV_FILE" ]; then
    export $(grep -v '^#' "$ENV_FILE" | xargs)
else
    echo "Error: .env file not found at $ENV_FILE"
    exit 1
fi

# Backup configuration
BACKUP_DIR="/var/lib/mysql/laravel_backup"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/backup_${DB_DATABASE}_${DATE}.sql"
LOG_FILE="$PROJECT_DIR/storage/logs/backup.log"

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Function to cleanup old backups (keep last 30 days)
cleanup_old_backups() {
    log_message "Cleaning up backups older than 30 days..."
    find "$BACKUP_DIR" -name "backup_*.sql*" -type f -mtime +30 -delete
    find "$BACKUP_DIR" -name "backup_*.sql*" -type f -mtime +30 -exec rm {} \; 2>/dev/null || true
}

# Start backup process
log_message "Starting MySQL backup for database: $DB_DATABASE"

# Create the backup
mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --add-drop-database \
    --complete-insert \
    --extended-insert=FALSE \
    --databases "$DB_DATABASE" > "$BACKUP_FILE"

# Check if backup was successful
if [ $? -eq 0 ]; then
    # Compress the backup
    gzip "$BACKUP_FILE"
    COMPRESSED_FILE="${BACKUP_FILE}.gz"
    
    # Get file size
    FILE_SIZE=$(du -h "$COMPRESSED_FILE" | cut -f1)
    
    log_message "Backup completed successfully: $(basename "$COMPRESSED_FILE") (${FILE_SIZE})"
    
    # Cleanup old backups
    cleanup_old_backups
    
    # Verify backup integrity by testing if it can be read
    if gunzip -t "$COMPRESSED_FILE" 2>/dev/null; then
        log_message "Backup integrity verified"
    else
        log_message "ERROR: Backup integrity check failed"
        exit 1
    fi
    
else
    log_message "ERROR: Backup failed"
    exit 1
fi

log_message "Backup process completed"