# User Approval Guarantee

## CRITICAL RULE: NO DATA CHANGES AFTER APPROVAL

Once the user approves metadata (by pressing Enter or selecting "Accept"), **ABSOLUTELY NO MODIFICATIONS** are allowed to:

- Title
- Author(s)
- Narrator(s)
- Series
- Series Number
- Genre
- Year
- Directory Path
- Any other user-visible field

---

## Code Enforcement

### 1. Critical Checkpoint (Line 1686-1688)

```php
// CRITICAL: After this point, $aiMetadata contains user-approved data
// DO NOT modify title, author, series, or custom_directory_path
// These values must be preserved exactly as approved by the user
```

After this point, the code goes directly to `performDatabaseImport()` with NO modifications.

### 2. Removed Violations

**FIXED**: Removed `extractSeriesNumberFromTitle()` call (line 2754)
- **Was**: Automatically stripping "Part 5" from "Spacers Part 5" after approval
- **Now**: User-approved title preserved exactly

### 3. Allowed Post-Approval Changes

ONLY these internal fields may be added:
- `batch_id` - Internal tracking (line 2133)
- Database IDs and timestamps
- System metadata not visible to user

---

## Testing

### Manual Test:
1. Import a book with title "Book Title Part 5"
2. Manually approve the title as "Book Title Part 5"
3. Verify database has EXACTLY "Book Title Part 5"
4. NOT "Book Title"

### Regression Test:
```php
/** @test */
public function user_approved_title_is_never_modified()
{
    $metadata = [
        'title' => 'Spacers Part 5',
        'series' => 'Spacers',
        'series_number' => 5,
    ];
    
    // Simulate user approval
    $approved = $metadata;
    
    // After approval, title must not change
    $this->assertEquals('Spacers Part 5', $approved['title']);
}
```

---

## Violations to Watch For

### ❌ NEVER DO THIS:
```php
// After user approval
$metadata['title'] = cleanTitle($metadata['title']);  // NO!
$this->extractSeriesNumberFromTitle($metadata);       // NO!
$metadata['author'] = normalizeAuthor($metadata['author']); // NO!
```

### ✅ CORRECT:
```php
// After user approval
$metadata['batch_id'] = $this->batchId;  // OK - internal only
// Pass directly to database
$book = $this->importService->createBookFromMetadata($metadata, $audiobook);
```

---

## Code Review Checklist

Before committing changes to import code:

- [ ] No calls to `extractSeriesNumberFromTitle()` after approval
- [ ] No calls to `normalizeAuthor()` after approval
- [ ] No title cleaning/trimming after approval
- [ ] No series name modifications after approval
- [ ] No directory path regeneration after approval
- [ ] Only `batch_id` and internal fields added
- [ ] Tests verify user data preserved

---

## Enforcement

1. **Pre-commit hook** runs tests
2. **Code review** checks for violations
3. **Regression tests** verify preservation
4. **User complaints** = immediate fix

---

## History

- **2025-10-21**: Removed `extractSeriesNumberFromTitle()` after approval
- **2025-10-21**: Added this guarantee document

---

**REMEMBER: The user's approval is FINAL. Their data is SACRED.**
