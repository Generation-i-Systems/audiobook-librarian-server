# Favorite Authors System

A comprehensive system for tracking favorite authors and receiving notifications when they release new audiobooks on AudiobookBay.

## Features

- **Favorite Author Management**: Mark authors as favorites via web UI or API
- **Automated Scraping**: Daily scraping of AudiobookBay categories (Sci-Fi, Fantasy, LitRPG)
- **Email Notifications**: Daily emails with new books from favorite authors
- **API Support**: Full RESTful API for client app integration
- **Admin Tools**: Automatically favorite all existing authors in the library

## Getting Started

### 1. Configure Email (Required)

Add AWS SES credentials to your `.env` file:

```env
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-east-1
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### 2. Run Migrations

```bash
php artisan migrate
```

This creates:
- `favorite_authors` table - stores user's favorite authors
- `discovered_books` table - stores books scraped from AudiobookBay

### 3. Auto-Favorite Existing Authors (Admin Only)

For the admin user, automatically favorite all authors who already have books in the library:

```bash
php artisan favorites:auto-add-existing
```

For a specific user:

```bash
php artisan favorites:auto-add-existing --user=user@example.com
```

## Usage

### Web UI

Navigate to `/favorites` to manage your favorite authors:

- **Add Favorite**: Type or select an author name from the dropdown
- **Toggle Notifications**: Enable/disable email notifications per author
- **Remove Favorite**: Delete an author from your favorites

### Artisan Commands

#### Scrape AudiobookBay Categories

```bash
# Scrape all categories (sci-fi, fantasy, litrpg)
php artisan abb:scrape-categories

# Scrape specific category
php artisan abb:scrape-categories --category=sci-fi

# Skip detail page fetching (faster but less complete data)
php artisan abb:scrape-categories --no-enrich
```

#### Send Email Notifications

```bash
# Send notifications for new books discovered in the last 24 hours
php artisan favorites:send-notifications

# Force send all un-notified books (ignore 24-hour window)
php artisan favorites:send-notifications --force
```

### API Endpoints

All endpoints require authentication via Sanctum token.

#### List Favorite Authors

```http
GET /api/v1/favorites
```

Response:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "author_name": "Brandon Sanderson",
      "notify_email": true,
      "created_at": "2025-12-12T12:00:00Z"
    }
  ]
}
```

#### Add Favorite Author

```http
POST /api/v1/favorites
Content-Type: application/json

{
  "author_name": "Brandon Sanderson",
  "notify_email": true
}
```

#### Remove Favorite Author

```http
DELETE /api/v1/favorites/{id}
```

#### Toggle Email Notifications

```http
PUT /api/v1/favorites/{id}
Content-Type: application/json

{
  "notify_email": false
}
```

#### Get New Books for Your Favorites

```http
GET /api/v1/favorites/new-books
```

Returns books discovered in the last 7 days matching your favorite authors.

## Scheduled Tasks

The system automatically runs these tasks daily:

- **4:00 AM**: Scrape AudiobookBay categories for new books
- **8:00 AM**: Send email notifications to users with new books

Schedule is configured in `bootstrap/app.php`.

To enable scheduled tasks, add to your crontab:

```cron
* * * * * cd /path/to/librarian && php artisan schedule:run >> /dev/null 2>&1
```

## How It Works

### Category Scraping

1. For each category (sci-fi, fantasy, litrpg), the scraper:
   - Fetches the category page from AudiobookBay
   - Extracts basic book info (title, author, cover)
   - Continues to next pages until reaching the last seen book
   - Optionally fetches detail pages for complete metadata

2. Books are saved to the `discovered_books` table with:
   - ABB ID (unique identifier)
   - Title, author, narrator
   - Category, URL, cover image
   - Description and metadata
   - Discovery timestamp

### Author Matching

Books are matched to favorite authors using fuzzy string matching:
- Both author names are normalized (lowercase, trimmed)
- Match if book author contains favorite author name or vice versa
- Example: "Eoin Colfer" matches "Artemis Fowl by Eoin Colfer"

### Email Notifications

1. Daily at 8:00 AM:
   - Query all users with favorite authors
   - For each user, find new books (discovered in last 24h) matching their favorites
   - Send HTML email with book details and links
   - Mark books as notified to prevent duplicates

2. Email includes:
   - Book title, author, narrator
   - Cover image
   - Description preview
   - Category badge
   - Link to AudiobookBay

## Database Schema

### favorite_authors
```sql
id                        BIGINT UNSIGNED PRIMARY KEY
user_id                   BIGINT UNSIGNED (FK to users)
author_name               VARCHAR(255)
notify_email              BOOLEAN (default: true)
last_notification_sent_at TIMESTAMP NULL
created_at                TIMESTAMP
updated_at                TIMESTAMP

UNIQUE (user_id, author_name)
INDEX (author_name)
```

### discovered_books
```sql
id            BIGINT UNSIGNED PRIMARY KEY
abb_id        VARCHAR(255) UNIQUE
title         VARCHAR(255)
author        VARCHAR(255) NULL
narrator      VARCHAR(255) NULL
category      VARCHAR(255)
url           VARCHAR(255)
description   TEXT NULL
cover_url     VARCHAR(255) NULL
metadata      JSON NULL
discovered_at TIMESTAMP
notified      BOOLEAN (default: false)
created_at    TIMESTAMP
updated_at    TIMESTAMP

INDEX (author, discovered_at)
INDEX (category, discovered_at)
INDEX (notified, discovered_at)
```

## Future Enhancements

As noted in the initial requirements, future features will include:

- **Book Download Requests**: Users can request specific books to be downloaded
- **In-App Notifications**: Push notifications for client apps
- **Additional Categories**: Expand beyond sci-fi, fantasy, litrpg
- **Advanced Matching**: Improve author matching algorithm
- **Download Queue Integration**: Auto-queue books from favorite authors

## Troubleshooting

### Emails Not Sending

1. Verify AWS SES credentials in `.env`
2. Check email is verified in AWS SES (sandbox mode)
3. Review logs: `storage/logs/favorite-notifications.log`

### Scraping Not Working

1. Check AudiobookBay is accessible
2. Verify category URLs in `AudiobookBayCategoryScraperService.php`
3. Review logs: `storage/logs/abb-scraping.log`

### No Books Being Discovered

1. Ensure favorite authors match ABB author names
2. Check last seen book ID isn't blocking new books
3. Run scraper manually with verbose output

## Testing

Test the scraping system manually:

```bash
# Scrape first page only (initial run)
php artisan abb:scrape-categories --category=sci-fi

# Force send test notification
php artisan favorites:send-notifications --force
```

## Security Notes

- All API endpoints require authentication
- Users can only manage their own favorites
- Email addresses are never exposed via API
- ABB scraping respects rate limits (0.5s between pages)
