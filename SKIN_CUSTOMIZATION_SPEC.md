# Skin Customization & Gallery Integration Spec

## Overview
This document outlines the API changes required to support user-generated skin customizations (background images/colors) and the migration of the skin gallery to the main PHP backend.

## 1. Backend API Updates (PHP)

The existing `https://skins.librarian.app` functionality will be migrated to the main application server.

### 1.1 New Endpoints

#### Upload Customization
**POST** `/api/skins/{skinId}/customizations`
Allows a user to upload a customization for a specific skin.

**Request:**
- `Content-Type`: `multipart/form-data`
- Body:
    - `type`: "color" | "image"
    - `value`: Hex color code (if type=color)
    - `image`: File (if type=image)
    - `visibility`: "private" | "public" (default: private)

**Response:**
```json
{
    "id": "customization_123",
    "skin_id": "skin_456",
    "user_id": "user_789",
    "type": "image",
    "url": "https://api.librarian.app/storage/customizations/abc.png",
    "created_at": "2025-10-27T10:00:00Z"
}
```

#### Get Customizations
**GET** `/api/skins/{skinId}/customizations`
Lists customizations for a skin (own + public).

**Response:**
```json
[
    {
        "id": "customization_123",
        "type": "color",
        "value": "#FF5733",
        "author": "User123"
    }
]
```

### 1.2 Migration of Skin Gallery

The following endpoints will be implemented in the PHP backend to replace the Kotlin server:

- `GET /api/skins`: List/Search skins
- `GET /api/skins/{id}`: Skin details
- `POST /api/skins`: Upload new skin
- `GET /api/skins/{id}/download`: Download skin package

## 2. Client Implementation

### 2.1 UI Changes

#### Theme Customizer
- Add **Background** section.
- Options:
  - **Solid Color**: Opens color picker in place of cover art in preview.
  - **Image**: Opens system file picker to select image.

#### Preview Logic
- `SkinRenderer` will support a "Live Edit" mode.
- When selecting color, the Cover Art element is replaced by a Color Wheel/Picker.
- Background updates in real-time.

### 2.2 Data Layer
- Update `GalleryApiClient` to point to `Config.getApiBaseUrl()`.
- Add methods for `uploadCustomization`.

## 3. Server-Side Integration (Tasks)

1.  Copy skins from `~/src/audiobook-librarian-client-skins-beta/skins` to `storage/app/public/skins` on the server.
2.  Seed the database with these skins.
3.  Implement the API endpoints in `routes/api.php` and corresponding Controllers.
