# API Documentation: Recommendations & Book Tracking

## 1. General Notes

- **Authentication**: All endpoints require a standard Bearer Token (Laravel Sanctum).
- **Response Format**: All JSON responses follow the **camelCase** naming convention for attributes (e.g., `bookId`, `coverImage`, `acknowledgedAt`).
- **Base URL**: `/api/v1/`

---

## 2. Book Recommendations

This system allows users to share books with others and view recommendations received from friends.

### Send a Recommendation

Share a specific book with another user.

- **URL**: `POST /recommendations/{bookId}`
- **Body**:
    ```json
    {
      "recipientId": 45,
      "message": "You should really listen to this!" (Optional, max 500 chars)
    }
    ```
- **Success (201 Created)**:
    ```json
    { "message": "Recommendation sent successfully." }
    ```
- **Errors**:
    - `422`: Validation error (e.g., trying to recommend to self).
    - `409`: Conflict (an unacknowledged recommendation for this book already exists for this recipient).

### Get Recommendation Inbox

Retrieve all recommendations sent to the authenticated user that have not yet been acknowledged.

- **URL**: `GET /recommendations/inbox`
- **Success (200 OK)**:
    ```json
    [
        {
            "id": 10,
            "bookId": 123,
            "message": "Found this for you",
            "createdAt": "2026-01-15T10:00:00Z",
            "sender": {
                "id": 5,
                "name": "John Doe"
            },
            "book": {
                "id": 123,
                "title": "Project Hail Mary",
                "coverImage": "hail-mary.jpg"
            }
        }
    ]
    ```

### Acknowledge a Recommendation

Mark a received recommendation as "read" or "accepted."

- **URL**: `POST /recommendations/{recommendationId}/acknowledge`
- **Success (200 OK)**:
    ```json
    { "message": "Recommendation acknowledged." }
    ```

---

## 3. Book Status Tracking

Manage a user's personal relationship with a book (Queue, Wishlist, Reading Progress).

### Set Book Status

Create or update the status of a book for the current user.

- **URL**: `POST /status/{bookId}/set`
- **Valid Statuses**: `queue`, `in_progress`, `completed`, `wishlist`
- **Body**:
    ```json
    {
      "status": "in_progress",
      "order": 1, (Optional, used for sorting queue)
      "statusDetail": { "device": "iPhone 15", "appVersion": "1.2.0" } (Optional JSON object)
    }
    ```
- **Success (200 OK)**:
    ```json
    { "message": "Book status updated to in_progress." }
    ```

### List Books by Status

Get a paginated list of books assigned to a specific status.

- **URL**: `GET /status/list/{status}`
- **Example**: `GET /status/list/queue`
- **Success (200 OK)**:
  Returns a standard paginated response containing Book objects.
    ```json
    {
      "data": [
        { "bookId": 123, "title": "...", "status": "queue", "order": 1 }
      ],
      "links": { ... },
      "meta": { ... }
    }
    ```

### Reorder Queue

Bulk update the ordering of books in the user's "Queue."

- **URL**: `POST /status/queue/reorder`
- **Body**:
    ```json
    {
        "bookOrders": [
            { "bookId": 101, "order": 1 },
            { "bookId": 105, "order": 2 },
            { "bookId": 99, "order": 3 }
        ]
    }
    ```
- **Success (200 OK)**:
    ```json
    { "message": "Queue reordered successfully." }
    ```

---

## 4. Error Handling

The API returns structured JSON for all errors:

```json
{
  "error": true,
  "message": "Human readable error message",
  "errors": { ... } // Detailed validation errors if status is 422
}
```

**Common Status Codes:**

- `401`: Unauthorized (Token missing or expired).
- `403`: Forbidden (Trying to access another user's data).
- `404`: Not Found (Invalid Book ID).
- `422`: Unprocessable Entity (Validation failed).
