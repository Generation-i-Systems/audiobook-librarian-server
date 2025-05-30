# Audiobook Directory Parser

A powerful tool for scanning directories of audiobook files and extracting metadata from file and directory names.

## Features

- Scans directories recursively for audiobook files
- Extracts metadata including:
  - Title
  - Author
  - Series and series number
  - Narrator
  - Edition information
  - File details (size, modification time, etc.)
- Supports multiple output formats (JSON, CSV, SQL, table)
- Configurable file extensions and filters
- Command-line interface for easy integration

## Installation

The book parser is included in the Audiobook Librarian application. No additional installation is required.

## Configuration

Configuration options are available in `config/bookparser.php`. You can publish the config file using:

```bash
php artisan vendor:publish --provider="App\Providers\BookParserServiceProvider" --tag=config
```

### Configuration Options

- `extensions`: Array of file extensions to include (default: common audio formats)
- `min_file_size`: Minimum file size in bytes (default: 100KB)
- `max_depth`: Maximum directory depth to scan (default: 10)
- `exclude_dirs`: Array of directory patterns to exclude
- `default_output_format`: Default output format (json, csv, sql, table)
- `default_output_path`: Default path for saving output
- `database_table`: Table name for SQL output
- `metadata_fields`: Default values for metadata fields

## Usage

### Command Line

Use the `books:parse` command to scan directories for audiobooks:

```bash
# Basic usage
php artisan books:parse /path/to/audiobooks

# With options
php artisan books:parse /path/to/audiobooks \
    --output=json \
    --extensions=m4b,mp3 \
    --limit=10 \
    --verbose
```

#### Available Options

- `path`: Directory to scan (default: current directory)
- `--output`: Output format (json, csv, sql, table) (default: table)
- `--limit`: Maximum number of books to process (0 for no limit) (default: 0)
- `--extensions`: Comma-separated list of file extensions to include
- `--min-size`: Minimum file size in bytes
- `--max-depth`: Maximum directory depth to scan
- `--dry-run`: Show what would be done without making any changes
- `--verbose`: Show more detailed output

### Programmatic Usage

You can also use the `BookDirectoryParser` service directly in your code:

```php
use App\Services\BookDirectoryParser;

$parser = app(BookDirectoryParser::class);
$books = $parser->parseDirectory('/path/to/audiobooks', [
    'extensions' => ['m4b', 'mp3'],
    'min_file_size' => 1024 * 100, // 100KB
    'max_depth' => 5,
]);

// Process the books
foreach ($books as $book) {
    // $book contains all extracted metadata
    echo "Found: {$book['title']} by {$book['author']}\n";
}
```

## Output Formats

### Table (Default)

Displays a formatted table with the most important fields.

### JSON

Outputs the complete book data as a JSON array of objects.

### CSV

Outputs the book data as comma-separated values, with headers in the first row.

### SQL

Generates SQL INSERT statements for inserting the book data into a database table.

## Metadata Extraction Rules

The parser uses the following rules to extract metadata from file and directory names:

### Title

- The title is typically the filename without extension
- Common patterns like "Book Title - Author Name" are handled
- Bracketed information is removed (e.g., "[Unabridged]")

### Author

- Extracted from parent directory names
- Common patterns like "Author Name - Book Title" are handled
- Multiple authors are supported when separated by commas or "&"

### Series and Series Number

- Extracted from patterns like "Series Name 01 - Book Title"
- Also supports "Book Title (Series Name #1)" format
- Roman numerals are converted to numbers (e.g., "Book II" → 2)

### Narrator

- Extracted from patterns like "[Narrated by John Doe]" or "[John Doe]"
- Also supports "narr. John Doe" and similar variations

### Edition

- Extracted from patterns like "[Unabridged]", "[Dramatized]", etc.
- Also detects special editions like "Graphic Audio" or "Audible Original"

## Testing

Run the test suite to verify the parser's functionality:

```bash
php artisan test tests/Feature/BookDirectoryParserTest.php
```

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
