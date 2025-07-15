# API Enhancements for Client Development

This document outlines the enhancements needed to address missing API features identified during client development.

## 1. Authentication

### Google OAuth Integration

**New Endpoint:** `/api/v1/auth/google`

```yaml
/auth/google:
  post:
    tags:
      - Authentication
    summary: Authenticate with Google OAuth
    description: Exchange a Google OAuth token for an app JWT token
    operationId: googleAuth
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              id_token:
                type: string
                description: Google OAuth ID token
              access_token:
                type: string
                description: Google OAuth access token (alternative to ID token)
            required:
              - id_token
    responses:
      '200':
        description: Authentication successful
        content:
          application/json:
            schema:
              type: object
              properties:
                token:
                  type: string
                  description: JWT token for authenticating with the API
                user:
                  $ref: '#/components/schemas/User'
                is_new_user:
                  type: boolean
                  description: Whether this is a newly created account
      '400':
        description: Invalid Google token
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
```

## 2. Book Search by Narrator

**Enhance Endpoint:** `/api/v1/books`

Add narrator filter parameter:

```yaml
parameters:
  - name: narrator
    in: query
    description: Filter books by narrator name (partial match)
    required: false
    schema:
      type: string
```

## 3. Book Download Metadata

**New Endpoint:** `/api/v1/books/{book}/download/manifest`

```yaml
/books/{book}/download/manifest:
  get:
    tags:
      - Books
    summary: Get book download manifest
    description: Returns metadata about the structure of the book download zip
    operationId: getBookDownloadManifest
    parameters:
      - name: book
        in: path
        description: Book ID or slug
        required: true
        schema:
          type: string
    responses:
      '200':
        description: Successful operation
        content:
          application/json:
            schema:
              type: object
              properties:
                book_id:
                  type: string
                  description: Book ID
                title:
                  type: string
                  description: Book title
                format:
                  type: string
                  enum: [mp3, m4b, mixed]
                  description: Audio file format
                chapters:
                  type: array
                  items:
                    type: object
                    properties:
                      number:
                        type: integer
                        description: Chapter number
                      title:
                        type: string
                        description: Chapter title
                      duration:
                        type: integer
                        description: Duration in seconds
                      file:
                        type: string
                        description: Filename in the zip
                has_cover:
                  type: boolean
                  description: Whether the zip includes a cover image
                cover_file:
                  type: string
                  description: Filename of the cover image in the zip
                total_duration:
                  type: integer
                  description: Total duration in seconds
                total_size:
                  type: integer
                  description: Total size in bytes
      '404':
        description: Book not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []
```

## 4. Audio File Format Specification

Add format information to both the download manifest endpoint (above) and enhance the download endpoint response:

**Enhance Endpoint:** `/api/v1/books/{book}/download`

Add format information to the response:

```yaml
responses:
  '200':
    content:
      application/zip:
        schema:
          type: string
          format: binary
        description: |
          Zip file containing audio files in the specified format(s).
          For MP3 files: Each chapter is a separate file named by chapter number.
          For M4B files: A single file with embedded chapter markers.
          Check the manifest endpoint for detailed file structure information.
```

## 5. Per-Chapter Bookmarks and Notes

**New Endpoints:**

```yaml
/books/{book}/bookmarks:
  get:
    tags:
      - Books
      - Bookmarks
    summary: Get bookmarks for a book
    description: Returns all bookmarks for a specific book
    operationId: getBookBookmarks
    parameters:
      - name: book
        in: path
        description: Book ID or slug
        required: true
        schema:
          type: string
    responses:
      '200':
        description: Successful operation
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  type: array
                  items:
                    $ref: '#/components/schemas/Bookmark'
      '404':
        description: Book not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []
      
  post:
    tags:
      - Books
      - Bookmarks
    summary: Create a bookmark
    description: Creates a new bookmark for a specific book
    operationId: createBookmark
    parameters:
      - name: book
        in: path
        description: Book ID or slug
        required: true
        schema:
          type: string
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              chapter:
                type: integer
                description: Chapter number
              position:
                type: integer
                description: Position in seconds
              title:
                type: string
                description: Bookmark title
              note:
                type: string
                description: Bookmark note
            required:
              - chapter
              - position
    responses:
      '201':
        description: Bookmark created
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  $ref: '#/components/schemas/Bookmark'
      '404':
        description: Book not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []

/books/{book}/bookmarks/{bookmark}:
  get:
    tags:
      - Books
      - Bookmarks
    summary: Get bookmark details
    description: Returns a specific bookmark
    operationId: getBookmark
    parameters:
      - name: book
        in: path
        description: Book ID or slug
        required: true
        schema:
          type: string
      - name: bookmark
        in: path
        description: Bookmark ID
        required: true
        schema:
          type: string
    responses:
      '200':
        description: Successful operation
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  $ref: '#/components/schemas/Bookmark'
      '404':
        description: Bookmark not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []
      
  put:
    tags:
      - Books
      - Bookmarks
    summary: Update bookmark
    description: Updates a specific bookmark
    operationId: updateBookmark
    parameters:
      - name: book
        in: path
        description: Book ID or slug
        required: true
        schema:
          type: string
      - name: bookmark
        in: path
        description: Bookmark ID
        required: true
        schema:
          type: string
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              chapter:
                type: integer
                description: Chapter number
              position:
                type: integer
                description: Position in seconds
              title:
                type: string
                description: Bookmark title
              note:
                type: string
                description: Bookmark note
    responses:
      '200':
        description: Bookmark updated
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  $ref: '#/components/schemas/Bookmark'
      '404':
        description: Bookmark not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []
      
  delete:
    tags:
      - Books
      - Bookmarks
    summary: Delete bookmark
    description: Deletes a specific bookmark
    operationId: deleteBookmark
    parameters:
      - name: book
        in: path
        description: Book ID or slug
        required: true
        schema:
          type: string
      - name: bookmark
        in: path
        description: Bookmark ID
        required: true
        schema:
          type: string
    responses:
      '204':
        description: Bookmark deleted
      '404':
        description: Bookmark not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []
```

## 6. Push Notification Registration

**New Endpoints:**

```yaml
/notifications/register:
  post:
    tags:
      - Notifications
    summary: Register for push notifications
    description: Register a device token for push notifications
    operationId: registerForNotifications
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              device_token:
                type: string
                description: Device token for push notifications
              device_type:
                type: string
                enum: [ios, android, web]
                description: Device type
            required:
              - device_token
              - device_type
    responses:
      '201':
        description: Device registered for notifications
        content:
          application/json:
            schema:
              type: object
              properties:
                message:
                  type: string
                  example: "Device registered for notifications"
                device_id:
                  type: string
                  description: Device ID for reference
      '400':
        description: Invalid request
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []

/notifications/unregister:
  post:
    tags:
      - Notifications
    summary: Unregister from push notifications
    description: Unregister a device from push notifications
    operationId: unregisterFromNotifications
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              device_token:
                type: string
                description: Device token to unregister
            required:
              - device_token
    responses:
      '200':
        description: Device unregistered
        content:
          application/json:
            schema:
              type: object
              properties:
                message:
                  type: string
                  example: "Device unregistered from notifications"
      '404':
        description: Device token not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []

/notifications/preferences:
  get:
    tags:
      - Notifications
    summary: Get notification preferences
    description: Get the current user's notification preferences
    operationId: getNotificationPreferences
    responses:
      '200':
        description: Notification preferences
        content:
          application/json:
            schema:
              type: object
              properties:
                new_books_by_followed_authors:
                  type: boolean
                  description: Notify when authors I follow release new books
                new_books_in_followed_series:
                  type: boolean
                  description: Notify when new books in series I follow are released
                system_announcements:
                  type: boolean
                  description: Notify about system announcements
    security:
      - bearerAuth: []
      
  put:
    tags:
      - Notifications
    summary: Update notification preferences
    description: Update the current user's notification preferences
    operationId: updateNotificationPreferences
    requestBody:
      required: true
      content:
        application/json:
          schema:
            type: object
            properties:
              new_books_by_followed_authors:
                type: boolean
                description: Notify when authors I follow release new books
              new_books_in_followed_series:
                type: boolean
                description: Notify when new books in series I follow are released
              system_announcements:
                type: boolean
                description: Notify about system announcements
    responses:
      '200':
        description: Preferences updated
        content:
          application/json:
            schema:
              type: object
              properties:
                message:
                  type: string
                  example: "Notification preferences updated"
                preferences:
                  type: object
                  properties:
                    new_books_by_followed_authors:
                      type: boolean
                    new_books_in_followed_series:
                      type: boolean
                    system_announcements:
                      type: boolean
    security:
      - bearerAuth: []
```

## 7. Narrator Details

**New Endpoints:**

```yaml
/narrators:
  get:
    tags:
      - Narrators
    summary: List narrators
    description: Returns a paginated list of narrators
    operationId: listNarrators
    parameters:
      - name: page
        in: query
        description: Page number
        required: false
        schema:
          type: integer
          default: 1
      - name: per_page
        in: query
        description: Items per page
        required: false
        schema:
          type: integer
          default: 20
          maximum: 100
      - name: sort
        in: query
        description: Sort field
        required: false
        schema:
          type: string
          enum: [name, book_count]
          default: name
      - name: order
        in: query
        description: Sort order
        required: false
        schema:
          type: string
          enum: [asc, desc]
          default: asc
    responses:
      '200':
        description: Successful operation
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  type: array
                  items:
                    $ref: '#/components/schemas/NarratorSummary'
                meta:
                  $ref: '#/components/schemas/PaginationMeta'
    security:
      - bearerAuth: []

/narrators/{narrator}:
  get:
    tags:
      - Narrators
    summary: Get narrator details
    description: Returns detailed information about a specific narrator
    operationId: getNarrator
    parameters:
      - name: narrator
        in: path
        description: Narrator ID or slug
        required: true
        schema:
          type: string
    responses:
      '200':
        description: Successful operation
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  $ref: '#/components/schemas/Narrator'
      '404':
        description: Narrator not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []

/narrators/{narrator}/books:
  get:
    tags:
      - Narrators
      - Books
    summary: Get books by narrator
    description: Returns a paginated list of books narrated by a specific narrator
    operationId: getBooksByNarrator
    parameters:
      - name: narrator
        in: path
        description: Narrator ID or slug
        required: true
        schema:
          type: string
      - name: page
        in: query
        description: Page number
        required: false
        schema:
          type: integer
          default: 1
      - name: per_page
        in: query
        description: Items per page
        required: false
        schema:
          type: integer
          default: 20
          maximum: 100
    responses:
      '200':
        description: Successful operation
        content:
          application/json:
            schema:
              type: object
              properties:
                data:
                  type: array
                  items:
                    $ref: '#/components/schemas/BookSummary'
                meta:
                  $ref: '#/components/schemas/PaginationMeta'
      '404':
        description: Narrator not found
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
    security:
      - bearerAuth: []
```

## 8. New Schema Definitions

```yaml
# Add these to the components/schemas section

Bookmark:
  type: object
  properties:
    id:
      type: string
      description: Bookmark ID
    book_id:
      type: string
      description: Book ID
    chapter:
      type: integer
      description: Chapter number
    position:
      type: integer
      description: Position in seconds
    title:
      type: string
      description: Bookmark title
    note:
      type: string
      description: Bookmark note
    created_at:
      type: string
      format: date-time
      description: Creation timestamp
    updated_at:
      type: string
      format: date-time
      description: Last update timestamp
  example:
    id: "bm_123456"
    book_id: "book_123456"
    chapter: 3
    position: 1250
    title: "Important quote"
    note: "This is where the protagonist realizes the truth"
    created_at: "2025-07-10T10:30:00.000000Z"
    updated_at: "2025-07-10T10:30:00.000000Z"

NarratorSummary:
  type: object
  properties:
    id:
      type: string
      description: Narrator ID
    name:
      type: string
      description: Narrator name
    book_count:
      type: integer
      description: Number of books narrated
    image_url:
      type: string
      nullable: true
      description: URL to narrator image
  example:
    id: "narrator_123456"
    name: "Ray Porter"
    book_count: 42
    image_url: "https://books.thelin.org/storage/narrators/ray-porter.jpg"

Narrator:
  type: object
  properties:
    id:
      type: string
      description: Narrator ID
    name:
      type: string
      description: Narrator name
    biography:
      type: string
      nullable: true
      description: Narrator biography
    website:
      type: string
      nullable: true
      description: Narrator website
    image_url:
      type: string
      nullable: true
      description: URL to narrator image
    book_count:
      type: integer
      description: Number of books narrated
    top_series:
      type: array
      items:
        $ref: '#/components/schemas/SeriesSummary'
      description: Top series narrated by this narrator
    top_authors:
      type: array
      items:
        $ref: '#/components/schemas/AuthorSummary'
      description: Top authors worked with
  example:
    id: "narrator_123456"
    name: "Ray Porter"
    biography: "Ray Porter is an American film and television actor and voice actor, known for his deep, resonant voice and acclaimed audiobook narrations."
    website: "https://www.rayporter.com"
    image_url: "https://books.thelin.org/storage/narrators/ray-porter.jpg"
    book_count: 42
    top_series: [
      {
        "id": "series_123456",
        "seriesName": "Bobiverse",
        "book_count": 4
      },
      {
        "id": "series_234567",
        "seriesName": "Joe Ledger",
        "book_count": 10
      }
    ]
    top_authors: [
      {
        "id": "author_123456",
        "name": "Dennis E. Taylor",
        "book_count": 7
      },
      {
        "id": "author_234567",
        "name": "Jonathan Maberry",
        "book_count": 15
      }
    ]
```

## Implementation Plan

1. First, add the schema definitions to the components/schemas section
2. Then, add the new endpoints in logical groups:
   - Authentication (Google OAuth)
   - Book features (search by narrator, download manifest)
   - Bookmarks endpoints
   - Narrator endpoints 
   - Push notification endpoints
3. Update existing endpoints where necessary (e.g., adding narrator filter to books search)
4. Test the updated OpenAPI specification with a validator

This approach ensures we address all the missing features while maintaining the consistency and organization of the API documentation.
