# Permissions Queue System

Automatically fix file permissions and ownership for files created by the web application.

## Problem

When files are created via the web interface (PHP/Laravel), they're owned by the web server user (www-data) with default permissions. For the audiobook library to work correctly, files need:
- **Ownership**: `eric:audio`
- **Permissions**: `0775` for directories, `0664` for files

Since PHP can't change ownership or set permissions beyond the web server's capabilities, we use a queue-based system with a cron job running as a privileged user.

## Components

### 1. Queue File
**Location**: `storage/app/permissions-queue.txt`

Simple text file listing paths that need permission fixes, one per line:
```
/path/to/audiobooks/Fantasy/Author/Book Title
/path/to/audiobooks/cover.jpg
```

### 2. Python Fix Script
**Location**: `scripts/fix-permissions.py`

Processes the queue, fixes permissions/ownership, and removes successfully processed paths.

**Features**:
- Checks current permissions before applying fixes
- Handles missing files gracefully
- Removes fixed paths from queue automatically
- Keeps failed paths in queue for retry
- Supports dry-run mode
- Optional recursive directory processing

### 3. Bash Helper Script
**Location**: `scripts/add-to-permissions-queue.sh`

Command-line tool to easily add paths to the queue.

### 4. PHP Service
**Location**: `app/Services/PermissionsQueueService.php`

Laravel service for adding paths to the queue from application code.

## Usage

### From Command Line

**Add a single path:**
```bash
./scripts/add-to-permissions-queue.sh /path/to/file
```

**Add multiple paths:**
```bash
./scripts/add-to-permissions-queue.sh /path/one /path/two /path/three
```

**Run fix script manually:**
```bash
# Normal mode (applies fixes and removes from queue)
sudo python3 scripts/fix-permissions.py

# Dry run (see what would happen)
sudo python3 scripts/fix-permissions.py --dry-run

# Fix directories recursively
sudo python3 scripts/fix-permissions.py --recursive

# Quiet mode (only show errors)
sudo python3 scripts/fix-permissions.py --quiet
```

### From Laravel/PHP

```php
use App\Services\PermissionsQueueService;

$queue = app(PermissionsQueueService::class);

// Add a single path
$queue->addPath('/path/to/audiobooks/Book Title');

// Add multiple paths
$queue->addPaths([
    '/path/to/audiobooks/Book One',
    '/path/to/audiobooks/Book Two',
    '/path/to/audiobooks/cover.jpg',
]);

// Check queue size
$count = $queue->getQueueSize();

// Check if path is in queue
$inQueue = $queue->isInQueue('/path/to/file');
```

**Example usage in BookImportService:**
```php
// After creating a book directory
$bookPath = $this->getBookDirectory($metadata);
app(PermissionsQueueService::class)->addPath($bookPath);
```

### Cron Setup

**Option 1: Run every 5 minutes**
```bash
crontab -e
```
Add:
```cron
*/5 * * * * /usr/bin/python3 /home/eric-shared/PhpstormProjects/librarian/scripts/fix-permissions.py >> /var/log/fix-permissions.log 2>&1
```

**Option 2: Run every minute (faster response)**
```cron
* * * * * /usr/bin/python3 /home/eric-shared/PhpstormProjects/librarian/scripts/fix-permissions.py >> /var/log/fix-permissions.log 2>&1
```

**Option 3: With recursive mode for directories**
```cron
*/5 * * * * /usr/bin/python3 /home/eric-shared/PhpstormProjects/librarian/scripts/fix-permissions.py --recursive --quiet >> /var/log/fix-permissions.log 2>&1
```

## How It Works

1. **Web app creates file/directory** → Owned by www-data with default permissions
2. **App adds path to queue** → Using `PermissionsQueueService` or bash script
3. **Cron runs fix script** → Python script reads queue (runs every 1-5 minutes)
4. **Script fixes each path**:
   - Checks if path exists
   - Checks current ownership and permissions
   - Applies fixes if needed: `chown eric:audio` and `chmod 0775/0664`
   - Logs results
5. **Removes from queue** → Successfully fixed paths are removed
6. **Failed paths remain** → Errors are logged, paths stay in queue for retry

## Monitoring

**Check queue size:**
```bash
wc -l < storage/app/permissions-queue.txt
```

**View queue contents:**
```bash
cat storage/app/permissions-queue.txt
```

**View fix log:**
```bash
tail -f /var/log/fix-permissions.log
```

**Test in dry-run mode:**
```bash
sudo python3 scripts/fix-permissions.py --dry-run --verbose
```

## Troubleshooting

### Paths stuck in queue
If paths remain in queue after multiple runs, check the log for errors:
```bash
grep ERROR /var/log/fix-permissions.log
```

Common issues:
- **Permission denied**: Cron job not running as privileged user (needs sudo)
- **File not found**: Path was deleted or moved (will be auto-removed from queue)
- **Invalid path**: Typo in path (manually remove from queue file)

### Script not running
Check cron is active:
```bash
sudo systemctl status cron
```

Check crontab entry:
```bash
crontab -l
```

### Manual intervention needed
If you need to manually fix permissions:
```bash
# Single path
sudo chown eric:audio /path/to/file
sudo chmod 664 /path/to/file

# Directory recursively
sudo chown -R eric:audio /path/to/directory
sudo find /path/to/directory -type d -exec chmod 775 {} \;
sudo find /path/to/directory -type f -exec chmod 664 {} \;
```

## Security Notes

- The Python script must run as a user with permission to chown files (typically root or via sudo)
- The queue file should be writable by the web server user (www-data)
- Queue file is in `storage/app/` which is not web-accessible
- Script validates paths and handles errors gracefully
- No arbitrary code execution - only chown/chmod operations

## Configuration

Edit these constants in `scripts/fix-permissions.py`:

```python
TARGET_USER = 'eric'          # Owner username
TARGET_GROUP = 'audio'        # Group name
DIR_MODE = 0o775             # Directory permissions (rwxrwxr-x)
FILE_MODE = 0o664            # File permissions (rw-rw-r--)
```

## Example Workflow

1. User uploads audiobook via web interface
2. Laravel creates `/audiobooks/Fantasy/New Author/New Book/`
3. Files are owned by `www-data:www-data` with `0755/0644` permissions
4. Code calls:
   ```php
   app(PermissionsQueueService::class)->addPath('/audiobooks/Fantasy/New Author/New Book');
   ```
5. Path is added to `storage/app/permissions-queue.txt`
6. Within 1-5 minutes, cron runs `fix-permissions.py`
7. Script changes ownership to `eric:audio` and permissions to `0775/0664`
8. Path is removed from queue file
9. Files are now accessible to both web app and user `eric`

## Testing

**Add a test path:**
```bash
./scripts/add-to-permissions-queue.sh /tmp/test-permissions
```

**Run fix (dry-run):**
```bash
sudo python3 scripts/fix-permissions.py --dry-run --verbose
```

**Run fix (actual):**
```bash
sudo python3 scripts/fix-permissions.py --verbose
```

**Verify path was removed from queue:**
```bash
grep /tmp/test-permissions storage/app/permissions-queue.txt
# Should return nothing if successful
```
