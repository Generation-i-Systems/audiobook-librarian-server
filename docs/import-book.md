# Import Book - Quick Import Tool

Import audiobooks from any filesystem location into your library with a simple command.

## Installation

To make `import-bk` available system-wide, create a symlink in a directory that's in your PATH:

```bash
# Example: Link to ~/bin (make sure ~/bin is in your PATH)
ln -s /home/eric-22/PhpstormProjects/ab5/bin/import-bk ~/bin/import-bk

# Or link to /usr/local/bin (requires sudo)
sudo ln -s /home/eric-22/PhpstormProjects/ab5/bin/import-bk /usr/local/bin/import-bk
```

## Usage

### Basic Usage

```bash
# Import current directory (if it has audio files)
import-bk

# Import specific book directory
import-bk /path/to/book/directory

# Import multiple books
import-bk /path/book1 /path/book2 /path/book3

# Import single audio file (imports its parent directory)
import-bk /path/to/audiobook.m4b
```

### Options

```bash
# Dry run - see what would be imported without making changes
import-bk --dry-run /path/to/book

# Force reimport of existing books
import-bk --force /path/to/book

# Add all imported books to a specific collection
import-bk --collection="Sci-Fi Favorites" /path/to/books/*

# Override or set a default genre for all imported books
import-bk --genre="Fantasy" /path/to/books/*

# Use a custom storage pattern
import-bk --pattern="[genre]/VA/[series]/[title] ([author])" /path/to/books/*
```

### Custom Patterns

The `--pattern` option supports placeholders that are replaced with metadata:

- `[genre]`: The primary genre of the book
- `[author]`: The normalized author name(s)
- `[series]`: The series name
- `[series_number]`: The number in the series (e.g., 01, 02)
- `[title]`: The book title
- `[year]`: The publication year
- `[narrator]`: The narrator name(s)

Example: `--pattern="[genre]/[author]/([year]) [title]"`
Result: `Science Fiction/Brandon Sanderson/(2010) The Way of Kings`

## Behavior

### Books Already in Library

If a book directory is already within the library structure (`/media/audiobooks/`):

- **Files are NOT moved or copied**
- **Database is updated** with current metadata
- Useful for refreshing metadata after manual edits

### Books Outside Library

If a book directory is outside the library structure:

- **Files are copied** to the appropriate location in the library
- **Directory structure is created** based on metadata (Genre/Author/Series/Title)
- **Database record is created** with all metadata
- Original files remain unchanged

### Metadata Detection

The tool reads metadata from:

1. **`.abs` files** (metadata.abs in the directory)
2. **Directory structure** (parses Genre/Author/Series/Title from path)
3. **Audio file tags** (if available)

### Duplicate Handling

- Books are matched by **directory path** or **title**
- Existing books are **skipped** unless `--force` is used
- With `--force`, existing books are **updated** with new metadata

## Examples

### Example 1: Import from Downloads

```bash
cd ~/Downloads/New\ Audiobook
import-bk
```

Output:

```
No paths provided, using current directory: /home/user/Downloads/New Audiobook
Starting import...

Processing: /home/user/Downloads/New Audiobook
  ✓ Imported to library

═══════════════════════════════════════════════════════════════
  Import Summary
═══════════════════════════════════════════════════════════════
  Total processed:  1
  Imported:        1
  Updated:         0
  Skipped:         0
  Errors:          0
═══════════════════════════════════════════════════════════════
```

### Example 2: Batch Import

```bash
import-bk /media/external/audiobooks/*
```

Imports all subdirectories from the external drive.

### Example 3: Update Existing Book

```bash
cd /media/audiobooks/Fantasy/Brandon\ Sanderson/Mistborn/01\ The\ Final\ Empire
import-bk --force
```

Updates the database record without moving files.

### Example 4: Dry Run

```bash
import-bk --dry-run ~/Downloads/audiobooks/*
```

Shows what would be imported without making any changes.

## Directory Structure

Books are organized following the library pattern:

### Standalone Books

```
Genre/Author/Title/
```

Example:

```
/media/audiobooks/General Fiction/Stephen King/The Stand/
```

### Series Books

```
Genre/Author/Series/## Title/
```

Example:

```
/media/audiobooks/Fantasy/Brandon Sanderson/Mistborn/01 The Final Empire/
/media/audiobooks/Fantasy/Brandon Sanderson/Mistborn/02 The Well of Ascension/
```

## Metadata Files

The tool looks for `metadata.abs` files in the format:

```ini
title=Book Title
author=Author Name
narrator=Narrator Name
series=Series Name
series_sequence=1
genre=Fantasy
description=Book description here
duration=36000
release_date=2024-01-01
publisher=Publisher Name
asin=B01234567
```

## Troubleshooting

### "No valid book data found"

- Ensure the directory contains audio files (`.m4b`, `.m4a`, or `.mp3`)
- Check that metadata can be parsed from directory structure or `.abs` file
- Verify the directory path follows a recognizable pattern

### "Sanity check failed: destination directory contains no audio files"

- This indicates the import completed file operations but the destination folder ended up with no audio files (e.g., only images/text)
- Ensure the source directory contains supported audio files and that permissions allow copying/moving them
- Re-run the import with `--dry-run` to confirm the expected source paths and detected files

### "Book already exists"

- Use `--force` to update existing books
- Check if the book is already in the database with a different path

### Permission Errors

- Ensure you have write permissions to `/media/audiobooks/`
- Check that the script has execute permissions: `chmod +x bin/import-bk`

## Advanced Usage

### Using Artisan Directly

```bash
cd /home/eric-22/PhpstormProjects/ab5
php artisan books:import /path/to/book
```

### Scripting

```bash
#!/bin/bash
# Import all books from external drive
for dir in /media/external/audiobooks/*/; do
    import-bk "$dir"
done
```

## See Also

- [OpenAudible Import](./openaudible-import.md) - For importing from OpenAudible
- [Parse Books Command](./parse-books.md) - For scanning the entire library
