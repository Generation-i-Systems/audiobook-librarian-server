# OpenAudible Import

## Overview
Import audiobooks from OpenAudible with complete metadata preservation. Safely imports books from OpenAudible's downloads directory into your library with full database integration.

## Features

### ✅ Complete Metadata Import
- Title, author, narrator
- Series information with sequence numbers
- Genres (multi-level hierarchies)
- Duration, release date, publisher
- ASIN, language, abridged status
- Descriptions and summaries

### ✅ File Management
- Copies .m4b audio files
- Copies cover images (.jpg, .png)
- Copies supplementary files (.pdf)
- Organizes by author/series/title
- Preserves all associated files

### ✅ Database Integration
- Creates book records
- Links authors, narrators, series, genres
- Handles existing books (skip or update)
- Transaction-safe operations
- Automatic rollback on errors

### ✅ Safety Features
- Dry-run mode
- Pre-flight validation
- Database transactions
- Automatic cleanup on errors
- No data loss guarantee

## Usage

### Basic Import
```bash
php artisan books:import-openaudible
```

### With Options
```bash
# Dry run (preview without changes)
php artisan books:import-openaudible --dry-run

# Include books from books_old directory
php artisan books:import-openaudible --include-old

# Update existing books
php artisan books:import-openaudible --force

# Limit number of books
php artisan books:import-openaudible --limit=10

# Custom source directory
php artisan books:import-openaudible --source=/path/to/OpenAudible
```

## Options

| Option | Description | Default |
|--------|-------------|---------|
| `--source` | OpenAudible directory path | `/media/audiobooks/OpenAudible` |
| `--dry-run` | Preview without making changes | false |
| `--include-old` | Import from books_old directory | false |
| `--force` | Reimport existing books | false |
| `--limit` | Maximum books to import | unlimited |

## Directory Structure

### OpenAudible Layout
```
/media/audiobooks/OpenAudible/
├── books.json              # Metadata for all books
├── books/                  # Downloaded audiobooks
│   ├── Book Title.m4b
│   ├── Book Title.jpg
│   └── Book Title.pdf
└── books_old/              # Archived books
    └── Old Book.m4b
```

### Library Layout (After Import)
```
/media/audiobooks/
├── Fantasy/
│   └── Author Name/
│       ├── Standalone Book/
│       │   ├── Standalone Book.m4b
│       │   ├── Standalone Book.jpg
│       │   └── Standalone Book.pdf
│       └── Series Name/
│           ├── 01 First Book/
│           │   └── 01 First Book.m4b
│           └── 02 Second Book/
│               └── 02 Second Book.m4b
├── Science Fiction/
│   └── Another Author/
│       └── Book Title/
│           └── Book Title.m4b
└── LitRPG/
    └── Author/
        └── Series/
            └── 01 Book One/
```

## Workflow

### 1. Pre-Flight Checks
```
✓ Validate source directory exists
✓ Validate books.json exists
✓ Validate book root exists and is writable
✓ Validate database connection
✓ Parse books.json
```

### 2. For Each Book
```
1. Extract metadata from books.json
2. Check if book already exists (by ASIN or title)
3. Find audio file in books/ or books_old/
4. Prepare destination directory
5. Copy audio file and associated files
6. Create/update book record
7. Create relationships (authors, narrators, series, genres)
8. Commit transaction
```

### 3. Error Handling
```
On error:
1. Rollback database transaction
2. Delete copied files
3. Log error details
4. Continue with next book
```

## Examples

### Import All Books
```bash
php artisan books:import-openaudible
```
Output:
```
Loading OpenAudible metadata...
Found 150 books in metadata

Processing books...
 150/150 [============================] 100%

=== Import Summary ===
Total books in metadata: 150
Imported: 145
Updated: 0
Skipped: 3
Errors: 2
```

### Preview Import
```bash
php artisan books:import-openaudible --dry-run
```
Output:
```
=== DRY RUN MODE ===
Loading OpenAudible metadata...
Found 150 books in metadata

Processing books...
 150/150 [============================] 100%

=== Import Summary ===
Total books in metadata: 150
Imported: 145
Updated: 0
Skipped: 5
Errors: 0

This was a dry run. No changes were made.
```

### Update Existing Books
```bash
php artisan books:import-openaudible --force
```

### Import from Archive
```bash
php artisan books:import-openaudible --include-old
```

### Test with Small Batch
```bash
php artisan books:import-openaudible --limit=5 --dry-run
```

## Metadata Mapping

### OpenAudible → Library

| OpenAudible Field | Library Field | Notes |
|-------------------|---------------|-------|
| `title` | `title` | Full title |
| `title_short` | Used for directory | Shorter version |
| `author` | `authors` relationship | Comma-separated |
| `narrated_by` | `narrators` relationship | Comma-separated |
| `series_name` | `series` relationship | Series name |
| `series_sequence` | `series.pivot.series_number` | Book number in series |
| `genre` | `genres` relationship | Colon-separated hierarchy |
| `asin` | `asin` | Amazon identifier |
| `description` | `description` | HTML stripped |
| `summary` | `description` (fallback) | HTML stripped |
| `seconds` | `duration` | Duration in seconds |
| `duration` | `duration` | HH:MM:SS format |
| `release_date` | `release_date` | Publication date |
| `publisher` | `publisher` | Publisher name |
| `language` | `language` | Language code |
| `abridged` | `abridged` | Boolean |
| `files` | Copied to directory | Audio, images, PDFs |

## Organization Pattern

Books are organized following the existing library structure:

### Standalone Books
```
Genre/Author/Title/
```

Example:
```
General Fiction/Stephen King/The Stand/
```

### Series Books
```
Genre/Author/Series/## Title/
```

Example:
```
Fantasy/Brandon Sanderson/Mistborn/01 The Final Empire/
Fantasy/Brandon Sanderson/Mistborn/02 The Well of Ascension/
```

Sequence numbers are zero-padded (01, 02, etc.) for proper sorting.

## Duplicate Handling

### By Default (Skip)
- Checks for existing book by ASIN
- Falls back to title match
- Skips if found
- Reports in summary

### With --force (Update)
- Finds existing book
- Updates metadata
- Moves files to new location
- Updates all relationships
- Reports as "Updated"

## Error Scenarios

### Missing Audio File
- **Cause**: File referenced in books.json doesn't exist
- **Handling**: Skip book, continue with others
- **Result**: Counted in "Skipped"

### Database Error
- **Cause**: Constraint violation, connection loss
- **Handling**: Rollback transaction, delete copied files
- **Result**: Counted in "Errors", logged

### File Copy Error
- **Cause**: Permission denied, disk full
- **Handling**: Rollback transaction
- **Result**: Counted in "Errors", logged

### Invalid Metadata
- **Cause**: Missing required fields
- **Handling**: Skip book
- **Result**: Counted in "Skipped"

## Safety Guarantees

### ✅ No Data Loss
- Original files never modified
- Files only copied, never moved
- Database transactions ensure atomicity
- Automatic rollback on any error

### ✅ No Corruption
- All-or-nothing imports per book
- Failed imports leave no partial data
- Copied files cleaned up on error
- Database consistency maintained

### ✅ Idempotent
- Safe to run multiple times
- Skips already-imported books
- Use --force to update existing

## Performance

### Benchmarks
| Books | Time | Notes |
|-------|------|-------|
| 10 | ~30s | With metadata |
| 100 | ~5min | Typical library |
| 1000 | ~50min | Large library |

### Optimization Tips
1. Use `--limit` for testing
2. Use `--dry-run` first
3. Import in batches
4. Run during off-hours

## Troubleshooting

### No Books Imported
```bash
# Check books.json exists
ls -la /media/audiobooks/OpenAudible/books.json

# Check audio files exist
ls /media/audiobooks/OpenAudible/books/*.m4b | head

# Run with dry-run to see what would happen
php artisan books:import-openaudible --dry-run
```

### Books Skipped
```bash
# Check if already imported
php artisan tinker
>>> Book::where('title', 'Book Name')->first()

# Force reimport
php artisan books:import-openaudible --force
```

### Permission Errors
```bash
# Check book root permissions
ls -la $BOOK_STORAGE_PATH

# Fix permissions
chmod 755 $BOOK_STORAGE_PATH
```

### Database Errors
```bash
# Check database connection
php artisan db:show

# Check logs
tail -f storage/logs/laravel.log
```

## Integration

### After Import
```bash
# Parse books to extract metadata
php artisan books:parse

# Verify imports
php artisan books:verify-integrity
```

### Scheduled Import
```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('books:import-openaudible --include-old')
        ->daily()
        ->at('02:00');
}
```

### Manual Workflow
```bash
# 1. Download books in OpenAudible
# 2. Preview import
php artisan books:import-openaudible --dry-run

# 3. Import
php artisan books:import-openaudible

# 4. Parse metadata
php artisan books:parse

# 5. Verify
php artisan books:verify-integrity
```

## Advanced Usage

### Custom Source
```bash
php artisan books:import-openaudible \
    --source=/mnt/backup/OpenAudible \
    --include-old
```

### Batch Processing
```bash
# Import in batches of 50
for i in {0..10}; do
    php artisan books:import-openaudible --limit=50
    sleep 60
done
```

### Selective Import
```bash
# Import only new books (skip existing)
php artisan books:import-openaudible

# Update all books
php artisan books:import-openaudible --force
```

## Logging

### Success
```
[INFO] OpenAudible import started
[INFO] Found 150 books in metadata
[INFO] Imported: Book Title by Author Name
[INFO] Import completed: 145 imported, 5 skipped
```

### Errors
```
[ERROR] OpenAudible import error
  book: Book Title
  error: Failed to copy audio file
  trace: [stack trace]
```

## Best Practices

### ✅ DO
- Run `--dry-run` first
- Import in small batches initially
- Check logs after import
- Verify a few books manually
- Keep OpenAudible files as backup

### ❌ DON'T
- Don't delete OpenAudible files immediately
- Don't run without testing first
- Don't import during heavy usage
- Don't skip verification

## Recovery

### If Import Fails Mid-Way
```bash
# Check what was imported
php artisan tinker
>>> Book::whereDate('created_at', today())->count()

# Continue import (skips existing)
php artisan books:import-openaudible
```

### If Wrong Books Imported
```bash
# Delete recent imports
php artisan tinker
>>> Book::whereDate('created_at', today())->delete()

# Reimport correctly
php artisan books:import-openaudible
```

## Testing

### Run Tests
```bash
php artisan test --filter=ImportOpenAudibleTest
```

### Test Coverage
- 25+ test cases
- All metadata fields
- All relationships
- Error scenarios
- Safety features
- Edge cases

## Support

### Check Status
```bash
# View import summary
php artisan books:import-openaudible --dry-run

# Check logs
tail -f storage/logs/laravel.log | grep OpenAudible
```

### Debug Mode
```bash
# Enable query logging
DB_LOG_QUERIES=true php artisan books:import-openaudible --limit=1
```
