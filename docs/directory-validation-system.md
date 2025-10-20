# Directory Validation System

## Overview
System to validate book directories, suppress books with missing directories from API, and provide admin tools to match orphaned directories.

## Implemented Components

### 1. Database Migration ✅
- **File**: `database/migrations/2025_10_17_000735_add_directory_exists_to_books_table.php`
- **Fields Added**:
  - `directory_exists` (boolean, default true, indexed)
  - `directory_last_checked` (timestamp, nullable)

### 2. Validation Command ✅
- **File**: `app/Console/Commands/ValidateBookDirectoriesCommand.php`
- **Command**: `php artisan books:validate-directories [--force]`
- **Features**:
  - Scans all books and validates directory existence
  - Updates `directory_exists` flag in database
  - Finds orphaned directories (directories without database entries)
  - **Filters out**:
    - Directories with "ebook" in the name
    - Directories without audio files (mp3, m4a, m4b, ogg, opus, flac, wav, aac)
    - System directories (.Trash, lost+found, etc.)
  - Caches results for 24 hours
  - Progress bars and detailed output

### 3. Book Model Scopes ✅
- **File**: `app/Models/Book.php`
- **Scopes Added**:
  - `withExistingDirectories()` - Filter books with existing directories
  - `withMissingDirectories()` - Filter books with missing directories
- **Casts Added**:
  - `directory_exists` => 'boolean'
  - `directory_last_checked` => 'datetime'

### 4. API Filtering ✅
**File**: `app/Services/MySqlService.php`
- Added `withExistingDirectories()` scope to `listBooks()` method
- Added directory_exists filter to `listBooksMinimal()` raw SQL query
- All API endpoints now automatically filter out books with missing directories

### 5. Admin Report Page ✅
**Files Created**:
- Controller: `app/Http/Controllers/Admin/DirectoryValidationController.php`
- View: `resources/views/admin/directory-validation.blade.php`
- Routes: `routes/web.php`

**Features**:
- Summary cards showing missing/orphaned counts and last scan time
- Books with missing directories (paginated table with delete action)
- Orphaned directories (table with import action)
- AI matching suggestions with confidence scores
- Actions: Rename directory, Delete book entry, Import orphaned directory, Rescan

**Routes**:
- GET `/admin/directory-validation` - Main report page
- POST `/admin/directory-validation/rescan` - Trigger manual rescan
- POST `/admin/directory-validation/rename` - Rename orphaned directory to match book
- DELETE `/admin/directory-validation/delete-book` - Delete book entry
- POST `/admin/directory-validation/import` - Import orphaned directory

### 6. AI Matching Service ✅
**File**: `app/Services/DirectoryMatchingService.php`

**Features**:
- Extracts metadata from directory path (genre, author, series, book title)
- Calculates match score (0-100) based on:
  - Title similarity (40 points)
  - Author match (30 points)
  - Series match (20 points)
  - Genre match (10 points)
- Returns top 3 matches with confidence scores
- Provides human-readable reasons for each match
- Batch processing for all orphaned directories

**Methods**:
- `findMatches()` - Find matches for single orphaned directory
- `matchAll()` - Batch process all orphaned directories
- `calculateMatchScore()` - Score calculation algorithm
- `normalizeTitle()` - Title normalization for comparison

### 7. Scheduled Task ✅
**File**: `bootstrap/app.php`

Added to schedule:
```php
$schedule->command('books:validate-directories')
         ->dailyAt('03:00')
         ->appendOutputTo(storage_path('logs/directory-validation.log'));
```

Runs daily at 3:00 AM and logs output to `storage/logs/directory-validation.log`

## Usage

### Run Migration
```bash
php artisan migrate
```

### Run Initial Validation
```bash
php artisan books:validate-directories --force
```

### Check Results
```bash
php artisan tinker
>>> Cache::get('book_validation_results')
=> [
     "missing_directories" => 3466,
     "orphaned_directories" => 127,
     "last_scan" => "2025-10-16 18:00:00",
   ]
```

### API Usage (After Implementation)
All API endpoints will automatically filter out books with `directory_exists = false`.

## Database Queries

### Books with Missing Directories
```php
Book::withMissingDirectories()->get();
```

### Books with Existing Directories
```php
Book::withExistingDirectories()->get();
```

### Count Missing
```php
Book::where('directory_exists', false)->count();
```

## Next Steps

1. ✅ Run migration: `php artisan migrate`
2. ✅ Run initial scan: `php artisan books:validate-directories --force`
3. ✅ API filtering implemented in MySqlService
4. ✅ Admin report page created
5. ✅ AI matching service implemented
6. ✅ Scheduled task added
7. **Test end-to-end** - Access `/admin/directory-validation` to view report

## Testing

### Access Admin Page
Navigate to: `https://books.thelin.org/admin/directory-validation`

### Expected Behavior
1. Summary cards show current stats (3,466 missing, ~127 orphaned)
2. Click "Run AI Matching" to generate match suggestions
3. Review suggested matches with confidence scores
4. Use "Rename Directory" to fix matches
5. Use "Delete" to remove orphaned database entries
6. Use "Rescan Now" to refresh validation

### Verify API Filtering
```bash
# Books with missing directories should not appear in API
curl https://books.thelin.org/api/v1/books

# Check that count decreased from 9,113 to ~5,647
```

### Verify Scheduled Task
```bash
# Check if task is scheduled
php artisan schedule:list

# Run manually
php artisan books:validate-directories --force

# Check log
tail -f storage/logs/directory-validation.log
```
