# Audiobook Librarian

A Laravel-based audiobook library and management system for personal and family use. Features a RESTful API, web interface (admin & user), and powerful console utilities for maintaining and repairing your audiobook collection.

---

## Table of Contents
- [Features](#features)
- [API Specification](#api-specification)
- [Console Commands & Jobs](#console-commands--jobs)
- [Web Interface](#web-interface)
  - [Admin](#admin)
  - [User](#user)
- [Setup](#setup)
- [License](#license)

---

## Features
- RESTful API for book, author, series, genre, and user management
- Web UI for browsing, searching, downloading, and managing audiobooks
- Admin dashboard for managing users, books, and repair jobs
- Repair commands for fixing metadata, covers, and cleaning up missing files
- Queue-based download manager
- Reading progress tracking
- User authentication (API & web)

---

## API Specification

**Base URL:** `/api/v1/`

### Authentication & Headers
- Most endpoints require authentication via Laravel Sanctum (`Authorization: Bearer <token>`).
- Responses are JSON. Errors use standard HTTP codes with a JSON error message.

### Authentication
- `POST /register`
  - **Body:** `{ "name": "...", "email": "...", "password": "..." }`
  - **Response:** `{ "token": "...", "user": { ... } }`
- `POST /login`
  - **Body:** `{ "email": "...", "password": "..." }`
  - **Response:** `{ "token": "...", "user": { ... } }`
- `POST /logout` — Requires auth, invalidates the token.

### User
- `GET /user` — Get current user (requires auth)
- `GET /me` — Alias for `/user`

### Books
- `GET /books`
  - **Query Parameters:**
    - `query`: Search string (matches title, author, or series)
    - `author_id` or `author`: Filter by author ID or name
    - `series_id` or `series`: Filter by series ID or name
    - `genre_id` or `genre`: Filter by genre ID or name
    - `published_year`: Filter by publication year
    - `date_added`: Filter by date the book was added
    - `with_cover` (bool, default: true): Include cover info
    - `inlineCovers` (bool, default: false): Return base64-encoded cover images instead of URLs
    - `per_page` (default: 100): Results per page
    - `page` (default: 1): Page number
  - **Response:**
    ```json
    {
      "data": [ { "id": 1, "title": "...", ... } ],
      "meta": { "current_page": 1, ... }
    }
    ```

- `GET /books/{book}` — Get book details
  - **Query Parameters:**
    - `with_cover` (bool, default: true)
    - `inlineCovers` (bool, default: false)
  - **Response:**
    ```json
    {
      "id": 1,
      "title": "...",
      "author": { ... },
      "series": { ... },
      "cover_url": "/api/v1/books/1/cover",
      "download_url": "/api/v1/books/1/download",
      ...
    }
    ```

- `GET /books/{book}/cover` — Returns image or 404
- `GET /books/{book}/download` — Returns audio file (requires permission)

- `GET /books/browse`
  - **Query Parameters:**
    - `type` (required): One of `genre`, `author`, `series`
    - `search`: Filter by name
    - `per_page` (default: 100)
    - `page` (default: 1)
  - **Response:** Paginated list of genres, authors, or series

- `GET /books/search`
  - **Query Parameters:**
    - `title`: Filter by book title
    - `author`: Filter by author name
    - `series`: Filter by series name
    - `genre`: Filter by genre name
    - `published_year`: Filter by publication year
    - `date_added`: Filter by date added
    - `with_cover` (bool, default: true)
    - `inlineCovers` (bool, default: false)
    - `per_page` (default: 100)
    - `page` (default: 1)
  - **Response:** Paginated list of books matching criteria

- `GET /books/browse` — Browse books by genre/author
- `GET /books/search` — Advanced search (see `/books`)
- `POST /books/queue/download` — Queue books for zipped download
  - **Body:** `{ "book_ids": [1,2,3] }`
  - **Response:** `{ "zip_id": "..." }`
- `GET /books/queue/download/{zipId}` — Download zipped books
- `POST /books/queue/download/{zipId}/mark-downloaded` — Mark zip as downloaded

### Series
- `GET /series/{seriesId}/books` — List books in a series
  - **Response:** Array of book objects

### Authors
- `GET /authors/{authorId}/books` — List books by author
- `GET /authors/{authorId}/series` — List series by author
- `GET /authors/{authorId}/genres/{genreId}/books` — List books by author & genre

### Genres
- `GET /genres` — List genres
- `GET /genres/{genre}/authors` — List authors by genre
- `GET /genres/{genreId}/authors` — List authors by genre (simple)

### Book Requests
- `POST /book-requests`
  - **Body:** `{ "title": "...", "author": "...", ... }`
  - **Response:** `{ "status": "ok", ... }`

### Follow/Unfollow
- `POST /follow/{followableType}/{followableId}` — Follow an author/series
- `DELETE /unfollow/{followableType}/{followableId}` — Unfollow

### Reading Progress
- `POST /reading-progress/{book}`
  - **Body:** `{ "progress": 0.5 }`
  - **Response:** `{ "status": "ok" }`
- `GET /reading-progress/{book}`
  - **Response:** `{ "progress": 0.5 }`

### Messages
- `POST /messages`
  - **Body:** `{ "message": "..." }`
  - **Response:** `{ "status": "sent" }`

#### API Response Example (Book)
```json
{
  "id": 123,
  "title": "Book Title",
  "author": {
    "id": 1,
    "name": "Author Name"
  },
  "series": {
    "id": 2,
    "name": "Series Name",
    "number": 1
  },
  "cover_url": "/api/v1/books/123/cover",
  "download_url": "/api/v1/books/123/download",
  "genres": ["Sci-Fi", "Adventure"],
  "description": "...",
  "duration": 36000,
  ...
}
```

#### Error Response Example
```json
{
  "error": "Not Found"
}
```

#### Pagination Details
Most list endpoints return paginated responses. The structure is:

```json
{
  "data": [ /* array of resource objects, e.g. books */ ],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 20,
    "total": 200,
    "from": 1,
    "to": 20,
    "path": "/api/v1/books",
    "links": {
      "first": "/api/v1/books?page=1",
      "last": "/api/v1/books?page=10",
      "prev": null,
      "next": "/api/v1/books?page=2"
    }
  }
}
```
- `data`: Array of items for this page.
- `meta.current_page`: Current page number.
- `meta.last_page`: Last available page.
- `meta.per_page`: Items per page.
- `meta.total`: Total number of items matching the query.
- `meta.from`/`meta.to`: Range of items in this page.
- `meta.path`: Base API endpoint.
- `meta.links`: URLs for navigation (null if not available).
- Use `?page=N&per_page=M` to control pagination.

#### Example: Requesting Page 2
```
GET /api/v1/books?page=2&per_page=10
```

#### Example: Error Response (Validation)
```json
{
  "error": "Validation Error",
  "details": {
    "email": ["The email field is required."]
  }
}
```

---

## Console Commands & Jobs

### `php artisan books:repair`
Repairs book covers and series numbers in the database.
- **Options:**
  - `book_id`: Only repair the book with this ID.
  - `directory_path`: Only repair books in this directory path.
- **Behavior:**
  - Scans for books with missing/placeholder covers or series numbers.
  - Attempts to extract covers from storage or embedded in `.m4b` files.
  - Parses series numbers from directory names using flexible patterns.
  - Logs progress and results.
- **Sample Output:**
  ```
  [INFO] Repairing 5 books...
  [OK] Updated cover for 'Book Title'
  [WARN] Could not find cover for 'Another Book'
  [OK] Set series number for 'Book 3' to 3
  [DONE] Repair complete.
  ```
- **On Failure:**
  - Logs errors and continues with remaining books.

### `php artisan books:repair-no-audio [parent_path] [--delete] [--interactive] [--verbose]`
Finds books whose directories contain no audio files.
- **Arguments:**
  - `parent_path`: Only consider books under this directory path.
- **Options:**
  - `--delete`: Delete matching books from the database (no prompt).
  - `--interactive`: For each book, show directory contents and prompt to accept/delete/edit.
  - `--verbose`: Output raw SQL and debug info for each book.
- **Behavior:**
  - Scans all (or filtered) books, checks for audio files (`.m4b`, `.mp3`, `.m4a`, etc.).
  - In interactive mode, shows file list and lets you delete or accept each book.
  - If a book's directory is missing, prompts to delete the DB entry.
- **Sample Output:**
  ```
  [INFO] Scanning for books with no audio files...
  [WARN] NO AUDIO: 'Empty Book' (/path/to/book)
  [INFO] Directory contents for /path/to/book:
    - cover.jpg
    - info.txt
  Action? [Accept, Delete Book, Edit]
  [OK] Deleted book DB entry: 'Empty Book' (42)
  [DONE] Found 2 books with no audio files.
  ```
- **On Failure:**
  - Logs warnings for missing directories or permission errors.

### `php artisan make:admin`
Creates a new admin user.
- **Prompts:** for email, password, etc.
- **Behavior:**
  - Adds a user with admin privileges.
  - Notifies if the user already exists.
- **Sample Output:**
  ```
  [INFO] Creating admin user...
  [OK] Admin user created: admin@example.com
  ```

### Background Jobs
- Download zips and other long-running tasks are queued and processed asynchronously.
- Jobs log progress and errors to the database and/or log files.

---

## Web Interface

### Admin
- Dashboard for managing books, users, series, and genres
- Run repair jobs from the web
- View logs and system status

### User
- Browse/search audiobooks
- Download audio (if permitted)
- Track reading/listening progress
- Request new books
- Follow authors/series

---

## Setup

1. Clone the repo
2. Run `composer install`
3. Copy `.env.example` to `.env` and set DB/storage config
4. Run `php artisan migrate --seed`
5. Create an admin: `php artisan make:admin`
6. Start the server: `php artisan serve`

---

## License

MIT
