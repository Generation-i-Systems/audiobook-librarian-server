# AI Tool-Based Query System

## Overview

The AI Tool-Based Query System provides a flexible, powerful way to interact with your audiobook library database and filesystem using natural language queries. Unlike the previous SQL-generation approach, this system uses Gemini 2.5 Flash with function calling to dynamically execute operations based on your requests.

## Key Features

- **Natural Language Queries**: Ask questions in plain English
- **Flexible Operations**: Handles unplanned queries without hardcoding
- **Database Access**: Search, analyze, and update book metadata
- **Filesystem Operations**: List directories, scan files, move files with preview
- **Series Analysis**: Find gaps, duplicates, detect patterns
- **Bulk Operations**: Preview and apply changes to multiple books
- **Book Management**: Create, update, and delete books with automatic backups
- **External Integration**: Fetch metadata from Google Books, Audible, Hardcover
- **Audio Metadata**: Extract ID3 tags, chapters, and technical details from files
- **Media Server Support**: Generate NFO files for Kodi/Plex
- **Safety First**: Preview mode, confirmations, automatic backups, trash system
- **Iterative Reasoning**: AI can chain multiple tool calls to answer complex questions

## Architecture

```
User Query → AIToolService → Gemini 2.5 Flash (function calling)
                ↓
          Tool Execution (ToolExecutor)
                ↓
          Database/Filesystem Operations
                ↓
          Results → AI → Natural Language Response
```

### Components

1. **AIToolService** (`app/Services/AI/AIToolService.php`)
   - Manages conversation with Gemini
   - Handles tool calling loop
   - Formats responses

2. **ToolDefinitions** (`app/Services/AI/ToolDefinitions.php`)
   - Defines all available tools and their parameters
   - Provides tool schemas for AI

3. **ToolExecutor** (`app/Services/AI/ToolExecutor.php`)
   - Executes tool requests
   - Implements database and filesystem operations
   - Returns structured results

4. **AIQueryController** (`app/Http/Controllers/Admin/AIQueryController.php`)
   - Web API endpoints for tool-based queries
   - Stores query history

## Available Tools

### Database Operations

#### `search_books`
Search for books with flexible criteria.

**Parameters:**
- `query` (string): General search across title, author, series
- `title` (string): Search by title
- `author` (string): Search by author name
- `series` (string): Search by series name
- `genre` (string): Filter by genre
- `narrator` (string): Search by narrator
- `limit` (integer): Max results (default: 100)

**Example Queries:**
- "Find all Science Fiction books by Isaac Asimov"
- "Show me books narrated by R.C. Bray"
- "Search for books with 'Foundation' in the title"

#### `analyze_series`
Analyze a series for gaps, duplicates, and patterns.

**Parameters:**
- `series_name` (string): Name of series to analyze
- `series_id` (integer): ID of series (if known)

**Returns:**
- Total books in series
- Gaps in numbering (missing books)
- Duplicate entries
- Detected naming pattern

**Example Queries:**
- "Find missing books in the Deathlands series"
- "Analyze the Foundation series for gaps"
- "Show duplicates in Honor Harrington series"

#### `get_series_details`
Get detailed information about a series.

**Parameters:**
- `series_name` (string): Series name
- `series_id` (integer): Series ID
- `include_books` (boolean): Include full book list (default: true)

**Example Queries:**
- "Show all books in the Wheel of Time series"
- "Get details about the Dresden Files series"

#### `get_book_details`
Get complete details about a specific book.

**Parameters:**
- `book_id` (integer): Book ID
- `title` (string): Book title (if ID not known)

**Example Queries:**
- "Show details for book ID 12345"
- "Get information about 'The Way of Kings'"

#### `search_authors`, `search_genres`, `search_narrators`
Search for authors, genres, or narrators with book counts.

**Parameters:**
- `name` (string): Name to search for
- `include_books` (boolean): Include book list
- `limit` (integer): Max results

**Example Queries:**
- "Show all authors starting with 'Brandon'"
- "List all genres"
- "Find narrators with 'Scott' in their name"

### Filesystem Operations

#### `list_directory`
List files and directories at a path.

**Parameters:**
- `path` (string): Directory path (relative to book root)
- `recursive` (boolean): List recursively
- `file_types` (array): Filter by extensions (e.g., ["m4b", "mp3"])

**Example Queries:**
- "List all files in Science Fiction/Isaac Asimov/"
- "Show all m4b files in the Fantasy directory"

#### `scan_book_files`
Scan a book's directory for audio files.

**Parameters:**
- `book_id` (integer): Book ID
- `directory_path` (string): Directory path

**Example Queries:**
- "What audio files does book 12345 have?"
- "Scan files for 'The Way of Kings'"

#### `check_files_exist`
Verify files exist for books or paths.

**Parameters:**
- `book_ids` (array): Book IDs to check
- `paths` (array): File paths to check

**Example Queries:**
- "Check if files exist for books in the Deathlands series"
- "Verify files for all orphaned database entries"

#### `preview_file_move`
Preview file move operations before executing.

**Parameters:**
- `moves` (array): Array of {book_id, from_path, to_path} objects

**Returns:**
- Preview of all moves
- Conflicts (destination exists, source missing)
- Can proceed flag

**Example Queries:**
- "Show me what would happen if I move all Asimov books to Classic genre"

#### `execute_file_move`
Execute confirmed file moves.

**Parameters:**
- `preview_id` (string): ID from preview_file_move
- `confirmed_moves` (array): Book IDs to move

**Safety**: MUST be preceded by preview_file_move

### Bulk Operations

#### `pattern_rename_preview`
Preview renaming books with a template pattern.

**Parameters:**
- `series_id` (integer): Series to rename
- `book_ids` (array): Specific books to rename
- `template` (string): Template with variables
- `apply_to` (string): "title", "directory", or "both"

**Template Variables:**
- `{series}`: Series name
- `{number}`: Series number
- `{title}`: Book title
- `{author}`: Author name
- `{narrator}`: Narrator name

**Example Queries:**
- "Rename Foundation series books to 'Foundation #{number} - {title}'"
- "Preview renaming Deathlands to standard pattern"

#### `bulk_update_preview`
Preview bulk metadata updates.

**Parameters:**
- `book_ids` (array): Books to update
- `updates` (object): Metadata to update

**Example Queries:**
- "Preview changing all Homer books to Classic genre"

#### `execute_advanced_query`
Execute complex custom queries for special cases.

**Parameters:**
- `description` (string): What the query does
- `query_type` (string): "count", "aggregate", "list", "statistics"
- `parameters` (object): Query parameters

**Example Queries:**
- "Find all series with more than 10 books"
- "Show statistics about the library"

### Book Management Tools

#### `read_audio_metadata`
Extract ID3 tags and technical metadata from audio files.

**Parameters:**
- `book_id` (integer): Book ID to read metadata for
- `file_path` (string): Specific file path (alternative to book_id)
- `include_chapters` (boolean): Extract chapter information (default: false)

**Returns:**
- File metadata: duration, bitrate, format
- ID3 tags: title, artist, album, genre, year
- Chapter information (if requested)
- Technical details

**Example Queries:**
- "Read audio metadata for book ID 12345"
- "Extract chapter information from 'The Way of Kings'"
- "What are the ID3 tags for this book?"

#### `create_book`
Create new book records from filesystem directories.

**Parameters:**
- `directory_path` (string, required): Path to book directory
- `auto_discover_metadata` (boolean): Auto-extract from directory structure (default: true)
- `title` (string): Override discovered title
- `description` (string): Book description
- `confirmed` (boolean, required): Safety confirmation

**Safety**: Requires confirmation (confirmed=true)

**Example Queries:**
- "Create a book entry for the directory 'Fantasy/Brandon Sanderson/Mistborn'"
- "Add a new book from this directory with title 'Custom Title'"

#### `update_book_metadata`
Update book metadata with automatic backup.

**Parameters:**
- `book_id` (integer, required): Book to update
- `updates` (object, required): Fields to update (title, description, authors, genres, narrators, language, isbn, release_date)
- `preview_only` (boolean): Preview changes without applying (default: true)
- `create_backup` (boolean): Create backup before changes (default: true)

**Safety**:
- Preview mode by default
- Automatic backup before changes
- Set preview_only=false to apply

**Example Queries:**
- "Preview updating book 123 with genre 'Science Fiction'"
- "Update book title to 'New Title' and description to 'New description'"
- "Change the author of this book to 'Isaac Asimov'"

#### `delete_books`
Delete books with automatic trash/backup.

**Parameters:**
- `book_ids` (array, required): Book IDs to delete
- `delete_files` (boolean): Also delete physical files (default: false)
- `reason` (string, required): Reason for deletion (audit log)
- `confirmed` (boolean, required): Safety confirmation

**Safety**:
- Requires confirmation
- Requires reason for audit trail
- Automatic backup to trash
- Can be restored later

**Example Queries:**
- "Delete book ID 12345 because it's a duplicate"
- "Remove books 100, 101, 102 and their files - they're corrupted"

#### `apply_bulk_updates`
Execute bulk metadata updates with automatic backups.

**Parameters:**
- `updates` (array, required): Array of {book_id, updates} objects
- `preview_id` (string): Reference to preview
- `confirmed` (boolean, required): Safety confirmation

**Safety**:
- Requires confirmation
- Automatic bulk backup before changes
- Use with bulk_update_preview first

**Example Queries:**
- "Apply the previewed bulk updates to all Foundation books"
- "Execute the genre changes for all selected books"

#### `fetch_external_metadata`
Fetch enriched metadata from external sources.

**Parameters:**
- `source` (string, required): "audible", "google_books", or "hardcover"
- `search_query` (string, required): Search query

**Returns:**
- Title, authors, description
- ISBN, publication date
- Categories/genres
- Cover image URLs

**Example Queries:**
- "Search Google Books for 'The Hobbit by J.R.R. Tolkien'"
- "Find metadata for 'Foundation' on Audible"
- "Get book information from Hardcover for this title"

#### `download_cover_image`
Download and save cover images.

**Parameters:**
- `book_id` (integer, required): Book to add cover to
- `image_url` (string): Direct URL to image
- `auto_fetch` (boolean): Auto-search and download from Google Books (default: false)

**Example Queries:**
- "Download cover image for book 12345"
- "Auto-fetch and save cover for 'The Way of Kings'"
- "Download cover from this URL for book 100"

#### `trigger_ai_processing`
Run AI metadata extraction on books.

**Parameters:**
- `book_ids` (array, required): Books to process
- `force_reprocess` (boolean): Reprocess even if already done (default: false)
- `min_confidence` (float): Minimum confidence threshold (default: 0.7)

**Safety**: Automatic backup before processing

**Example Queries:**
- "Run AI processing on book 12345"
- "Reprocess all books with low confidence scores"
- "Extract metadata using AI for these 10 books"

#### `generate_nfo_files`
Generate NFO sidecar files for Kodi/Plex.

**Parameters:**
- `book_ids` (array, required): Books to generate NFO for
- `format` (string): "kodi" or "plex" (default: "kodi")

**Returns:**
- Generated NFO file paths
- Success/failure for each book

**Example Queries:**
- "Generate NFO files for all Foundation books"
- "Create Kodi metadata files for book 12345"
- "Generate Plex-compatible NFO for this series"

## Usage

### Command Line

```bash
# Interactive test
php artisan ai:test-tools

# Direct query
php artisan ai:test-tools "Find missing books in Foundation series"

# With verbose output
php artisan ai:test-tools "Show all Science Fiction books" -v
```

### Web API

```php
// POST /admin/ai-query/tools/process
{
  "prompt": "Find all series with more than 10 books",
  "context": {}
}

// Response
{
  "success": true,
  "query_id": 123,
  "response": "Here are all series with more than 10 books...",
  "iterations": 3
}

// Get query history
GET /admin/ai-query/tools/history?limit=20

// Get query details
GET /admin/ai-query/tools/123
```

### Programmatic Use

```php
use App\Services\AI\AIToolService;

$service = new AIToolService('gemini-2.5-flash');
$service->setMaxIterations(15);

$result = $service->processQuery(
    "Find missing books in the Deathlands series"
);

if ($result['success']) {
    echo $result['response'];
    echo "Iterations: " . $result['iterations'];
}
```

## Example Queries

### Series Analysis
```
"Find missing books in the Foundation series"
"Show gaps in Deathlands numbering"
"Analyze the Honor Harrington series for duplicates"
"What's the naming pattern for Dresden Files?"
```

### Advanced Searches
```
"Find all series with more than 10 books"
"Show all Science Fiction books by Isaac Asimov"
"List books narrated by R.C. Bray in the Action genre"
"Find all books without a series"
```

### Bulk Operations
```
"Rename all Foundation books to '{series} #{number} - {title}'"
"Move all Homer books to the Classic genre"
"Find duplicate books across all series"
```

### Filesystem Operations
```
"Check if files exist for all Deathlands books"
"Scan audio files for 'The Way of Kings'"
"List all m4b files in the Science Fiction directory"
```

### Book Management
```
"Create a new book from the directory 'Fantasy/Patrick Rothfuss/The Name of the Wind'"
"Update book 12345 to add genre 'Epic Fantasy'"
"Delete duplicate books 100, 101, 102 - reason: duplicates found"
"Preview metadata update for book 'The Way of Kings'"
```

### Metadata Enhancement
```
"Read audio metadata and chapters from book 12345"
"Search Google Books for 'The Hobbit' and show results"
"Download cover image for 'Foundation' by Isaac Asimov"
"Run AI processing on all books with missing authors"
"Generate NFO files for all books in the Foundation series"
```

### External Integration
```
"Find metadata on Google Books for 'The Lord of the Rings'"
"Auto-fetch cover image for book 12345"
"Get publication details from external sources for this book"
```

## How It Works

1. **User submits query** in natural language
2. **AIToolService** sends query to Gemini 2.5 Flash with tool definitions
3. **Gemini analyzes** the query and decides which tools to use
4. **Tools are executed** by ToolExecutor (database queries, file operations)
5. **Results returned** to Gemini
6. **Gemini synthesizes** final natural language response
7. **Response shown** to user

The AI can chain multiple tool calls in one conversation to answer complex questions. For example:
- Query: "Find missing books in Foundation series"
- AI calls: `analyze_series("Foundation")`
- AI receives: gaps, book list, statistics
- AI formats: Natural language response with gap numbers

## Safety Features

1. **File Operation Previews**: All file moves require preview first
2. **Confirmation Required**: Destructive operations need user approval
3. **Automatic Backups**: Data changes create automatic backups in trash system
4. **Trash & Restore**: Deleted books can be restored from trash with full metadata
5. **Preview Mode**: Metadata updates default to preview-only mode
6. **Audit Trail**: Deletions require reason for audit logging
7. **Error Handling**: Graceful failures with helpful error messages
8. **Iteration Limits**: Prevents infinite loops (default: 10, configurable)
9. **Filesystem Validation**: Checks file existence before operations

## Configuration

### Environment Variables

```bash
# Gemini API Key (required)
GEMINI_API_KEY=your-key-here

# Default model (optional)
AI_DEFAULT_MODEL=gemini-2.5-flash

# Book root directory
BOOK_ROOT=/media/lyra_data1/audiobooks/books
```

### Customization

**Add New Tools:**

1. Add tool definition to `ToolDefinitions.php`
2. Implement execution in `ToolExecutor.php`
3. Tools are automatically available to AI

**Change AI Model:**

```php
// Use different Gemini model
$service = new AIToolService('gemini-2.5-pro');

// Adjust iteration limit
$service->setMaxIterations(20);
```

## Troubleshooting

### "Maximum iterations reached"
- Query is too complex or ambiguous
- AI is stuck in a loop
- Increase max iterations or simplify query

### "Tool execution failed"
- Check database connection
- Verify book_root path exists
- Check file permissions
- Review logs: `storage/logs/laravel.log`

### "No valid response from Gemini API"
- Verify GEMINI_API_KEY is set
- Check API quota/rate limits
- Check network connectivity
- Review API error in logs

## Performance

- **Average Response Time**: 2-5 seconds (1-3 iterations)
- **Complex Queries**: 5-15 seconds (4-8 iterations)
- **Cost**: ~$0.001-0.005 per query (Gemini 2.5 Flash pricing)
- **Concurrent Requests**: Handled via queue (Laravel)

## Future Enhancements

- [ ] Conversational context (follow-up questions)
- [ ] User preference learning
- [ ] Custom tool creation via UI
- [ ] Tool usage analytics
- [ ] Batch query processing
- [ ] Export results to CSV/JSON
- [ ] Scheduled automated queries
- [ ] Integration with external APIs (Audible, Goodreads)

## Comparison: SQL-Based vs Tool-Based

| Feature | SQL-Based | Tool-Based |
|---------|-----------|------------|
| Flexibility | Limited to predefined patterns | Handles unplanned queries |
| Query Types | research, list, bulk_update, parse | 15+ specialized tools |
| Multi-step Operations | Manual chaining | Automatic iteration |
| File Operations | Limited | Full preview + execute |
| Series Analysis | Basic | Comprehensive (gaps, duplicates, patterns) |
| Cost | Higher (Claude/GPT) | Lower (Gemini Flash) |
| Speed | 3-8 seconds | 2-5 seconds |

## Migration from SQL-Based System

Both systems coexist. To migrate:

1. **Test queries** with new system: `POST /admin/ai-query/tools/process`
2. **Compare results** with old system
3. **Gradually shift** users to tool-based system
4. **Monitor** query success rates
5. **Deprecate** old system when confident

## Support

- **Documentation**: `/docs/AI-TOOL-SYSTEM.md`
- **Test Command**: `php artisan ai:test-tools`
- **Logs**: `storage/logs/laravel.log`
- **API**: See `AIToolService`, `ToolExecutor`, `ToolDefinitions`

---

**Built with**: Gemini 2.5 Flash, Laravel 11, PHP 8.3
**Last Updated**: 2026-01-06
