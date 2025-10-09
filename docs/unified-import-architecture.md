# Unified Import Architecture

## Overview

All book imports now use a single, unified service: `UnifiedBookImporter`. This ensures consistency across all import methods and eliminates code duplication.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Import Commands (UI Layer)                │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ ImportBook   │  │OpenAudible   │  │ Web Upload   │      │
│  │   Command    │  │   Import     │  │   Import     │      │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘      │
│         │                  │                  │              │
│         └──────────────────┼──────────────────┘              │
│                            │                                 │
└────────────────────────────┼─────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│              UnifiedBookImporter (Logic Layer)               │
│  ┌────────────────────────────────────────────────────────┐ │
│  │  • Find existing books                                 │ │
│  │  • Generate directory paths                            │ │
│  │  • Handle file operations (copy/move)                  │ │
│  │  • Find/copy cover images                              │ │
│  │  • Create/update book records                          │ │
│  │  • Create relationships (authors, genres, series)      │ │
│  │  • Parse metadata (duration, genres, etc.)             │ │
│  └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                   Supporting Services                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ Genre        │  │ Book         │  │ Document     │      │
│  │ Mapping      │  │ Directory    │  │ Store        │      │
│  │ Service      │  │ Parser       │  │ Service      │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
```

## Responsibilities

### Import Commands (UI Layer)
**Responsibilities:**
- Parse command-line arguments
- Display progress and feedback to user
- Handle user interactions (prompts, confirmations)
- Collect statistics
- Format output

**NOT Responsible For:**
- File operations
- Database operations
- Metadata parsing
- Path generation
- Relationship creation

### UnifiedBookImporter (Logic Layer)
**Responsibilities:**
- All import logic
- File operations (copy/move)
- Database operations (create/update)
- Metadata normalization
- Path generation
- Cover image handling
- Relationship management
- Duplicate detection

**NOT Responsible For:**
- User interface
- Progress display
- Statistics formatting

## Usage

### Basic Import

```php
use App\Services\UnifiedBookImporter;

$importer = app(UnifiedBookImporter::class);

$result = $importer->importBook($bookData, [
    'source_path' => '/path/to/book',
    'dry_run' => false,
    'force' => false,
]);

// Result structure:
// [
//     'status' => 'imported|updated|skipped|error',
//     'book' => Book|null,
//     'reason' => string|null,
//     'error' => string|null,
// ]
```

### With Options

```php
$result = $importer->importBook($bookData, [
    'source_path' => '/path/to/book',
    'dry_run' => true,              // Preview only
    'force' => true,                // Update existing
    'duplicate_action' => 'replace', // replace|merge|skip
]);
```

## Book Data Format

The `UnifiedBookImporter` accepts normalized book data:

```php
$bookData = [
    // Required
    'title' => 'Book Title',
    
    // Highly Recommended
    'author' => 'Author Name' | ['Author 1', 'Author 2'],
    'genre' => 'Fantasy' | 'Fantasy:Epic:Dragons',
    
    // Optional
    'title_short' => 'Short Title',
    'description' => 'Book description',
    'summary' => 'Book summary',
    'asin' => 'B01234567',
    'product_id' => 'BK_ACX0_123456',
    'release_date' => '2024-01-01',
    'publisher' => 'Publisher Name',
    'language' => 'en',
    'abridged' => 'true|false',
    'duration' => 3600 | '01:00:00',
    'seconds' => 3600,
    'cover_image' => 'cover.jpg',
    
    // Series
    'series' => 'Series Name',
    'series_name' => 'Series Name',
    'series_number' => 1,
    'series_sequence' => '1',
    
    // Narrators
    'narrator' => 'Narrator Name' | ['Narrator 1', 'Narrator 2'],
    'narrated_by' => 'Narrator Name',
    
    // Directory
    'directory_path' => 'Fantasy/Author/Series/01 Title',
];
```

## Import Sources

### 1. Manual Import (import-bk)

```php
// Command: ImportBook
$bookData = $parser->parseDirectory($path);
$result = $importer->importBook($bookData, [
    'source_path' => $path,
]);
```

### 2. OpenAudible Import

```php
// Command: ImportOpenAudible
$bookData = $this->normalizeOpenAudibleData($rawData);
$result = $importer->importBook($bookData, [
    'source_path' => $audioFilePath,
    'duplicate_action' => $action,
]);
```

### 3. Web Upload Import

```php
// Controller: UploadController
$bookData = $this->extractMetadata($uploadedFile);
$result = $importer->importBook($bookData, [
    'source_path' => $tempPath,
]);
```

### 4. Directory Scan Import

```php
// Command: ParseBooksCommand
$bookData = $parser->parseDirectory($directory);
$result = $importer->importBook($bookData, [
    'source_path' => $directory,
]);
```

## Features

### Duplicate Detection
- By ASIN
- By directory path
- By title + author

### File Operations
- **Copy**: Files copied to library (default)
- **Move**: Files moved to library
- **In-place**: Files already in library, database only

### Duplicate Actions
- **Replace**: Delete old audio files, use new ones
- **Merge**: Keep old audio, add new non-audio files
- **Skip**: Leave existing unchanged

### Path Generation
- Genre/Author/Series/Title (default)
- Handles series with sequence numbers
- Sanitizes invalid characters
- Maps genres to existing directories

### Cover Images
- Prioritizes files with 'cover' in name
- Falls back to first image found
- Supports: jpg, jpeg, png, gif, webp

### Relationships
- Authors (multiple supported)
- Narrators (multiple supported)
- Genres (with primary/secondary)
- Series (with sequence numbers)

## Migration Guide

### Before (Old Way)

```php
// Each command had its own logic
class ImportOpenAudible extends Command
{
    private function processBook($bookData) {
        // Duplicate logic
        $book = Book::create([...]);
        $author = Author::firstOrCreate([...]);
        $book->authors()->attach($author);
        // etc...
    }
}
```

### After (New Way)

```php
// Commands only handle UI
class ImportOpenAudible extends Command
{
    private UnifiedBookImporter $importer;
    
    private function processBook($bookData) {
        $result = $this->importer->importBook($bookData, [
            'source_path' => $path,
        ]);
        
        // Handle result for UI display
        match($result['status']) {
            'imported' => $this->info("Imported: {$bookData['title']}"),
            'updated' => $this->info("Updated: {$bookData['title']}"),
            'skipped' => $this->warn("Skipped: {$bookData['title']}"),
            'error' => $this->error("Error: {$result['error']}"),
        };
    }
}
```

## Benefits

1. **Consistency**: All imports work the same way
2. **Maintainability**: Fix bugs in one place
3. **Testability**: Test logic separate from UI
4. **Flexibility**: Easy to add new import sources
5. **Reliability**: Centralized error handling
6. **Reusability**: Logic available to all commands

## Testing

```php
use Tests\TestCase;
use App\Services\UnifiedBookImporter;

class UnifiedBookImporterTest extends TestCase
{
    public function test_imports_book_with_metadata()
    {
        $importer = app(UnifiedBookImporter::class);
        
        $result = $importer->importBook([
            'title' => 'Test Book',
            'author' => 'Test Author',
        ], [
            'source_path' => '/test/path',
        ]);
        
        $this->assertEquals('imported', $result['status']);
        $this->assertInstanceOf(Book::class, $result['book']);
    }
}
```

## Future Enhancements

- [ ] Batch import support
- [ ] Progress callbacks
- [ ] Metadata enrichment hooks
- [ ] Custom path templates
- [ ] Automatic duplicate resolution
- [ ] Import validation rules
- [ ] Rollback support
- [ ] Import history tracking
