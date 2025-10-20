# Fix James Axler Deathlands Command

## Overview

The `books:fix-james-axler-deathlands` command is a one-off fix script designed to clean up and standardize James Axler Deathlands series books that have various issues from failed imports and inconsistent metadata.

## Usage

```bash
# Dry run to see what would be changed
php artisan books:fix-james-axler-deathlands --dry-run

# Run with automatic backup
php artisan books:fix-james-axler-deathlands

# Run without backup (not recommended)
php artisan books:fix-james-axler-deathlands --no-backup
```

## Options

- `--dry-run`: Preview changes without making any modifications
- `--no-backup`: Skip automatic database backup (not recommended)

## What It Fixes

### 1. Series Standardization
- **Before**: `Deathlands #3`, `Deathlands`, etc.
- **After**: `Deathlands (GraphicAudio)`

### 2. Series Number Extraction
Extracts series numbers from multiple sources in priority order:
1. Series pivot table (`series_number` field)
2. Directory path with smart detection:
   - `03 008 Ice And Fire` → extracts `8` (ignores `03` failed import marker)
   - `110 Sins Of Honor` → extracts `110`
   - `03 Some Title` → returns null (only failed import marker, no real number)
3. Title prefix (e.g., `110 Sins Of Honor`)

**Special handling**: 
- Detects double-number pattern where `01`, `02`, or `03` prefix was added by failed import
- Extracts the second number as the real series number
- Removes leading zeros from extracted numbers

### 3. Title Cleanup
- **Before**: `110 Sins Of Honor`
- **After**: `Sins Of Honor`

Removes number prefixes from titles.

### 4. Genre Correction
- **Before**: `Action`
- **After**: `Science Fiction`

### 5. Duplicate Merging
Books are only considered duplicates if they have BOTH:
- The same series number (e.g., 110)
- The same normalized title (ignoring numbers and parenthetical content)

For example:
- `110 Sins Of Honor` and `110 Sins Of Honor` → **Duplicates** (same number, same title)
- `072 Atlantis Reprise (Altered States #2)` and `072 Different Book` → **NOT duplicates** (same number, different titles)

When true duplicates are found:
- Keeps the most complete book (based on completeness score)
- Merges files from duplicate directories
- Deletes duplicate database entries

### 6. Directory Path Standardization
- **Format**: `Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/[number] [title]`
- **Number Format**: 3-digit zero-padded (001, 002, ..., 110)
- **Examples**: 
  - `Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/008 Ice And Fire`
  - `Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/047 Gaia's Demise`
  - `Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/110 Sins Of Honor`

## Completeness Score

When merging duplicates, the command calculates a completeness score based on:
- Basic fields (title, description, cover, release date, duration, publisher)
- Relationships (authors, narrators, genres, series)
- Audio file count

The book with the highest score is kept.

## Example Output

```
Finding James Axler Deathlands books...
Found 120 James Axler Deathlands books

Found 2 duplicate books for #110 "sins of honor"
Keeping book ID 9019 (110 Sins Of Honor)
Merging and deleting book ID 9021 (110 Sins Of Honor)
Moved file: 110 Sins Of Honor.m4b
Removed empty directory: Action/James Axler/Deathlands/Deathlands (GraphicAudio)/03 110 Sins Of Honor

Book ID 9019: Fixing series to "Deathlands (GraphicAudio)" #110
Book ID 9019: Setting genre to "Science Fiction"
Book ID 9019: Fixing title from "110 Sins Of Honor" to "Sins Of Honor"
Book ID 9019: Fixing directory from "Action/James Axler/Deathlands/Deathlands (GraphicAudio)/03 110 Sins Of Honor" to "Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/110 Sins Of Honor"

Book ID 9070: Fixing series to "Deathlands (GraphicAudio)" #8
Book ID 9070: Setting genre to "Science Fiction"
Book ID 9070: Fixing title from "008 Ice And Fire" to "Ice And Fire"
Book ID 9070: Fixing directory from "Science Fiction/James Axler/Deathlands (GraphicAudio)/03 008 Ice And Fire" to "Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/008 Ice And Fire"

Book ID 8440: Fixing series to "Deathlands (GraphicAudio)" #72
Book ID 8440: Setting genre to "Science Fiction"
Book ID 8440: Fixing title from "072 Atlantis Reprise (Altered States #2)" to "Atlantis Reprise (Altered States #2)"

Book ID 8355: Fixing series to "Deathlands (GraphicAudio)" #47
Book ID 8355: Setting genre to "Science Fiction"
Book ID 8355: Fixing title from "047 Gaia's Demise (The Baronies Trilogy #2)" to "Gaia's Demise (The Baronies Trilogy #2)"

Summary:
Series fixed: 120
Genres fixed: 120
Titles fixed: 118
Directories fixed: 115
Duplicates merged: 2
Errors: 0
```

## Safety Features

1. **Automatic Backup**: Creates database backup before making changes (unless `--no-backup` is used)
2. **Dry Run Mode**: Preview all changes without modifying anything
3. **File Safety**: Checks for directory existence before moving files
4. **Error Handling**: Catches and reports errors without stopping the entire process

## Technical Details

### Book Selection Criteria
Finds books where:
- Author is "James Axler"
- AND (Series contains "Deathlands" OR directory path contains "Deathlands")

### Series Number Extraction Logic
```
1. Check series pivot table for series_number
2. If not found, extract from directory path using pattern: /\/(\d+)(?:\s+(\d+))?\s+/
   - If two numbers found (e.g., "03 008"):
     * Check if first is 01, 02, or 03 (failed import marker)
     * If yes, use second number as series number
     * If no, use first number
   - If one number found:
     * Skip if it's ONLY 01, 02, or 03 with no second number
     * Otherwise use that number
3. If not found, extract from title using pattern: /^(\d+)\s+/
4. Remove leading zeros from extracted number (except standalone "0")
```

**Examples**:
- `03 008 Ice And Fire` → extracts `8` (second number, first is failed import marker)
- `110 Sins Of Honor` → extracts `110` (single number, not a failed import marker)
- `03 Some Title` → returns `null` (only failed import marker, no real series number)

### Duplicate Detection Logic
Books are considered duplicates only if BOTH conditions are met:
1. **Same series number** (after normalization)
2. **Same normalized title** where normalization:
   - Removes leading numbers (e.g., "110 ")
   - Removes parenthetical content (e.g., "(Altered States #2)")
   - Normalizes whitespace
   - Converts to lowercase

Examples:
- `110 Sins Of Honor` → normalized to `sins of honor`
- `072 Atlantis Reprise (Altered States #2)` → normalized to `atlantis reprise`
- `047 Gaia's Demise (The Baronies Trilogy #2)` → normalized to `gaia's demise`

### Directory Path Format
```
Genre/Author/Series/SeriesWithPublisher/Number Title

Where Number is 3-digit zero-padded (001, 002, ..., 110)

Examples:
Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/008 Ice And Fire
Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/047 Gaia's Demise
Science Fiction/James Axler/Deathlands/Deathlands (GraphicAudio)/110 Sins Of Honor
```

## Testing

The command includes comprehensive test coverage:

```bash
php artisan test --filter=FixJamesAxlerDeathladsCommandTest
```

Tests verify:
- Series number extraction from various sources
- Handling of failed import numbers (01, 02, 03)
- Completeness score calculation
- Directory path generation
- Dry run mode functionality

## Related Commands

- `books:remove-duplicates`: General duplicate removal
- `books:fix-directory-paths`: General directory path fixes
- `books:resolve-duplicate-paths`: Interactive duplicate resolution

## Notes

- This is a **one-off fix script** designed specifically for James Axler Deathlands books
- Always run with `--dry-run` first to preview changes
- The command will create a database backup by default
- File operations are logged for troubleshooting
