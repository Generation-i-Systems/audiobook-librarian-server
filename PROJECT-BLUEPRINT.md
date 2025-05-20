# Audiobook Librarian Project Blueprint

## Overview
Audiobook Librarian is a Laravel-based web app for managing audiobooks, supporting admin CRUD, user management, Google Books autofill, and Firestore-backed autocomplete for authors and series. The backend also provides a REST API that supports integration with an Android app client.

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

### Book Document (Firestore)
```json
{
  "title": "The Way of Kings",
  "authors": ["Brandon Sanderson", "Co-Author Name"],
  "series": {
    "Stormlight Archive": 1,
    "Cosmere": 15
  },
  "description": "Epic fantasy novel...",
  "cover_image": "covers/way-of-kings.jpg",
  "directory_path": "audiobooks/way-of-kings",
  "genre": ["Fantasy", "Epic"],
  "created_at": "2025-05-20T14:00:00Z",
  "updated_at": "2025-05-20T14:00:00Z",
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
- **Services:** `app/Services/FirestoreService.php`
- **Views:** `resources/views/admin/books/form.blade.php`, `layouts/app.blade.php`
- **JS:** `public/js/admin/books/form.js`

---
This blueprint summarizes the architecture, features, and design up to this point. Use for onboarding, planning, or future extension.
