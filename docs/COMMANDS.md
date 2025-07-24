# Console Commands & Jobs

This document provides a comprehensive list of all Artisan console commands available in the Audiobook Librarian application, along with their descriptions and usage.

## Application-Specific Commands

### `php artisan backup:database`
Create a backup of the MySQL database.
- **Signature:** `backup:database {--verify : Verify backup integrity after creation}`
- **Description:** Create a backup of the MySQL database.
- **Options:**
  - `--verify`: Verify backup integrity after creation.

### `php artisan app:benchmark-database-performance`
Run a series of queries against database services to benchmark their performance.
- **Signature:** `app:benchmark-database-performance {driver=all : The driver to benchmark (mysql, mongo, all)}`
- **Description:** Run a series of queries against database services to benchmark their performance.
- **Arguments:**
  - `driver`: The database driver to benchmark (`mysql`, `mongo`, `firestore`, or `all`). Default is `all`.

### `php artisan cover:check`
Checks for inconsistencies in book cover images and attempts to fix them.
- **Signature:** `cover:check`
- **Description:** Checks for inconsistencies in book cover images and attempts to fix them.

### `php artisan app:compare-book-data`
Compare book data between MongoDB and MySQL.
- **Signature:** `app:compare-book-data {title?} {--id=} {--list : List all books in MySQL} {--all : Compare all fields including JSON data}`
- **Description:** Compare book data between MongoDB and MySQL.
- **Arguments:**
  - `title`: (Optional) Title of the book to compare.
- **Options:**
  - `--id`: MongoDB ID of the book to compare.
  - `--list`: List all books in MySQL.
  - `--all`: Compare all fields including JSON data.

### `php artisan app:compare-mongo-mysql-books`
Compares MongoDB book _ids with MySQL book mongo_ids to find unmigrated books.
- **Signature:** `app:compare-mongo-mysql-books`
- **Description:** Compares MongoDB book _ids with MySQL book mongo_ids to find unmigrated books.

### `php artisan app:create-admin-user`
Create an admin user if one does not exist.
- **Signature:** `app:create-admin-user`
- **Description:** Create an admin user if one does not exist.

### `php artisan firestore:books-dump`
Dump the entire Firestore books collection as JSON for MongoDB import.
- **Signature:** `firestore:books-dump {--output= : Output file (default: stdout)} {--import-to-mongo : Import directly into MongoDB using .env credentials} {--collection= : Firestore/MongoDB collection name (default: books)} {--one-by-one : Export/import one record at a time} {--direction=firestore-to-mongo : Sync direction (firestore-to-mongo|mongo-to-firestore)}`
- **Description:** Dump the entire Firestore books collection as JSON for MongoDB import.
- **Options:**
  - `--output`: Output file (default: stdout).
  - `--import-to-mongo`: Import directly into MongoDB using .env credentials.
  - `--collection`: Firestore/MongoDB collection name (default: books).
  - `--one-by-one`: Export/import one record at a time.
  - `--direction`: Sync direction (`firestore-to-mongo` or `mongo-to-firestore`). Default is `firestore-to-mongo`.

### `php artisan books:fix-duplicates-and-review-flags`
Remove duplicate books by directoryPath and flag books for review with needsReviewReasons.
- **Signature:** `books:fix-duplicates-and-review-flags {--dry-run} {--ids=}`
- **Description:** Remove duplicate books by directoryPath and flag books for review with needsReviewReasons.
- **Options:**
  - `--dry-run`: Show what would be processed without making changes.
  - `--ids`: Comma-separated list of book IDs to process.

### `php artisan books:import-downloads`
Import audiobooks from download directories using AI processing and external data enrichment.
- **Signature:** `books:import-downloads {--directory=* : Custom directories to scan (defaults to /media/download and /media/download/audiobooks)} {--model=gemini-2.5-flash-lite : AI model to use for processing} {--min-confidence=80 : Minimum AI confidence for auto-import} {--auto : Fully automated mode - no manual review} {--dry-run : Show what would be imported without making changes} {--limit=10 : Maximum number of books to process per run} {--force : Skip confirmation prompts} {--skip-enrichment : Skip external data enrichment (Audible, Google Books)} {--copy-files : Copy files after successful import instead of moving (default is move)}`
- **Description:** Import audiobooks from download directories using AI processing and external data enrichment.
- **Options:**
  - `--directory`: Custom directories to scan (defaults to `/media/download` and `/media/download/audiobooks`).
  - `--model`: AI model to use for processing (e.g., `gemini-2.5-flash-lite`, `gpt-4o-mini`, `claude-3-5-haiku`).
  - `--min-confidence`: Minimum AI confidence for auto-import (default: 80).
  - `--auto`: Fully automated mode - no manual review.
  - `--dry-run`: Show what would be imported without making changes.
  - `--limit`: Maximum number of books to process per run (default: 10).
  - `--force`: Skip confirmation prompts.
  - `--skip-enrichment`: Skip external data enrichment (Audible, Google Books).
  - `--copy-files`: Copy files after successful import instead of moving (default is move).

### `php artisan books:migrate-camelcase`
Migrate all book records in Firestore from snake_case to camelCase and remove snake_case fields.
- **Signature:** `books:migrate-camelcase`
- **Description:** Migrate all book records in Firestore from snake_case to camelCase and remove snake_case fields.

### `php artisan app:migrate-mongo-to-mysql`
Migrate data from MongoDB to MySQL.
- **Signature:** `app:migrate-mongo-to-mysql {--force : Skip confirmation prompt} {--limit=0 : Limit the number of books to process (0 for no limit)}`
- **Description:** Migrate data from MongoDB to MySQL.
- **Options:**
  - `--force`: Skip confirmation prompt.
  - `--limit`: Limit the number of books to process (0 for no limit). Default is 0.

### `php artisan books:migrate-series-format`
Normalize the series field for all books in both MongoDB and Firestore to the canonical format.
- **Signature:** `books:migrate-series-format`
- **Description:** Normalize the series field for all books in both MongoDB and Firestore to the canonical format.

### `php artisan mongo:test`
Test MongoDB integration: insert a record into books and count records.
- **Signature:** `mongo:test`
- **Description:** Test MongoDB integration: insert a record into books and count records.

### `php artisan books:parse`
Parse book directories and output metadata for each book.
- **Signature:** `books:parse {paths* : One or more directory paths to scan for books. Supports shell wildcards} {--output= : Output format (json, table, csv, sql, array). Default: table} {--limit=0 : Maximum number of books to process (0 for no limit)} {--extensions= : Comma-separated list of file extensions to include} {--min-size= : Minimum file size in bytes} {--max-depth= : Maximum directory depth to scan} {--dry-run : Show what would be done without making any changes} {--sort : Sort output by author, series, series number, and title} {--save-json : Save output JSON into each book directory} {--json-filename= : Filename for saved JSON (default: librarian.json)} {--enrich : Lookup and enrich metadata from selected APIs} {--apis= : Comma-separated list of APIs for --enrich (google,audible,abbay,hardcover)} {--store : Store parsed book data to Documentstore} {--update-existing : Update existing books in Documentstore instead of skipping them}`
- **Description:** Parse book directories and output metadata for each book.
- **Arguments:**
  - `paths`: One or more directory paths to scan for books. Supports shell wildcards.
- **Options:**
  - `--output`: Output format (`json`, `table`, `csv`, `sql`, `array`). Default: `table`.
  - `--limit`: Maximum number of books to process (0 for no limit). Default: 0.
  - `--extensions`: Comma-separated list of file extensions to include.
  - `--min-size`: Minimum file size in bytes.
  - `--max-depth`: Maximum directory depth to scan.
  - `--dry-run`: Show what would be done without making any changes.
  - `--sort`: Sort output by author, series, series number, and title.
  - `--save-json`: Save output JSON into each book directory.
  - `--json-filename`: Filename for saved JSON (default: `librarian.json`).
  - `--enrich`: Lookup and enrich metadata from selected APIs.
  - `--apis`: Comma-separated list of APIs for `--enrich` (`google`, `audible`, `abbay`, `hardcover`).
  - `--store`: Store parsed book data to Documentstore.
  - `--update-existing`: Update existing books in Documentstore instead of skipping them.

### `php artisan books:process-ai`
Processes books using AI to extract and improve metadata from directory paths, filenames, and audio tags.
- **Signature:** `books:process-ai {--book=* : Process specific book IDs} {--limit=10 : Limit number of books to process (default 10 for free tier)} {--min-confidence=70 : Minimum confidence level to auto-apply changes} {--force : Skip confirmation prompts} {--dry-run : Show what would be processed without making changes} {--reprocess : Process books even if already AI-processed} {--model=gemini-2.5-flash-lite : Model to use (gemini-2.0-flash, gemini-2.0-flash-lite, gemini-2.5-flash, gemini-2.5-flash-lite, gemini-2.5-pro, claude-3-5-haiku, claude-3-5-sonnet, claude-4-sonnet, claude-4-opus, gpt-4o-mini, gpt-4o, gpt-4-turbo, gpt-3.5-turbo)} {--paid-tier : Use paid tier limits and pricing (requires billing setup)}`
- **Description:** Processes books using AI to extract and improve metadata from directory paths, filenames, and audio tags.
- **Options:**
  - `--book`: Process specific book IDs (can be used multiple times).
  - `--limit`: Limit number of books to process (default: 10 for free tier).
  - `--min-confidence`: Minimum confidence level to auto-apply changes (default: 70).
  - `--force`: Skip confirmation prompts.
  - `--dry-run`: Show what would be processed without making changes.
  - `--reprocess`: Process books even if already AI-processed.
  - `--model`: AI model to use (e.g., `gemini-2.5-flash-lite`, `gpt-4o-mini`, `claude-3-5-haiku`).
  - `--paid-tier`: Use paid tier limits and pricing (requires billing setup).

### `php artisan books:process-titles-interactive`
Interactively process book titles to clean up formatting, extract series info, narrators, and years.
- **Signature:** `books:process-titles-interactive {--force : Skip confirmation prompts}`
- **Description:** Interactively process book titles to clean up formatting, extract series info, narrators, and years.
- **Options:**
  - `--force`: Skip confirmation prompts.

### `php artisan books:repair`
Repair book metadata including covers and series numbers.
- **Signature:** `books:repair {book_id? : The ID of the book to repair} {--cover : Repair book covers} {--series : Repair series numbers} {--all : Repair both covers and series} {--force : Skip confirmation prompts}`
- **Description:** Repair book metadata including covers and series numbers.
- **Arguments:**
  - `book_id`: (Optional) The ID of the book to repair.
- **Options:**
  - `--cover`: Repair book covers.
  - `--series`: Repair series numbers.
  - `--all`: Repair both covers and series.
  - `--force`: Skip confirmation prompts.

### `php artisan books:repair-no-audio`
Find (and optionally delete) books whose directory contains no audio files.
- **Signature:** `books:repair-no-audio {parent_path?} {--delete : Delete books with no audio files} {--interactive : Interactively review each book with no audio files}`
- **Description:** Find (and optionally delete) books whose directory contains no audio files.
- **Arguments:**
  - `parent_path`: (Optional) Only consider books under this directory path.
- **Options:**
  - `--delete`: Delete books with no audio files.
  - `--interactive`: Interactively review each book with no audio files.

### `php artisan narrators:normalize-names`
Update the normalized_name field for all narrators.
- **Signature:** `narrators:normalize-names`
- **Description:** Update the normalized_name field for all narrators.
