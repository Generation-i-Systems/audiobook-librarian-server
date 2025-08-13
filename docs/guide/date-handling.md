# Date Handling Guide

This project normalizes dates across PHP (backend) and JavaScript (frontend) to ensure consistent storage and API behavior.

## Goals
- Store dates in the database as `YYYY-MM-DD`.
- Accept flexible input formats from forms and API.
- Avoid timezone drift and off-by-one errors.

## PHP Helper
- File: `app/Support/DateNormalizer.php`
- Responsibility: Convert various date-like inputs to canonical `Y-m-d` strings, or `null` when invalid.
- Behavior:
  - Accepts strings like `2024-2-3`, `02/03/2024`, `Feb 3, 2024` and normalizes to `2024-02-03`.
  - Trims whitespace, rejects invalid dates.
- Tests: `tests/Unit/DateNormalizerTest.php` cover valid/invalid cases.

## JavaScript Helper
- File: `resources/js/utils/dateNormalizer.js`
- Responsibility: Convert user input to `YYYY-MM-DD` for API payloads and form state.
- Behavior:
  - Parses common formats and returns canonical string or `null`.
- Tests: `resources/js/utils/__tests__/dateNormalizer.test.js` (Jest) validate core cases.

## API and Models
- Eloquent casts `release_date` to `date:Y-m-d` at the model layer (e.g., `Book`).
- Controllers should pass user-provided dates through the PHP normalizer before persisting.

## Usage Examples

### PHP
```php
use App\Support\DateNormalizer;

$normalized = DateNormalizer::normalize($request->input('release_date'));
$book->release_date = $normalized; // null or 'YYYY-MM-DD'
$book->save();
```

### JavaScript
```js
import { normalizeDate } from '@/utils/dateNormalizer';

const normalized = normalizeDate(form.releaseDate);
await api.post('/api/v1/books', { ...payload, release_date: normalized });
```

## Notes
- Always validate using the normalizer; do not hand-parse in controllers.
- Never store timezone-aware timestamps in `release_date` fields.
- Keep unit tests updated when adding new accepted formats.
