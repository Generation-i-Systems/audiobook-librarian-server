# Embedded Cover Extraction

## Overview

M4B audiobook files often contain embedded cover images. This feature ensures that embedded covers are extracted and saved to the book directory during import.

## Priority Order

When importing a book, covers are handled in this priority order:

1. **Existing cover in directory** - If `cover.jpg` already exists, use it (don't overwrite)
2. **Embedded cover from M4B** - Extract from file tags and save as `cover.jpg`
3. **Local cover file** - Copy from `metadata['cover_path']`
4. **Downloaded cover** - Download from `metadata['cover_url']`

## Implementation

### Cover Data Extraction

The embedded cover is extracted in `ImportBooksFromDownloads::processAudiobook()`:

```php
// Extract embedded cover image if available
if (!empty($fileTags['picture']['data'])) {
    $this->line("  Found embedded cover image in M4B file");
    $aiMetadata['cover_data'] = $fileTags['picture']['data'];
    $aiMetadata['cover_source'] = 'Embedded in M4B';
}
```

### Cover Data Saving

The cover data is saved in `BookImportService::createBookFromMetadata()`:

```php
elseif (!empty($metadata['cover_data'])) {
    // Save embedded cover from M4B file
    $coverPath = $this->saveEmbeddedCover($metadata['cover_data'], $book->directory_path);
    if ($coverPath) {
        $book->cover_image = $coverPath;
    }
}
```

### Method: `saveEmbeddedCover()`

```php
protected function saveEmbeddedCover(string $coverData, string $directoryPath): ?string
{
    // Convert relative path to absolute
    $bookRoot = rtrim(config('app.book_root'), '/');
    $absoluteDir = $bookRoot . '/' . ltrim($directoryPath, '/');
    
    // Create directory if needed
    if (!is_dir($absoluteDir)) {
        mkdir($absoluteDir, 0775, true);
    }
    
    // Save as cover.jpg
    $filename = 'cover.jpg';
    $filePath = "{$absoluteDir}/{$filename}";
    
    if (file_put_contents($filePath, $coverData)) {
        chmod($filePath, 0664);
        return $filename;
    }
    
    return null;
}
```

## Testing

### Test File: `tests/Unit/Services/EmbeddedCoverExtractionTest.php`

Three tests ensure the feature works correctly:

1. **`embedded_cover_data_is_saved_to_directory()`**
   - Verifies embedded cover is saved to book directory
   - Checks file exists and contains correct data

2. **`embedded_cover_has_priority_over_download()`**
   - Ensures embedded cover is used instead of downloading
   - Even when `cover_url` is provided

3. **`existing_cover_has_priority_over_embedded()`**
   - Verifies existing covers are not overwritten
   - Embedded cover is ignored if file already exists

### Running Tests

```bash
# Run embedded cover tests
php artisan test --filter=EmbeddedCoverExtractionTest

# Run all import tests
composer test:import
```

## Regression Prevention

This feature was previously working but broke due to refactoring. The tests ensure:

- ✅ Embedded covers are always extracted from M4B files
- ✅ Cover data is saved to the correct directory
- ✅ Priority order is respected
- ✅ Existing covers are never overwritten
- ✅ No downloads when embedded cover exists

## File Locations

- **Service**: `app/Services/BookImportService.php`
- **Command**: `app/Console/Commands/ImportBooksFromDownloads.php`
- **Tests**: `tests/Unit/Services/EmbeddedCoverExtractionTest.php`

## Example

```php
$metadata = [
    'title' => 'In Our Stars',
    'author' => ['Jack Campbell'],
    'cover_data' => $embeddedCoverBinaryData, // Extracted from M4B
    'cover_source' => 'Embedded in M4B',
];

$book = $importService->createBookFromMetadata($metadata, $audiobook);
// Result: cover.jpg saved to book directory
// book->cover_image = 'Science Fiction/Jack Campbell/The Doomed Earth/01 In Our Stars/cover.jpg'
```

## Troubleshooting

### Cover not extracted?

1. Check if M4B file has embedded cover:
   ```bash
   ffprobe file.m4b 2>&1 | grep -i picture
   ```

2. Check logs for extraction errors:
   ```bash
   tail -f storage/logs/laravel.log | grep -i cover
   ```

3. Verify directory permissions:
   ```bash
   ls -la /media/lyra_data1/audiobooks/books/[book-path]/
   ```

### Cover overwritten?

- Existing covers should NEVER be overwritten
- If this happens, check test: `existing_cover_has_priority_over_embedded`
- File a bug report with details

## Related Documentation

- [Import System Testing](IMPORT-TESTING.md)
- [User Approval Guarantee](USER-APPROVAL-GUARANTEE.md)
- [Collections Feature](collections-feature.md)
