# Automatic Permissions Queue

Automatic solution for queueing files created/modified by the web application.

## Problem Solved

Previously, developers had to manually remember to queue files after creating them:
```php
Storage::disk('books')->put($path, $contents);
app(PermissionsQueueService::class)->addPath($fullPath); // Easy to forget!
```

Now, with `AutoPermissionsStorage`, files are **automatically** queued when created or modified.

## Usage

### Method 1: Dependency Injection (Recommended)

Inject `AutoPermissionsStorage` into your services/controllers:

```php
use App\Services\AutoPermissionsStorage;

class BookImportService
{
    public function __construct(
        protected AutoPermissionsStorage $storage
    ) {}

    public function createBook(array $metadata): void
    {
        // Files are automatically queued!
        $this->storage->put('path/to/book.json', json_encode($metadata));
        $this->storage->makeDirectory('path/to/book');
    }
}
```

### Method 2: Direct Usage

```php
use App\Services\AutoPermissionsStorage;

$storage = app(AutoPermissionsStorage::class);
$storage->put('path/to/file.txt', 'contents'); // Automatically queued!
```

### Method 3: Replace Existing Storage Calls

**Before:**
```php
use Illuminate\Support\Facades\Storage;

Storage::disk('books')->put($path, $contents);
```

**After:**
```php
use App\Services\AutoPermissionsStorage;

app(AutoPermissionsStorage::class)->put($path, $contents);
```

## Supported Operations

All file/directory write operations automatically queue for permissions fixes:

### File Operations
- `put($path, $contents)` - Store file contents
- `putFile($path, $file)` - Store uploaded file
- `putFileAs($path, $file, $name)` - Store file with specific name
- `copy($from, $to)` - Copy file
- `move($from, $to)` - Move file
- `write($path, $contents)` - Alias for put

### Directory Operations
- `makeDirectory($path)` - Create directory (queues directory + parents)
- `copyDirectory($from, $to)` - Copy entire directory
- `moveDirectory($from, $to)` - Move entire directory

### Read-Only Operations (No Queueing)
- `exists()`, `get()`, `read()`, `size()`, `lastModified()`
- `files()`, `allFiles()`, `directories()`, `allDirectories()`
- `delete()`, `deleteDirectory()` - Deletions don't need permission fixes
- All other read operations

## How It Works

1. **Wrap Storage Operations**: `AutoPermissionsStorage` wraps Laravel's `FilesystemAdapter`
2. **Intercept Write Methods**: Detects file/directory creation and modification
3. **Auto-Queue Paths**: Automatically adds full paths to permissions queue
4. **Pass Through Reads**: Read operations work normally (no queueing)
5. **Cron Fixes**: Background cron job processes queue as usual

## Integration Examples

### Example 1: Book Import Service

```php
use App\Services\AutoPermissionsStorage;

class BookImportService
{
    public function __construct(
        protected AutoPermissionsStorage $storage,
        protected DocumentStoreServiceInterface $documentStore
    ) {}

    public function importBook(array $metadata, string $sourcePath): array
    {
        // Create book directory - automatically queued!
        $bookPath = $this->buildBookPath($metadata);
        $this->storage->makeDirectory($bookPath);

        // Copy audio files - automatically queued!
        foreach ($this->getAudioFiles($sourcePath) as $audioFile) {
            $destPath = $bookPath . '/' . basename($audioFile);
            $this->storage->putFile($bookPath, $audioFile);
        }

        // Upload cover - automatically queued!
        if (!empty($metadata['cover_url'])) {
            $coverContents = file_get_contents($metadata['cover_url']);
            $this->storage->put($bookPath . '/cover.jpg', $coverContents);
        }

        // All files/directories are now in the queue!
        // Cron will fix permissions within 1-5 minutes

        return $metadata;
    }
}
```

### Example 2: Cover Upload Controller

```php
use App\Services\AutoPermissionsStorage;

class BookController extends Controller
{
    public function uploadCover(
        Request $request,
        AutoPermissionsStorage $storage,
        string $bookId
    ) {
        $request->validate(['cover' => 'required|image|max:5120']);

        $book = $this->documentStore->getBook($bookId);
        $coverPath = $book['directoryPath'] . '/cover.jpg';

        // Automatically queued!
        $storage->putFileAs(
            dirname($coverPath),
            $request->file('cover'),
            'cover.jpg'
        );

        return back()->with('success', 'Cover uploaded successfully');
    }
}
```

### Example 3: File Migration

```php
use App\Services\AutoPermissionsStorage;

class MigrateBookFilesCommand extends Command
{
    public function handle(AutoPermissionsStorage $storage)
    {
        $oldPaths = $this->getOldBookPaths();

        foreach ($oldPaths as $oldPath => $newPath) {
            // Automatically queued!
            $storage->moveDirectory($oldPath, $newPath);

            $this->info("Moved: {$oldPath} → {$newPath}");
        }

        $this->info("All files queued for permission fixes");
    }
}
```

## Advantages Over Manual Queueing

| Manual Queueing | Auto Queueing |
|----------------|---------------|
| Easy to forget | Automatic - can't forget |
| Verbose code | Clean, concise code |
| Two-step process | One-step process |
| Error prone | Reliable |
| Inconsistent usage | Consistent everywhere |

## Backward Compatibility

`AutoPermissionsStorage` is 100% compatible with Laravel's Storage API:

```php
// These all work the same:
Storage::disk('books')->put($path, $contents);
app(AutoPermissionsStorage::class)->put($path, $contents);

// Can use same methods:
Storage::disk('books')->exists($path);
app(AutoPermissionsStorage::class)->exists($path);

// Can switch disk:
app(AutoPermissionsStorage::class)->disk('local')->put($path, $contents);
```

## Migration Guide

### Step 1: Identify Storage Usage

Find all places where files are created via Storage:
```bash
grep -r "Storage::disk('books')" app/
grep -r "->put\|->putFile\|->makeDirectory" app/
```

### Step 2: Replace with AutoPermissionsStorage

**Option A: Dependency Injection (Best)**
```php
use App\Services\AutoPermissionsStorage;

class MyService
{
    public function __construct(
        protected AutoPermissionsStorage $storage
    ) {}

    public function doSomething()
    {
        $this->storage->put($path, $contents);
    }
}
```

**Option B: Direct Replacement**
```php
// Before
Storage::disk('books')->put($path, $contents);

// After
app(AutoPermissionsStorage::class)->put($path, $contents);
```

**Option C: Create Helper**
```php
// app/Helpers/storage_helper.php
if (!function_exists('bookstorage')) {
    function bookstorage(): AutoPermissionsStorage {
        return app(AutoPermissionsStorage::class);
    }
}

// Usage
bookstorage()->put($path, $contents);
```

### Step 3: Remove Manual Queueing

Remove any manual `PermissionsQueueService` calls:
```php
// Remove these lines:
app(PermissionsQueueService::class)->addPath($fullPath);
$this->permissionsQueue->addPath($fullPath);
```

### Step 4: Test

```php
// Test that files are being queued
$storage = app(AutoPermissionsStorage::class);
$queue = app(PermissionsQueueService::class);

$initialSize = $queue->getQueueSize();
$storage->put('test/file.txt', 'test');
$newSize = $queue->getQueueSize();

assert($newSize === $initialSize + 1, 'File was queued!');
```

## Performance

**Minimal Overhead:**
- Only queues paths on successful operations
- No file I/O during queueing (just appends to text file)
- ~1ms overhead per file operation
- Queue file is locked during writes (atomic)

**Cron Processing:**
- Runs every 1-5 minutes (configurable)
- Processes queue asynchronously
- No impact on web request performance

## Best Practices

### 1. Use for Book Storage Only

Only use for the 'books' disk (or similar) where permissions matter:
```php
// Good - books disk needs permission fixes
app(AutoPermissionsStorage::class)->put($path, $contents);

// Not needed - temp files don't need fixing
Storage::disk('local')->put($tempPath, $contents);
```

### 2. Inject as Dependency

```php
class MyService
{
    public function __construct(
        protected AutoPermissionsStorage $storage  // Better
    ) {}

    public function bad()
    {
        app(AutoPermissionsStorage::class)->put(...); // Works but less testable
    }
}
```

### 3. Use Type Hints

```php
use App\Services\AutoPermissionsStorage;

public function myMethod(AutoPermissionsStorage $storage): void
{
    $storage->put($path, $contents);
}
```

### 4. Test with Dry Run

Before deploying, test with cron dry-run mode:
```bash
sudo python3 scripts/fix-permissions.py --dry-run --verbose
```

## Troubleshooting

### Files Not Being Queued

Check if AutoPermissionsStorage is being used:
```php
// Enable debug logging in AutoPermissionsStorage
Log::debug('AutoPermissionsStorage: queueing path', ['path' => $path]);
```

### Queue Growing Too Large

```bash
# Check queue size
wc -l < storage/app/permissions-queue.txt

# Check for errors in cron log
tail -f /var/log/fix-permissions.log | grep ERROR
```

### Cron Not Running

```bash
# Verify cron is active
sudo systemctl status cron

# Check crontab
crontab -l

# Manually run script
sudo python3 scripts/fix-permissions.py --verbose
```

## Monitoring

### Dashboard Widget

Add to your admin dashboard:
```php
public function dashboard(PermissionsQueueService $queue)
{
    return view('admin.dashboard', [
        'permissions_queue_size' => $queue->getQueueSize(),
    ]);
}
```

```blade
@if($permissions_queue_size > 0)
    <div class="alert alert-info">
        {{ $permissions_queue_size }} file(s) pending permission fixes
    </div>
@endif
```

### Log Monitoring

```bash
# Watch for queue additions
tail -f storage/logs/laravel.log | grep "PermissionsQueue"

# Watch for permission fixes
tail -f /var/log/fix-permissions.log
```

## Complete Example

```php
<?php

namespace App\Services;

use App\Services\AutoPermissionsStorage;
use App\Contracts\DocumentStoreServiceInterface;

class BookImportService
{
    public function __construct(
        protected AutoPermissionsStorage $storage,
        protected DocumentStoreServiceInterface $documentStore
    ) {}

    public function importBook(array $metadata, string $sourcePath): array
    {
        // 1. Create book directory (auto-queued)
        $bookPath = $this->buildBookPath($metadata);
        $this->storage->makeDirectory($bookPath);

        // 2. Copy audio files (auto-queued)
        foreach (glob($sourcePath . '/*.mp3') as $audioFile) {
            $this->storage->putFile($bookPath, $audioFile);
        }

        // 3. Download and save cover (auto-queued)
        if (!empty($metadata['cover_url'])) {
            $coverData = file_get_contents($metadata['cover_url']);
            $this->storage->put($bookPath . '/cover.jpg', $coverData);
        }

        // 4. Save metadata (auto-queued)
        $this->storage->put(
            $bookPath . '/metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT)
        );

        // 5. Create database record
        $bookId = $this->documentStore->createBook($metadata);

        // All files automatically queued for permission fixes!
        // Cron will handle within 1-5 minutes

        return [
            'id' => $bookId,
            'path' => $bookPath,
        ];
    }

    protected function buildBookPath(array $metadata): string
    {
        $genre = $metadata['genre'][0] ?? 'Unknown';
        $author = $metadata['author'][0] ?? 'Unknown';
        $title = $metadata['title'];

        return "{$genre}/{$author}/{$title}";
    }
}
```

No manual queueing needed - it all happens automatically! 🎉
