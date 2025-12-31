# Integration Examples

How to integrate the Permissions Queue system into your Laravel application.

## Example 1: After Creating a Book Directory

In `BookImportService.php` or similar:

```php
use App\Services\PermissionsQueueService;

class BookImportService
{
    protected PermissionsQueueService $permissionsQueue;

    public function __construct(PermissionsQueueService $permissionsQueue)
    {
        $this->permissionsQueue = $permissionsQueue;
    }

    public function importBook(array $metadata, string $sourcePath): array
    {
        // ... existing import logic ...

        // After creating the book directory
        $bookDirectory = $this->createBookDirectory($metadata);

        // Add to permissions queue for async fixing
        $this->permissionsQueue->addPath($bookDirectory);

        // ... rest of import logic ...
    }

    protected function createBookDirectory(array $metadata): string
    {
        $path = $this->buildBookPath($metadata);
        Storage::disk('books')->makeDirectory($path);

        return Storage::disk('books')->path($path);
    }
}
```

## Example 2: After Uploading Cover Image

```php
public function uploadCoverImage(string $bookId, UploadedFile $coverImage): string
{
    $book = $this->getBook($bookId);
    $coverPath = $book['directoryPath'] . '/cover.jpg';

    // Save the cover image
    Storage::disk('books')->putFileAs(
        dirname($coverPath),
        $coverImage,
        'cover.jpg'
    );

    // Add to permissions queue
    $fullPath = Storage::disk('books')->path($coverPath);
    app(PermissionsQueueService::class)->addPath($fullPath);

    return $coverPath;
}
```

## Example 3: After Moving Multiple Files

```php
public function moveBookFiles(string $sourceDir, string $destDir): void
{
    $files = Storage::disk('books')->allFiles($sourceDir);
    $movedPaths = [];

    foreach ($files as $file) {
        $newPath = str_replace($sourceDir, $destDir, $file);
        Storage::disk('books')->move($file, $newPath);
        $movedPaths[] = Storage::disk('books')->path($newPath);
    }

    // Add destination directory to queue (will fix recursively via cron --recursive flag)
    app(PermissionsQueueService::class)->addPath(
        Storage::disk('books')->path($destDir)
    );
}
```

## Example 4: In Controller After Book Creation

```php
use App\Services\PermissionsQueueService;

class BookController extends Controller
{
    public function store(Request $request, PermissionsQueueService $permissionsQueue)
    {
        $validated = $request->validate([...]);

        // Create book record
        $bookId = $this->documentStore->createBook($validated);
        $book = $this->documentStore->getBook($bookId);

        // If book has a directory path, queue it for permissions fix
        if (!empty($book['directoryPath'])) {
            $fullPath = Storage::disk('books')->path($book['directoryPath']);
            if (file_exists($fullPath)) {
                $permissionsQueue->addPath($fullPath);
            }
        }

        return redirect()->route('admin.books.show', $bookId);
    }
}
```

## Example 5: Batch Processing

```php
public function importMultipleBooks(array $bookMetadataList): array
{
    $results = [];
    $pathsToFix = [];

    foreach ($bookMetadataList as $metadata) {
        $result = $this->importBook($metadata);
        $results[] = $result;

        if (!empty($result['directoryPath'])) {
            $pathsToFix[] = Storage::disk('books')->path($result['directoryPath']);
        }
    }

    // Add all paths at once (more efficient)
    if (!empty($pathsToFix)) {
        app(PermissionsQueueService::class)->addPaths($pathsToFix);
    }

    return $results;
}
```

## Example 6: Console Command

```php
use App\Services\PermissionsQueueService;
use Illuminate\Console\Command;

class ImportBooksCommand extends Command
{
    protected $signature = 'books:import {path}';
    protected $description = 'Import books from directory';

    public function handle(PermissionsQueueService $permissionsQueue)
    {
        $path = $this->argument('path');

        // ... import logic ...

        // After creating files
        $createdPath = '/path/to/created/book';
        $permissionsQueue->addPath($createdPath);

        $this->info("Added to permissions queue: {$createdPath}");
        $this->info("Queue size: " . $permissionsQueue->getQueueSize());
    }
}
```

## Best Practices

### 1. Queue Entire Directories Instead of Individual Files

**Good:**
```php
// Queue the parent directory once
$permissionsQueue->addPath('/audiobooks/Fantasy/Author/Book');
```

**Avoid:**
```php
// Don't queue every single file
$permissionsQueue->addPath('/audiobooks/Fantasy/Author/Book/chapter01.mp3');
$permissionsQueue->addPath('/audiobooks/Fantasy/Author/Book/chapter02.mp3');
// ... 50+ files ...
```

Then use `--recursive` flag in cron to fix all contents.

### 2. Use Dependency Injection

**Good:**
```php
class BookService
{
    public function __construct(
        protected PermissionsQueueService $permissionsQueue
    ) {}
}
```

**Also Good (for quick usage):**
```php
app(PermissionsQueueService::class)->addPath($path);
```

### 3. Check Before Adding (Optional)

```php
// Only add if not already in queue
if (!$permissionsQueue->isInQueue($path)) {
    $permissionsQueue->addPath($path);
}
```

### 4. Log for Debugging

```php
use Illuminate\Support\Facades\Log;

$permissionsQueue->addPath($bookPath);
Log::info('Added to permissions queue', ['path' => $bookPath]);
```

### 5. Handle Failures Gracefully

```php
if (!$permissionsQueue->addPath($path)) {
    Log::warning('Failed to add path to permissions queue', ['path' => $path]);
    // Continue - cron will handle it eventually
}
```

## When to Use

✅ **Do use when:**
- Creating new directories via web interface
- Uploading files via web interface
- Moving/copying files programmatically
- Creating files from templates
- Extracting archives

❌ **Don't use when:**
- Files already have correct ownership (check first)
- Operating on files in `/tmp` or other non-persistent locations
- Files are being immediately deleted

## Monitoring Integration

Add to your admin dashboard:

```php
// In AdminController or Dashboard
public function dashboard(PermissionsQueueService $permissionsQueue)
{
    $stats = [
        'pending_permissions_fixes' => $permissionsQueue->getQueueSize(),
        // ... other stats ...
    ];

    return view('admin.dashboard', compact('stats'));
}
```

In your view:
```blade
@if($stats['pending_permissions_fixes'] > 0)
    <div class="alert alert-info">
        <i class="fas fa-clock"></i>
        {{ $stats['pending_permissions_fixes'] }} file(s) waiting for permission fixes
        (will be processed automatically)
    </div>
@endif
```
