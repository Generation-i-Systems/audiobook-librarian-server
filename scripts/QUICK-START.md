# Permissions Queue - Quick Start

## What Was Created

### 1. Core Services (PHP)
- **`app/Services/PermissionsQueueService.php`** - Manages permission queue
- **`app/Services/AutoPermissionsStorage.php`** - Auto-queueing Storage wrapper

### 2. Scripts
- **`scripts/fix-permissions.py`** - Cron job to fix permissions
- **`scripts/add-to-permissions-queue.sh`** - Add paths manually
- **`storage/app/permissions-queue.txt`** - Queue file (created, gitignored)

### 3. Documentation
- **`scripts/PERMISSIONS-QUEUE-README.md`** - Full system docs
- **`scripts/AUTO-PERMISSIONS-GUIDE.md`** - Automatic usage guide
- **`scripts/INTEGRATION-EXAMPLE.md`** - PHP integration examples
- **`scripts/QUICK-START.md`** - This file

## Setup (5 Minutes)

### Step 1: Create Queue File
```bash
touch storage/app/permissions-queue.txt
chmod 666 storage/app/permissions-queue.txt
```

### Step 2: Test Python Script
```bash
# Dry run to verify it works
sudo python3 scripts/fix-permissions.py --dry-run --verbose
```

### Step 3: Add to Cron
```bash
crontab -e
```

Add this line (runs every 5 minutes):
```cron
*/5 * * * * /usr/bin/python3 /home/eric-shared/PhpstormProjects/librarian/scripts/fix-permissions.py --quiet >> /var/log/fix-permissions.log 2>&1
```

Or for faster response (every minute):
```cron
* * * * * /usr/bin/python3 /home/eric-shared/PhpstormProjects/librarian/scripts/fix-permissions.py --quiet >> /var/log/fix-permissions.log 2>&1
```

### Step 4: Update Your Code

**Option A: Use AutoPermissionsStorage (Recommended)**

Replace:
```php
use Illuminate\Support\Facades\Storage;

Storage::disk('books')->put($path, $contents);
```

With:
```php
use App\Services\AutoPermissionsStorage;

app(AutoPermissionsStorage::class)->put($path, $contents);
```

**Option B: Inject as Dependency**
```php
use App\Services\AutoPermissionsStorage;

class BookImportService
{
    public function __construct(
        protected AutoPermissionsStorage $storage
    ) {}

    public function importBook($metadata)
    {
        $this->storage->put($path, $contents); // Auto-queued!
    }
}
```

## Testing

### Test 1: Add Path to Queue
```bash
./scripts/add-to-permissions-queue.sh /tmp/test-file
cat storage/app/permissions-queue.txt
# Should show /tmp/test-file
```

### Test 2: Run Fix Script (Dry Run)
```bash
sudo python3 scripts/fix-permissions.py --dry-run --verbose
```

### Test 3: Run Fix Script (Actually Fix)
```bash
touch /tmp/test-file
sudo chown www-data:www-data /tmp/test-file
./scripts/add-to-permissions-queue.sh /tmp/test-file

# Run fix
sudo python3 scripts/fix-permissions.py --verbose

# Verify ownership changed
ls -l /tmp/test-file
# Should show: eric:audio

# Verify removed from queue
cat storage/app/permissions-queue.txt
# Should be empty
```

### Test 4: From PHP
```php
use App\Services\PermissionsQueueService;
use App\Services\AutoPermissionsStorage;

// Test manual queueing
$queue = app(PermissionsQueueService::class);
$queue->addPath('/tmp/test-from-php');
echo "Queue size: " . $queue->getQueueSize(); // 1

// Test auto queueing
$storage = app(AutoPermissionsStorage::class);
$storage->put('test/auto.txt', 'test content');
echo "Queue size: " . $queue->getQueueSize(); // 2
```

## How It Works

```
┌─────────────────┐
│ Web App Creates │
│ File/Directory  │
└────────┬────────┘
         │
         │ (Auto via AutoPermissionsStorage)
         ▼
┌─────────────────┐
│ Path Added to   │
│ Queue File      │
└────────┬────────┘
         │
         │ (Cron runs every 1-5 min)
         ▼
┌─────────────────┐
│ Python Script   │
│ Reads Queue     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Fix Permissions │
│ chown eric:audio│
│ chmod 775/664   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Remove from     │
│ Queue           │
└─────────────────┘
```

## Common Use Cases

### Book Import
```php
use App\Services\AutoPermissionsStorage;

class BookImportService
{
    public function __construct(
        protected AutoPermissionsStorage $storage
    ) {}

    public function importBook($metadata, $sourcePath)
    {
        // Create directory - auto-queued!
        $bookPath = $this->buildPath($metadata);
        $this->storage->makeDirectory($bookPath);

        // Copy files - auto-queued!
        foreach (glob($sourcePath . '/*.mp3') as $file) {
            $this->storage->putFile($bookPath, $file);
        }

        // All files queued, cron will fix within 1-5 min!
    }
}
```

### Cover Upload
```php
public function uploadCover(Request $request, AutoPermissionsStorage $storage)
{
    $storage->putFileAs(
        $bookPath,
        $request->file('cover'),
        'cover.jpg'
    );
    // Auto-queued! Done!
}
```

## Monitoring

### Check Queue Size
```bash
wc -l < storage/app/permissions-queue.txt
```

### View Queue
```bash
cat storage/app/permissions-queue.txt
```

### Watch Cron Log
```bash
tail -f /var/log/fix-permissions.log
```

### Check Laravel Log
```bash
tail -f storage/logs/laravel.log | grep Permission
```

## Troubleshooting

### Queue Not Processing
```bash
# Check cron is running
sudo systemctl status cron

# Check crontab
crontab -l

# Run manually with verbose output
sudo python3 scripts/fix-permissions.py --verbose
```

### Permissions Not Changing
```bash
# Check script has sudo access
sudo python3 scripts/fix-permissions.py --dry-run

# Check user/group exist
id eric
grep audio /etc/group
```

### Queue Growing Too Large
```bash
# Check for errors
grep ERROR /var/log/fix-permissions.log

# Process with recursive mode
sudo python3 scripts/fix-permissions.py --recursive --verbose
```

## Next Steps

1. ✅ Setup cron job
2. ✅ Update code to use AutoPermissionsStorage
3. ✅ Test with sample file
4. ✅ Monitor queue and logs
5. ✅ Remove manual PermissionsQueueService calls

## Documentation

- **Full Docs**: `scripts/PERMISSIONS-QUEUE-README.md`
- **Auto Mode**: `scripts/AUTO-PERMISSIONS-GUIDE.md`
- **Examples**: `scripts/INTEGRATION-EXAMPLE.md`

## Support

Issues or questions? Check the full documentation in:
- `scripts/PERMISSIONS-QUEUE-README.md`
- `scripts/AUTO-PERMISSIONS-GUIDE.md`
