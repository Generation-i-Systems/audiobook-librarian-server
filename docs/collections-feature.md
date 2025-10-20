# Collections Feature

## Overview

Collections are a special type of series that represent curated lists of books, such as "Top 100 Sci-Fi Books" or "Best Mystery Novels". Unlike regular series where books have a narrative order, collections are thematic groupings where the number represents ranking or list position.

## Key Differences: Collections vs. Series

| Feature | Regular Series | Collection |
|---------|---------------|------------|
| **Purpose** | Narrative sequence | Curated list/ranking |
| **Number Meaning** | Book order in story | Rank/position in list |
| **Directory Structure** | Creates subdirectories | No subdirectories |
| **Primary Series** | Can be primary | Never primary |
| **Example** | "Harry Potter #3" | "#82 in Top 100 Sci-Fi" |

## Database Schema

### Series Table
- `id` - Primary key
- `name` - Series/collection name
- `is_collection` - Boolean flag (default: false)
- `created_at` - Timestamp
- `updated_at` - Timestamp

### Book-Series Pivot Table
- `book_id` - Foreign key to books
- `series_id` - Foreign key to series
- `series_number` - Position/rank in series/collection

## API Response Format

Collections are returned as part of the series array with an `is_collection` flag:

```json
{
  "series": [
    {
      "name": "The Expanse",
      "series_number": "3",
      "is_collection": false
    },
    {
      "name": "Top 100-ish Sci-Fi Books",
      "series_number": "82",
      "is_collection": true
    }
  ]
}
```

## Admin Interface

### Book Edit Form

Each series entry in the book edit form includes a "Collection" checkbox:

```
[#] [Series Name] [☑ Collection] [Edit] [Remove]
```

- Check the "Collection" box to mark a series as a collection
- The checkbox is saved with the book's series data
- Existing series can be updated to collections

## Import Collection Command

### Purpose
Import books from specially formatted collection directories where each book follows the pattern:
```
[number] - [title] - [author] - [year]
```

### Command Signature
```bash
php artisan books:import-collection [options]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--collection` | "Top 100-ish Sci-Fi Books" | Collection name |
| `--path` | `/media/lyra_data1/audiobooks/books/Science Fiction/VA/Top 100-ish Sci-Fi Books` | Source directory |
| `--dry-run` | false | Preview changes without making them |
| `--books` | (all) | Specific book directories to import |

### Examples

#### Dry Run (Preview)
```bash
php artisan books:import-collection --dry-run
```

#### Import Specific Books for Testing
```bash
php artisan books:import-collection --books="82 - The Lathe" --books="15 - Dune"
```

#### Import Different Collection
```bash
php artisan books:import-collection \
  --collection="Best Mystery Novels" \
  --path="/media/lyra_data1/audiobooks/books/Mystery/Collections/Best Mystery"
```

### What It Does

1. **Parses Directory Names**
   - Pattern: `82 - The Lathe of Heaven - Ursula K Le Guin - 1971`
   - Extracts: collection number, title, author(s), year

2. **Calculates Target Path**
   - Based on genre, author last name, and title
   - Example: `/Science Fiction/L/Ursula K Le Guin/The Lathe of Heaven`
   - No collection subdirectories created

3. **Moves Directory**
   - Relocates from collection folder to proper author/title location
   - Creates parent directories as needed

4. **Imports Book**
   - Creates book record with metadata
   - Links to authors, genres, and collection
   - Sets collection number in pivot table

5. **Enriches Metadata**
   - Queries Google Books API for description, publisher, ISBN
   - Downloads cover image if available
   - Stores enriched data with book

### Output Example

```
Collection Import Tool
Collection: Top 100-ish Sci-Fi Books
Source: /media/lyra_data1/audiobooks/books/Science Fiction/VA/Top 100-ish Sci-Fi Books
Mode: LIVE

Found 127 book(s) to process

================================================================================
Processing: 82 - The Lathe of Heaven - Ursula K Le Guin - 1971
================================================================================
+---------------+---------------------------+
| Field         | Value                     |
+---------------+---------------------------+
| Collection #  | 82                        |
| Title         | The Lathe of Heaven       |
| Author(s)     | Ursula K Le Guin          |
| Year          | 1971                      |
+---------------+---------------------------+
Source: /media/.../Science Fiction/VA/Top 100-ish Sci-Fi Books/82 - The Lathe...
Target: /media/.../Science Fiction/L/Ursula K Le Guin/The Lathe of Heaven
Attempting to enrich metadata...
✓ Metadata enriched from Google Books
Book created with enriched metadata
✓ Successfully imported book ID: 550e8400-e29b-41d4-a716-446655440000

================================================================================
Import Summary
================================================================================
Processed: 127
Skipped: 0
Errors: 0
```

## Directory Structure

### Before Import
```
/media/lyra_data1/audiobooks/books/
└── Science Fiction/
    └── VA/
        └── Top 100-ish Sci-Fi Books/
            ├── 15 - Dune - Frank Herbert - 1965/
            ├── 82 - The Lathe of Heaven - Ursula K Le Guin - 1971/
            └── ...
```

### After Import
```
/media/lyra_data1/audiobooks/books/
└── Science Fiction/
    ├── H/
    │   └── Frank Herbert/
    │       └── Dune/
    └── L/
        └── Ursula K Le Guin/
            └── The Lathe of Heaven/
```

## Usage in Application

### Filtering by Collections
Collections appear in series filters but can be distinguished by the `is_collection` flag.

### Display
- Show collection number as rank: "#82 in Top 100-ish Sci-Fi Books"
- Don't create directory hierarchies for collections
- Mark as "Collection" in UI to differentiate from regular series

### Search
Collections are searchable like regular series through the series filter.

## Best Practices

1. **Naming Collections**
   - Use descriptive names: "Top 100-ish Sci-Fi Books"
   - Include scope/theme in name
   - Be consistent with naming conventions

2. **Collection Numbers**
   - Use integers for rankings
   - Start from 1
   - Gaps are acceptable

3. **Directory Format**
   - Always follow: `[number] - [title] - [author] - [year]`
   - Use full author names
   - Use 4-digit years
   - Separate with ` - ` (space-dash-space)

4. **Import Process**
   - Always run with `--dry-run` first
   - Test with specific books using `--books` option
   - Review output before live import
   - Check logs for any errors

## Troubleshooting

### Directory Not Parsed
- Check format matches: `[number] - [title] - [author] - [year]`
- Ensure spaces around dashes
- Verify year is 4 digits

### Move Failed
- Check target doesn't already exist
- Verify write permissions on target directory
- Ensure enough disk space

### Enrichment Failed
- Check internet connectivity
- Google Books API may be rate-limited
- Not all books have metadata available

### Book Not Created
- Check application logs: `storage/logs/laravel.log`
- Verify database connectivity
- Ensure all required fields are present

## Related Documentation

- [Directory Validation System](directory-validation-system.md)
- [Book Import Process](book-import.md)
- [Series Management](series-management.md)
