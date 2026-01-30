# Backend Support for Favorites

To support the new "Favorites" feature in the client, we need the following updates to the backend API and database.

## Database Schema Updates

We need to store the favorite status for Authors and Series, associated with a specific user.

### New Tables (or modifying existing pivot tables)

Assuming a many-to-many relationship between Users and Authors/Series.

**`user_author_favorites`** (or similar)
- `user_id`: Integer, Foreign Key
- `author_id`: Integer, Foreign Key
- `created_at`: Timestamp

**`user_series_favorites`** (or similar)
- `user_id`: Integer, Foreign Key
- `series_id`: Integer, Foreign Key
- `created_at`: Timestamp

## API Endpoints

### 1. Toggle Author Favorite
**POST** `/api/authors/{id}/favorite`

**Request Body:**
```json
{
  "is_favorite": true  // or false
}
```

**Response:**
- 200 OK
- Returns the updated Author object (optional, but helpful).

### 2. Toggle Series Favorite
**POST** `/api/series/{id}/favorite`

**Request Body:**
```json
{
  "is_favorite": true // or false
}
```

**Response:**
- 200 OK
- Returns the updated Series object.

### 3. Get Favorite Authors
**GET** `/api/authors?favorites=true`

- Add a `favorites` boolean query parameter to the existing `/authors` endpoint.
- When `favorites=true`, return only the authors favorited by the current user.
- Supports existing pagination.

### 4. Get Favorite Series
**GET** `/api/series?favorites=true`

- Add a `favorites` boolean query parameter to the existing `/series` endpoint.
- When `favorites=true`, return only the series favorited by the current user.
- Supports existing pagination.

### 5. Update Entity Responses
Update the response DTOs for:
- `Author` (in `/authors`, `/authors/{id}`, and search results)
- `Series` (in `/series`, `/series/{id}`, and search results)

**Add field:**
- `is_favorite`: Boolean (true if the authenticated user has favorited this item, false otherwise).

## Logic
- When fetching lists or details of Authors/Series, the backend must check the pivot tables to determine the `is_favorite` status for the requesting user.
