# Critical Import Issues to Fix

## Session Summary: 2025-10-20

### What Was Fixed Today ✅

1. **Embedded Cover Extraction** - WORKING
   - Extracts cover from ALL M4B files (not just split books)
   - Saves to book directory as `cover.jpg`
   - Priority: Embedded > Local file > Downloaded
   - Display shows "Embedded" instead of temp file path
   - Tests created: `EmbeddedCoverExtractionTest.php`

2. **User-Approved Path Preservation** - WORKING
   - `moveFilesToLibrary()` no longer overwrites `directory_path`
   - User-approved metadata is preserved after approval

3. **Relative Path Enforcement** - WORKING
   - `makePathRelative()` tries multiple book root configs
   - Throws exception if path can't be made relative
   - Prevents absolute paths in database

4. **Title Parsing Pattern** - WORKING
   - Added pattern: `/^(.+?)\s*-\s*(.+?),\s*Book\s+(\d+)$/i`
   - Correctly parses: "In Our Stars - The Doomed Earth, Book 1"
   - Extracts: title="In Our Stars", series="The Doomed Earth", number=1

5. **Batch ID Column** - WORKING
   - Migration run: `add_batch_id_to_books_table`
   - Books can be tracked by import batch

6. **Duplicate Detection Method** - CREATED
   - `updateBookFromMetadata()` method created
   - Prompts user when book exists at path
   - Options: Update existing, Skip, or Create duplicate

### Critical Issues Still Broken ❌

## Issue 1: Series Being Overridden by AI/Enrichment

**Problem:**
- File tags correctly parse: "The Doomed Earth" 
- But final metadata shows: "Jack" (extracted from author name)
- AI or enrichment is overwriting the authoritative file tag data

**Evidence:**
```
File tags: series="The Doomed Earth", number=1
Final display: Series: Jack
```

**CRITICAL FINDING:**
Tests prove that `MetadataProcessingService::applyId3TagMappings()` works correctly:
- ✅ Test `file_tag_series_never_overridden_by_ai` PASSES
- ✅ Test `parses_title_with_comma_book_format` PASSES
- ✅ File tags DO extract "The Doomed Earth" correctly

**Therefore: The bug happens AFTER file tag extraction, somewhere in the import pipeline**

**Root Cause:**
Something between file tag extraction and database insert is overwriting the series:
1. File tags extract: series="The Doomed Earth" ✅
2. [SOMETHING HAPPENS HERE] ❌
3. Final metadata: series="Jack" ❌

**Most Likely Culprits:**
1. AI processing in `processAudiobookMetadata()` - overwrites file tag data
2. Enrichment merge in `performExternalDataEnrichment()` - doesn't respect file tags
3. Directory name parsing - extracts "Jack" from path and overwrites

**Fix Needed:**
1. Add logging to track series value through entire pipeline
2. Find where series changes from "The Doomed Earth" to "Jack"
3. Ensure file tags are NEVER overwritten after extraction
4. Add integration test that runs full import pipeline

**Files to Check:**
- `app/Console/Commands/ImportBooksFromDownloads.php` - AI processing
- `app/Services/BookEnrichmentService.php` - Enrichment merge
- `app/Services/AIBookProcessor.php` - AI extraction

**Tests Added:**
- `file_tag_series_never_overridden_by_ai()` - Regression test
- `parses_title_with_comma_book_format()` - Format test

---

## Issue 2: Duplicate Detection Not Working

**Problem:**
- Book 7762 exists at path `_NEEDS_REPROCESSING/01 The Doomed Earth`
- Import creates NEW book 10555 instead of updating existing
- The duplicate detection code runs but doesn't find the match

**Evidence:**
```
Book 7762: directory_path = '_NEEDS_REPROCESSING/01 The Doomed Earth'
Import: Creates book 10555 instead of updating 7762
```

**Root Cause:**
The duplicate detection at lines 2134-2163 checks `$aiMetadata['custom_directory_path']` which is the APPROVED/TARGET path, not the current source path. It's comparing:
- Target: `Science Fiction/Jack Campbell/The Doomed Earth/01 In Our Stars`
- Existing: `_NEEDS_REPROCESSING/01 The Doomed Earth`

These don't match, so no duplicate is found.

**Fix Needed:**
1. Check for existing book at SOURCE path first
2. Then check for existing book at TARGET path
3. If found at either, prompt user to update

**Code Location:**
`app/Console/Commands/ImportBooksFromDownloads.php` lines 2134-2163

---

## Issue 3: Directory Path Has Duplicate Author Name

**Problem:**
```
Expected: Science Fiction/Jack Campbell/The Doomed Earth/01 In Our Stars
Actual:   Science Fiction/Jack Campbell/Jack Campbell/In Our Stars
```

**Root Cause:**
This is a SYMPTOM of Issue #1. When series="Jack" instead of "The Doomed Earth", the path generation creates:
- Genre: Science Fiction
- Author: Jack Campbell
- Series: Jack (WRONG - should be "The Doomed Earth")
- Title: In Our Stars

Result: `Science Fiction/Jack Campbell/Jack/In Our Stars`

But then it's using "Jack Campbell" again somehow, creating the duplicate.

**Fix:**
This will be fixed automatically when Issue #1 is fixed.

---

## Issue 4: User Edits Not Being Preserved

**Problem:**
Even after user approves metadata, subsequent processing modifies it.

**Evidence:**
User approves: series="The Doomed Earth"
Database shows: series="Jack"

**Root Cause:**
After approval, the code continues to process and modify the metadata:
1. User approves metadata
2. `performDatabaseImport()` is called
3. Something between approval and database insert modifies the data

**Fix Needed:**
1. Add CRITICAL comment: DO NOT MODIFY after user approval
2. Audit all code between approval and database insert
3. Ensure NO modifications happen to approved fields

**Code to Audit:**
- Lines 2699-2704: "CRITICAL: After this point, $aiMetadata contains user-approved data"
- Everything between `handleManualReview()` and `createBookFromMetadata()`

---

## Testing Requirements

### Tests Needed:

1. **File Tag Authority Test**
   - Extract series from M4B tags
   - Run AI processing
   - Assert series is NOT overwritten

2. **Duplicate Detection Test**
   - Create book at path A
   - Import book with source path A
   - Assert: prompts to update, doesn't create duplicate

3. **User Approval Preservation Test**
   - User approves metadata
   - Complete import
   - Assert: database matches approved data exactly

4. **Path Generation Test**
   - Given: author="Jack Campbell", series="The Doomed Earth"
   - Assert: path does NOT contain duplicate "Jack Campbell"

---

## Priority Order

1. **HIGHEST**: Fix Issue #1 (Series Override) - This breaks everything else
2. **HIGH**: Fix Issue #2 (Duplicate Detection) - Prevents data corruption
3. **MEDIUM**: Fix Issue #4 (User Edit Preservation) - User trust issue
4. **LOW**: Issue #3 will auto-fix when #1 is fixed

---

## Files Modified Today

- `app/Services/BookImportService.php` - Added updateBookFromMetadata, fixed paths
- `app/Services/MetadataProcessingService.php` - Added title parsing pattern
- `app/Console/Commands/ImportBooksFromDownloads.php` - Cover extraction, duplicate detection
- `app/Services/TerminalImageService.php` - Display name parameter
- `tests/Unit/Services/EmbeddedCoverExtractionTest.php` - NEW TEST FILE
- `docs/EMBEDDED-COVER-EXTRACTION.md` - NEW DOCUMENTATION

---

## Next Session TODO

1. Debug why series is being overridden
   - Add logging to track series value through pipeline
   - Find where "Jack" is being set
   - Prevent override of file tag data

2. Fix duplicate detection
   - Check source path first
   - Then check target path
   - Prompt user appropriately

3. Add comprehensive logging
   - Track metadata changes through pipeline
   - Log: "Series changed from X to Y at [location]"
   - Make it easy to find where data is corrupted

4. Run all import tests
   - Ensure no regressions
   - Add new tests for fixed issues
