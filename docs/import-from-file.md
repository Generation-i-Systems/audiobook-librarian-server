# Import-from-File Feature Documentation

## Overview

The Import-from-File feature allows administrators to easily add books to the library by directly importing audio files. This feature streamlines the book addition process by automatically extracting metadata from audio files, importing cover images, and creating book records with minimal manual input.

## Features

- Browse and select audio files/directories from configured import roots
- Automatic metadata extraction from audio files (ID3 tags, etc.)
- Cover image extraction and import
- Series name and number detection
- One-click import with form pre-filling
- AJAX-based processing for smooth user experience

## Usage Instructions

### Accessing the Import Feature

1. Log in as an administrator
2. Navigate to the Admin Dashboard
3. Click on "Books" in the main navigation
4. Select "Import from File/Audio" from the dropdown menu

### Importing a Book

1. **Select Import Root**: Choose from the available import root directories configured in your system
2. **Browse Files**: Navigate through the directory structure to find the audio file or directory you want to import
3. **Select File/Directory**: Click on the audio file or directory you want to import
4. **Extract Metadata**: The system will automatically extract available metadata from the selected file(s)
5. **Review Metadata**: Verify the extracted metadata displayed in the summary panel
6. **Import Options**:
   - Click "Import and Prefill Book Form" to create a book record and redirect to the edit page for additional adjustments
   - Click "Move to Library" to move the selected file(s) to your library storage location

### Multi-Book Imports: Sticky Genre

When importing multiple books in a row from the same batch:

- After you manually select a genre for one imported book, that genre is remembered for the rest of the import session.
- For subsequent books where the system cannot automatically determine a genre, the import UI will default to the previously selected genre.

### Metadata Extraction

The system attempts to extract the following metadata from audio files:

- Title
- Author(s)
- Genre(s)
- Narrator(s)
- Series name and number
- Description
- Publication year
- Publisher
- ISBN
- Cover image

## Technical Details

### Supported File Formats

- M4B (preferred)
- MP3
- M4A
- AAC
- FLAC
- OGG

### Import Metadata

When a book is imported using this feature, additional metadata is stored with the book record:

- `import_path`: The original path of the imported file/directory
- `import_root`: The root directory from which the file was imported
- `import_type`: The type of import (file or directory)
- `imported_at`: Timestamp of when the import was performed

### Series Data Handling

Series information is stored with the `seriesName` field (not `name`) to match the MongoDB schema. For example:

```json
"series": [
  {
    "seriesName": "The Expanse",
    "number": "1"
  }
]
```

## Troubleshooting

### Common Issues

1. **No metadata extracted**: Ensure your audio files have proper ID3 tags or other metadata embedded
2. **Missing cover image**: The system looks for embedded cover art or image files in the same directory
3. **Import fails**: Check that the file format is supported and the file is not corrupted

### Error Messages

- "Unable to extract metadata": The file format may not be supported or the file may be corrupted
- "Failed to move file": Check file permissions and available disk space
- "Invalid import data": Ensure required fields (title, author, genre) are present in the metadata

## Configuration

Import roots are configured in the `.env` file:

```
IMPORT_ROOTS=/path/to/import/root1,/path/to/import/root2
```

## Keyboard Shortcuts

- `Up`/`Down` arrows: Navigate through file/directory list
- `Enter`: Select highlighted file/directory
- `Backspace`: Navigate to parent directory
