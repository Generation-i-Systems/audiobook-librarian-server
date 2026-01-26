# Master Prompt: Audiobook Librarian Desktop App (NativePHP)

**Role**: You are an expert Laravel and NativePHP developer.
**Task**: Create a new Laravel application configured as a NativePHP desktop app that serves as the client/importer for the Audiobook Librarian server.

## 1. Project Setup

- **Name**: `audiobook-librarian-app`
- **Stack**: Laravel 11, NativePHP (Electron), Vue 3, Inertia.js, TailwindCSS, Pinia.
- **Goal**: A desktop application allowing users to import audiobooks either locally (direct file access) or remotely (via sync directory + API).

## 2. Architecture & Configuration

### Directory Structure

Establish the following structure:

```
app/
  Http/Controllers/
    AuthController.php          # Login & Token management
    ImportController.php        # Import wizard flow
    SettingsController.php      # Config (Sync path, Server URL)
  Services/
    CredentialService.php       # Wraps NativeSecureStorage for tokens
    RemoteApiService.php        # HTTP Client with Bearer auth
    SyncDirectoryService.php    # Manages local sync queue folder
    LocalImportService.php      # Logic for local file operations
  Stores/ (Pinia)
    useAuthStore                # State: user, token, serverUrl
    useImportStore              # State: current scan, metadata overrides
```

### Dependencies

- `nativephp/electron`: For desktop shell.
- `inertiajs/inertia-laravel`: For UI routing.
- `guzzlehttp/guzzle`: For API communication.

## 3. Core Features

### A. Authentication (Remote Mode)

- **UI**: Login screen asking for Server URL, Email, Password.
- **Logic**:
    1. POST `{serverUrl}/api/v1/auth/login`
    2. On success, store `token`, `server_url`, and `user_email` using `NativeSecureStorage`.
    3. Redirect to Dashboard.

### B. Settings

- **Sync Directory**: Allow user to pick a local folder (default: `~/LibrarianSync`).
- **Mode**: Toggle between "Local Library" (direct access) and "Remote Upload" (queue only).

### C. Import Wizard

1. **Scan**: User selects a folder. App recursively finds audio files.
2. **Match/Review**:
    - Parse metadata from ID3 tags.
    - **Check Duplicates**: Call `POST /api/v1/imports/check-duplicate` (see API Reference).
    - **Metadata Form**: Allow editing Title, Author (autocomplete), Series (autocomplete), Genre (dropdown).
3. **Queue/Process**:
    - Generate `librarian.json` (schema below).
    - Move files to Sync Directory structure: `{SyncDir}/queue/import-{uuid}/`.

## 4. API Reference (Server Integration)

The server implementation is complete. Use these endpoints:

- **Base URL**: `/api/v1`
- **Auth Headers**: `Authorization: Bearer {token}`

| Endpoint                   | Method | Purpose       | Payload                          |
| -------------------------- | ------ | ------------- | -------------------------------- |
| `/auth/login`              | POST   | Authenticate  | `{email, password, device_name}` |
| `/imports/check-duplicate` | POST   | Check DB      | `{title, author, series, isbn}`  |
| `/imports/genres`          | GET    | Valid genres  | -                                |
| `/authors/search`          | GET    | Autocomplete  | `?q=Query`                       |
| `/authors`                 | POST   | Create Author | `{name}`                         |
| `/series/search`           | GET    | Autocomplete  | `?q=Query`                       |
| `/series`                  | POST   | Create Series | `{name, is_collection}`          |

## 5. Data Contracts

### librarian.json Schema

**Critical**: The server's `ImportQueueService` expects this exact format in the sync directory.

```json
{
    "version": "2.0",
    "title": "Project Hail Mary",
    "authors": [{ "name": "Andy Weir" }],
    "narrators": [{ "name": "Ray Porter" }],
    "series": {
        "name": "The Martian Universe",
        "number": "2"
    },
    "genres": ["Science Fiction"],
    "description": "...",
    "year": "2021",
    "publisher": "Audible Studios",
    "isbn": "9780593135204",
    "duration": 45000,
    "cover_image": "cover.jpg",
    "directory_path": "Science Fiction/Andy Weir/Project Hail Mary",
    "audio_files": ["audiobook.m4b"],
    "import_metadata": {
        "source": "librarian-desktop",
        "version": "1.0.0",
        "user_email": "user@example.com",
        "queued_at": "2026-01-25T12:00:00Z",
        "checksum": "sha256:..."
    }
}
```

## 6. Implementation Steps for You

1.  **Initialize**: Scaffold the Laravel NativePHP app.
2.  **Scaffold UI**: Create Vue components for Login, Dashboard, and Import Review.
3.  **Implement Services**: Write `CredentialService` and `RemoteApiService`.
4.  **Implement Workflow**: Connect the Import Wizard UI to the SyncDirectoryService.
