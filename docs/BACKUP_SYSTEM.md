# MySQL Backup System

This document describes the automated MySQL backup system for the Audiobook Librarian application.

## Overview

The backup system creates compressed MySQL dumps automatically on a scheduled basis to protect against data loss.

## Components

### 1. Laravel Artisan Command
- **Command**: `php artisan backup:database`
- **Options**: `--verify` (verifies backup integrity after creation)
- **Location**: `app/Console/Commands/BackupDatabase.php`

### 2. Shell Script
- **Script**: `scripts/backup-mysql.sh`
- **Purpose**: Alternative backup method that can be run independently of Laravel

### 3. Scheduling Configuration
- **Location**: `bootstrap/app.php`
- **Schedule**: 
  - Daily backup at 2:00 AM
  - Weekly backup on Sundays at 3:00 AM

## Backup Details

### Storage Location
- **Directory**: `/var/lib/mysql/laravel_backup/`
- **Format**: `backup_{database_name}_{timestamp}.sql.gz`
- **Compression**: Gzip compressed to save space

### Retention Policy
- **Automatic cleanup**: Backups older than 30 days are automatically deleted
- **Manual cleanup**: Old backups can be manually removed from the backup directory

### Backup Features
- **Single transaction**: Ensures consistency during backup
- **Includes**: Routines, triggers, events, and database structure
- **Integrity verification**: Optional verification of backup file integrity
- **Logging**: All backup operations are logged

## Setup Instructions

### 1. Install the Cron Job
Run the setup script to add the Laravel scheduler to crontab:
```bash
./scripts/setup-cron.sh
```

### 2. Manual Backup
Create a backup manually:
```bash
php artisan backup:database --verify
```

### 3. Test the Schedule
Check what tasks are scheduled:
```bash
php artisan schedule:list
```

## Monitoring

### Log Files
- **Laravel logs**: `storage/logs/laravel.log`
- **Backup cron logs**: `storage/logs/backup-cron.log`
- **Backup script logs**: `storage/logs/backup.log`

### Checking Backup Status
1. **List backups**: `ls -la /var/lib/mysql/laravel_backup/`
2. **Check logs**: `tail -f storage/logs/backup-cron.log`
3. **Verify latest backup**: `php artisan backup:database --verify`

## Restore Process

### 1. Stop the Application
```bash
# Stop web server and any running processes
sudo systemctl stop nginx  # or apache2
```

### 2. Restore from Backup
```bash
# Decompress the backup
gunzip /var/lib/mysql/laravel_backup/backup_ab5_YYYYMMDD_HHMMSS.sql.gz

# Restore to MySQL
mysql -h127.0.0.1 -P3306 -uab5 -p < /var/lib/mysql/laravel_backup/backup_ab5_YYYYMMDD_HHMMSS.sql
```

### 3. Restart the Application
```bash
# Start web server
sudo systemctl start nginx  # or apache2
```

## Security Considerations

1. **Database credentials**: Stored in `.env` file - ensure proper file permissions
2. **Backup directory**: Located in `/var/lib/mysql/` with restricted access
3. **Log files**: May contain sensitive information - review log retention policies

## Troubleshooting

### Common Issues

1. **Permission denied**
   - Ensure backup directory has proper ownership: `sudo chown -R $USER:$USER /var/lib/mysql/laravel_backup/`

2. **Command not found**
   - Verify `mysqldump` is installed: `which mysqldump`

3. **Authentication failed**
   - Check database credentials in `.env` file
   - Test connection: `mysql -h127.0.0.1 -P3306 -uab5 -p`

4. **Disk space**
   - Check available space: `df -h /var/lib/mysql/`
   - Clean up old backups if needed

### Manual Cleanup
Remove old backups manually:
```bash
find /var/lib/mysql/laravel_backup/ -name "backup_*.sql.gz" -mtime +30 -delete
```

## Configuration

### Environment Variables
The backup system uses these database configuration values from `.env`:
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

### Customization
To modify backup schedule, edit the `withSchedule` section in `bootstrap/app.php`.