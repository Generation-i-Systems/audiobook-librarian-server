# import-bk Collection Support

## Overview

The `import-bk` script now fully supports collections! When importing books, the system automatically detects collection patterns in series names and marks them appropriately.

## What Changed

### 1. Automatic Detection ✓
The AI metadata processor (`AIBookProcessor`) now automatically detects collection patterns:

```php
// Patterns that trigger collection detection:
- /top\s+\d+/i          → "Top 100", "Top 50"
- /best\s+of/i          → "Best of Sci-Fi"
- /greatest/i           → "Greatest Novels"
- /collection/i         → "Fantasy Collection"
- /anthology/i          → "Horror Anthology"
- /\d+\s*essential/i    → "100 Essential Books"
- /must\s*read/i        → "Must Read Classics"
- /classics/i           → "Science Fiction Classics"
```

### 2. Import Service Support ✓
The `BookImportService` now handles the `is_collection` flag:

- Creates series with `is_collection = true` when detected
- Updates existing series to mark as collection if needed
- Stores collection flag with book metadata

### 3. Metadata Flow ✓
```
AI Processing → Detects Pattern → Sets is_collection
                                         ↓
                              BookImportService
                                         ↓
                              Series Created/Updated
                                         ↓
                              Book Linked to Collection
```

## Usage Examples

### Example 1: Automatic Detection
```bash
# Book directory: "Top 100 Sci-Fi - Foundation - Isaac Asimov"
import-bk /path/to/book

# AI extracts:
# - series: "Top 100 Sci-Fi"
# - series_number: extracted from context
# 
# System automatically sets:
# - is_collection: true (detected "Top 100" pattern)
```

### Example 2: Manual Override
If AI doesn't detect a collection, you can manually mark it in the edit form after import.

### Example 3: Collection Import Command
For specially formatted collection directories:
```bash
php artisan books:import-collection --dry-run
```

## How It Works

### Step 1: AI Extraction
When `import-bk` processes a book, the AI extracts metadata including series information.

### Step 2: Pattern Detection
The `AIBookProcessor` checks the series name against collection patterns:

```php
if (preg_match('/top\s+\d+/i', $seriesName)) {
    $metadata['is_collection'] = true;
}
```

### Step 3: Series Creation
The `BookImportService` creates or updates the series:

```php
$series = Series::firstOrCreate(
    ['name' => $seriesName],
    ['is_collection' => $isCollection]
);
```

### Step 4: Book Linking
The book is linked to the series with the collection flag preserved.

## API Response

Books in collections will return:

```json
{
  "id": "123",
  "title": "Foundation",
  "series": [
    {
      "name": "Top 100 Sci-Fi Books",
      "series_number": "3",
      "is_collection": true
    }
  ]
}
```

## Benefits

### 1. Zero Manual Work
Collections are automatically detected during import - no need to manually mark them.

### 2. Consistent Data
All books in a collection are marked the same way.

### 3. Better Organization
Collections are distinguished from regular series in the database and API.

### 4. Flexible
Can still manually mark series as collections in the edit form if needed.

## Testing

### Test Automatic Detection
```bash
# Create a test book with collection-like series name
mkdir -p "/tmp/test-book/Top 100 Sci-Fi - Test Book - Test Author"
# Add some audio files...

# Import it
import-bk "/tmp/test-book/Top 100 Sci-Fi - Test Book - Test Author"

# Check the result - should have is_collection = true
```

### Test Manual Import
```bash
# Use the collection import command
php artisan books:import-collection --dry-run --books="03 - Foundation"
```

## Supported Collection Types

The system recognizes these collection types:

1. **Rankings** - "Top 100", "Top 50 Best"
2. **Curated Lists** - "Best of", "Greatest"
3. **Explicit Collections** - "Collection", "Anthology"
4. **Essential Lists** - "100 Essential", "Must Read"
5. **Classics** - "Classics", "Classic Collection"

## Troubleshooting

### Collection Not Detected
If a collection isn't automatically detected:

1. **Check Series Name**: Does it match a pattern?
2. **Manual Override**: Mark it in the edit form
3. **Add Pattern**: Request a new pattern to be added

### Wrong Detection
If a regular series is marked as collection:

1. **Edit Form**: Uncheck the "Collection" checkbox
2. **Report**: Let us know so the pattern can be refined

### Series Already Exists
If a series exists without the collection flag:

- The system will automatically update it when a book with `is_collection = true` is imported
- No manual intervention needed

## Related Commands

### Standard Import
```bash
import-bk /path/to/book              # Auto-detects collections
import-bk -n /path/to/book           # Dry run
import-bk -v /path/to/book           # Verbose output
```

### Collection Import
```bash
php artisan books:import-collection  # Special collection directory format
```

### Check Results
```bash
# Query books in a collection
php artisan tinker
>>> Book::whereHas('series', fn($q) => $q->where('is_collection', true))->count()
```

## Summary

The `import-bk` script now provides **automatic collection detection** with:

✅ Pattern-based detection  
✅ Automatic series creation/update  
✅ API integration  
✅ Manual override capability  
✅ Zero configuration needed  

Just run `import-bk` as usual - collections are handled automatically!
