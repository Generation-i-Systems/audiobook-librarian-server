# Audiobook Librarian Project Blueprint

## Overview
Audiobook Librarian is a Laravel-based web app for managing audiobooks, supporting admin CRUD, user management, Google Books autofill, and Firestore-backed autocomplete for authors and series. The backend also provides a REST API that supports integration with an Android app client.

## Reading Progress & Statistics Requirements
See `docs/requirements/reading-progress-and-stats.md`.

## 1. Tech Stack
- **Backend:** Laravel (PHP) — provides both web and REST API endpoints
- **Frontend:** Blade, Bootstrap 5, jQuery, jQuery UI (Autocomplete)
- **Database:** Firestore
- **Other:** Google Books API integration
- **API Clients:** Android app (in development/production)

## 2. Core Features
- Book CRUD (admin)
- Book form: multiple authors/series (autocomplete), Google Books autofill, genre selection, file uploads
- Authors/series autocomplete via jQuery UI, server-side filtering
- Google Books API integration for autofill
- Admin/user management

## 3. Data Structures

### Book Data Model

### Series Field (Canonical Format)
- The `series` field is always an array of objects, each with `seriesName` (string) and `number` (string or int):
  ```json
  "series": [
    { "seriesName": "Buryoku", "number": "9" }
  ]
  ```
- All code (backend, frontend, API) expects and produces this format only.
- Legacy formats (string, key-value, separate objects) are not supported.
- Migration: update any old data to this format.

### Book Document (Firestore)
```json
{
  "title": "The Way of Kings",
  "authors": ["Brandon Sanderson", "Co-Author Name"],
  "series": [
    { "seriesName": "Stormlight Archive", "number": 1 },
    { "seriesName": "Cosmere", "number": 15 }
  ],
    "Cosmere": 15
  },
  "description": "Epic fantasy novel...",
  "coverImage": "covers/way-of-kings.jpg",
  "directoryPath": "audiobooks/way-of-kings",
  "genre": ["Fantasy", "Epic"],
  "createdAt": "2025-05-20T14:00:00Z",
  "updatedAt": "2025-05-20T14:00:00Z",
  // ...other metadata fields
}
```
- **Authors:** Array of strings, each an author name.
- **Series:** Map of series name to series number (int or null).
- **Genre:** Array of strings.
- **Cover image:** Path or URL to image.
- **Directory path:** Path to audiobook files.
- **Timestamps:** ISO8601 strings.
- **Other:** Additional metadata as needed (e.g., Google Books ID, language, tags, etc.)

## 4. Key Backend Components
- `Admin\BookController`: CRUD, autocomplete endpoints, REST API endpoints
- `FirestoreService`: list/search authors/series
- **Web Routes:**
  - `/admin/books` (CRUD)
  - `/admin/books/create`, `/admin/books/{book}/edit`
  - `/admin/books/autocomplete/authors` (AJAX autocomplete)
  - `/admin/books/autocomplete/series` (AJAX autocomplete)
  - `/admin/books/import-from-title` (Google Books autofill)
- **REST API Routes:**
  - `GET /api/books` — List books
  - `GET /api/books/{id}` — Get book detail
  - `POST /api/books` — Create book
  - `PUT /api/books/{id}` — Update book
  - `DELETE /api/books/{id}` — Delete book
  - `GET /api/authors` — List authors
  - `GET /api/series` — List series
  - (additional endpoints for Android app support)

## 5. Frontend Integration
- **Blade:** Book form uses `.author-autocomplete`/`.series-autocomplete`, `window.BOOK_FORM_ROUTES`
- **JS:** `public/js/admin/books/form.js` handles dynamic rows, autocomplete, Google Books autofill

## 6. Design Decisions
- Server-side filtering for autocomplete
- Firestore as source of truth
- All dynamic logic in external JS
- Modern UX with jQuery UI

## 7. Refactors & Cleanups
- All JS moved to external files
- jQuery/jQuery UI loaded globally
- Removed redundant controllers
- Linting/code style improvements ongoing

## 8. Known Issues & TODOs
- Some PHPCS lint errors remain
- Add more robust AJAX error handling
- More modularization/testing possible

## 9. How to Extend
- **Add autocomplete:** endpoint in BookController/FirestoreService, route, field, JS
- **Add book metadata:** update Firestore, form, validation, display

## 10. Contributors & Structure
- **Controllers:** `app/Http/Controllers/Admin/BookController.php`
- **Firebase Cloud Functions:** All Firebase backend automation, API endpoints, and event-driven logic are located in the `/functions` directory. Reference this for any Firebase-related backend code or extensions.

- **Services:** `app/Services/FirestoreService.php`
- **Views:** `resources/views/admin/books/form.blade.php`, `layouts/app.blade.php`
- **JS:** `public/js/admin/books/form.js`

---

## Appendix: Screenshots

### Create Book Form
![Create Book Form](public/screenshots/Screenshot-Book%20Form.png)

### Book List View
![Book List View](public/screenshots/Screenshot-Book%20List.png)

### Import Author View
![Import Author View](public/screenshots/Screenshot-Import%20Author%20View.png)

### Login Screen
![Login Screen](public/screenshots/Screenshot-Login.png)

---
This blueprint summarizes the architecture, features, and design up to this point. Use for onboarding, planning, or future extension.
