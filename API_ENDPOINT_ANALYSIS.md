# API Endpoint Analysis

This document provides a comprehensive analysis of the Audiobook Librarian API endpoints, showing which are currently implemented in the client and which represent potential missing features.

**Analysis Date:** 2026-01-31
**API Spec:** https://books.thelin.org/api-docs/openapi.json

---

## Summary

| Category | Total Endpoints | Implemented | Not Implemented | Implementation Rate |
|----------|----------------|-------------|-----------------|---------------------|
| **Authentication** | 4 | 4 | 0 | 100% |
| **User Management** | 2 | 1 | 1 | 50% |
| **Books & Library** | 7 | 4 | 3 | 57% |
| **Reading Progress** | 5 | 4 | 1 | 80% |
| **Bookmarks** | 3 | 2 | 1 | 67% |
| **Status & Queue** | 5 | 3 | 2 | 60% |
| **Statistics** | 5 | 4 | 1 | 80% |
| **Messages** | 3 | 0 | 3 | 0% |
| **Health** | 2 | 0 | 2 | 0% |
| **TOTAL** | **36** | **22** | **14** | **61%** |

---

## Authentication Endpoints

### ✅ Implemented (4/4)

| Endpoint | Method | Implementation Location | Notes |
|----------|--------|------------------------|-------|
| `/auth/register` | POST | `AuthRepositoryImpl.register()` | User registration with name, username, email, password |
| `/auth/login` | POST | `AuthRepositoryImpl.loginWithUsernamePassword()` / `loginWithEmailPassword()` | Bearer token authentication |
| `/auth/forgot-password` | POST | `AuthRepositoryImpl.requestPasswordReset()` | Password reset via email |
| `/auth/reset-password` | POST | `AuthRepositoryImpl.resetPassword()` | Password reset with token |

**Additional Auth Endpoints (Not in OpenAPI spec):**
- `/auth/google` - Google Sign-In (implemented but returns 404 from backend)
- `/auth/refresh` - Token refresh (implemented)
- `/auth/logout` - Logout (implemented)
- `/check-status` - Check account approval status (implemented)

---

## User Management

### ✅ Implemented (1/2)

| Endpoint | Method | Implementation Location | Notes |
|----------|--------|------------------------|-------|
| `/user` | GET | `AuthRepositoryImpl.getCurrentUser()` | Retrieve current user info |

### ❌ Not Implemented (1/2)

| Endpoint | Method | Missing Feature | Impact |
|----------|--------|-----------------|--------|
| `/user` | PUT | **User Profile Updates** | Users cannot update their name, email, password, or profile picture from the client |

**Missing Functionality:**
- Update user profile information
- Change password (outside reset flow)
- Update email address
- Change profile picture

---

## Books & Library

### ✅ Implemented (4/7)

| Endpoint | Method | Implementation Location | Notes |
|----------|--------|------------------------|-------|
| `/books` | GET | `BookRepositoryImpl.getRecentBooks()`, `searchBooks()` | Book listing with pagination and search |
| `/books/{id}` | GET | `BookRepositoryImpl.getBookById()`, `BookApi.getBookById()` | Single book details |
| `/books/batch` | POST | `BookApi.batchBooks()` | Batch metadata retrieval |
| `/books/enhanced` | GET | `BookRepositoryImpl.getBooksByGenre()`, `getBooksByAuthor()`, `getBooksBySeries()` | Enhanced book queries (not in OpenAPI spec) |

### ❌ Not Implemented (3/7)

| Endpoint | Method | Missing Feature | Impact |
|----------|--------|-----------------|--------|
| `/books/{id}/cover` | GET | **Direct Cover Image Access** | Client cannot fetch cover images separately; must use embedded URLs |
| `/books/{id}/download/{filename}` | GET | **Individual File Downloads** | Cannot download specific audio files or covers by filename |
| `/books/{id}/download-manifest` | GET | **Download Manifest** | Partially implemented as `/books/{id}/download` |

**Note:** The client uses `/books/{id}/download` endpoint (not in OpenAPI spec) to get download manifests, which appears to be a different implementation than `/books/{id}/download-manifest`.

**Missing Functionality:**
- Direct cover image fetching (separate from book metadata)
- Individual file downloads by filename
- Standard manifest endpoint (using custom endpoint instead)

### 🔄 Custom Endpoints (Not in OpenAPI)

The client uses several endpoints not documented in the OpenAPI spec:
- `GET /books/enhanced` - Enhanced book queries with author/series/genre relationships
- `GET /books/{id}/download` - Download manifest (different from OpenAPI's `/download-manifest`)
- `POST /books/{bookId}/recommend` - Send book recommendations to other users
- `POST /recommendations/{id}/acknowledge` - Acknowledge received recommendations
- `GET /genres` - Genre listing (not in OpenAPI)
- `GET /authors` - Author listing with filtering (not in OpenAPI)
- `GET /authors/{id}` - Author details (not in OpenAPI)
- `POST /authors/{id}/favorite` - Toggle author favorite (not in OpenAPI)
- `GET /series` - Series listing (not in OpenAPI)
- `GET /series/{id}` - Series details (not in OpenAPI)
- `POST /series/{id}/favorite` - Toggle series favorite (not in OpenAPI)

---

## Reading Progress

### ✅ Implemented (4/5)

| Endpoint | Method | Implementation Location | Notes |
|----------|--------|------------------------|-------|
| `/sync/progress` | POST | `BookApi.syncProgress()` | Bulk progress synchronization |
| `/progress/{bookId}` | GET | `BookRepositoryImpl.getReadingProgress()` | Get book progress |
| `/progress` | GET | `BookRepositoryImpl.getAllReadingProgress()` | Get all progress (not in OpenAPI spec) |
| `/books/{bookId}/progress` | PUT | `BookApi.updateReadingProgress()` | Update progress (custom endpoint, not in OpenAPI) |

### ❌ Not Implemented (1/5)

| Endpoint | Method | Missing Feature | Impact |
|----------|--------|-----------------|--------|
| `/progress/{bookId}/mark-completed` | POST | **Mark Book as Completed** | Cannot explicitly mark a book as finished; must rely on progress percentage |
| `/progress/device/{deviceId}` | GET | **Device-Specific Progress** | Cannot retrieve progress for a specific device |

**Note:** The client uses `/books/{bookId}/progress` (PUT) instead of OpenAPI's `/progress/{bookId}/update` (POST).

**Missing Functionality:**
- Explicit "mark as completed" action
- Device-specific progress queries
- Reading goals and target completion dates (see Status & Queue section)

---

## Bookmarks

### ✅ Implemented (2/3)

| Endpoint | Method | Implementation Location | Notes |
|----------|--------|------------------------|-------|
| `/bookmarks/{bookId}` | GET | `BookRepositoryImpl.getBookmarks()` | List all bookmarks for a book |
| `/bookmarks/{bookId}` | POST | `BookRepositoryImpl.createBookmark()` | Create a new bookmark |

### ❌ Not Implemented (1/3)

| Endpoint | Method | Missing Feature | Impact |
|----------|--------|-----------------|--------|
| `/bookmarks/{id}` | DELETE | **Bookmark Deletion** | Users cannot remove bookmarks they've created |

**Missing Functionality:**
- Delete individual bookmarks
- Edit existing bookmarks (if API supports it)

---

## Status & Queue

### ✅ Implemented (3/5)

| Endpoint | Method | Implementation Location | Notes |
|----------|--------|------------------------|-------|
| `/status/list/{statusType}` | GET | `BookApi.getQueue()` (for "queue" status) | List books by status (queue, wishlist, etc.) |
| `/status/history` | GET | `BookApi.getHistory()` | Reading history with pagination |
| `/status/{book}/set` | POST | `BookApi.addToQueue()` | Set book status and metadata |

### ❌ Not Implemented (2/5)

| Endpoint | Method | Missing Feature | Impact |
|----------|--------|-----------------|--------|
| `/status/goals` | GET | **Reading Goals** | Cannot view books with target completion dates |
| `/status/non-library/set` | POST | **Non-Library Book Status** | Cannot track status of books outside the main library |
| `/status/queue/reorder` | POST | **Queue Reordering** | Cannot reorder the reading queue |

**Missing Functionality:**
- Reading goals and target dates display
- Tracking external/non-library books
- Manual queue reordering (priority management)
- Wishlist management (status type supported but no dedicated UI)
- "Currently Reading" status management

---

## Statistics & Sessions

### ✅ Implemented (4/5)

| Endpoint | Method | Implementation Location | Notes |
|----------|--------|------------------------|-------|
| `/statistics/overview` | GET | `BookApi.getStatisticsOverview()`, `BookRepositoryImpl.getStatisticsOverview()` | Listening time, books finished, streaks |
| `/statistics/daily` | GET | `BookApi.getDailyStats()`, `BookRepositoryImpl.getDailyStats()` | Daily listening statistics |
| `/statistics/report` | POST | `BookRepositoryImpl.reportListeningStatistics()`, `reportListeningSession()` | Record listening sessions |
| `/analytics/event` | POST | `BookRepositoryImpl.sendClientEvent()` | Client analytics (not in OpenAPI spec) |

### ❌ Not Implemented (1/5)

| Endpoint | Method | Missing Feature | Impact |
|----------|--------|-----------------|--------|
| `/statistics/reading-history` | GET | **Reading Timeline** | Cannot view completed books grouped by time period |
| `/progress/session` | POST | **Session Logging** | Alternative session endpoint not used |

**Note:** The client implements statistics reporting, but may not be using all available endpoints.

**Missing Functionality:**
- Reading timeline view (books completed by month/year)
- Detailed session metadata logging

---

## Messages

### ❌ Not Implemented (3/3)

| Endpoint | Method | Missing Feature | Impact |
|----------|--------|-----------------|--------|
| `/messages` | GET | **Receive Messages** | Cannot view messages from other users |
| `/messages` | POST | **Send Messages** | Cannot send messages to other users |
| `/messages/{id}/acknowledge` | POST | **Acknowledge Messages** | Cannot mark messages as read |

**Missing Functionality:**
- **User-to-User Messaging System**: Complete absence of messaging features
- Private communication between users
- Message notifications
- Message history

**Note:** The client implements book recommendations (`/books/{bookId}/recommend`, `/recommendations/{id}/acknowledge`) which is a related but different feature from the general messaging system.

---

## Health & Monitoring

### ❌ Not Implemented (2/2)

| Endpoint | Method | Missing Feature | Impact |
|----------|--------|-----------------|--------|
| `/health/ping` | GET | **Simple Health Check** | No uptime monitoring from client |
| `/health` | GET | **Detailed System Health** | No database/system health visibility |

**Missing Functionality:**
- Client-side health monitoring
- System status visibility
- Connection diagnostics

**Note:** These endpoints are typically used for monitoring and diagnostics, not end-user features. However, they could be useful for:
- Troubleshooting connection issues
- Displaying server status to users
- Network diagnostics in debug builds

---

## Badges System

### ✅ Fully Implemented (Custom Feature)

The client has a complete badges/achievements system not documented in the OpenAPI spec:

| Endpoint | Implementation Location |
|----------|------------------------|
| `GET /badges` | `BadgeApi.getBadges()` |
| `GET /badges/user` | `BadgeApi.getUserBadges()` |
| `GET /badges/stats` | `BadgeApi.getBadgeStats()` |
| `GET /badges/categories` | `BadgeApi.getBadgesByCategory()` |
| `GET /badges/progress` | `BadgeApi.getBadgeProgress()` |
| `GET /badges/unnotified` | `BadgeApi.getUnnotifiedBadges()` |
| `POST /badges/mark-notified` | `BadgeApi.markBadgesNotified()` |
| `GET /badges/leaderboard` | `BadgeApi.getBadgeLeaderboard()` |

This is a **complete gamification system** for achievements and leaderboards.

---

## Priority Recommendations

### High Priority - Core Functionality Gaps

1. **Bookmark Deletion** (`DELETE /bookmarks/{id}`)
   - **Why:** Basic CRUD operation missing
   - **User Impact:** Cannot remove incorrect/unwanted bookmarks
   - **Effort:** Low (single endpoint)

2. **Queue Reordering** (`POST /status/queue/reorder`)
   - **Why:** Queue management is incomplete
   - **User Impact:** Cannot prioritize reading order
   - **Effort:** Medium (UI + API)

3. **Mark Book Completed** (`POST /progress/{bookId}/mark-completed`)
   - **Why:** Explicit completion action is clearer than 100% progress
   - **User Impact:** Cannot manually mark books as finished
   - **Effort:** Low (single endpoint)

4. **User Profile Updates** (`PUT /user`)
   - **Why:** Users expect to edit their profile
   - **User Impact:** Cannot update name, email, or picture
   - **Effort:** Medium (form UI + validation)

### Medium Priority - Enhanced Features

5. **Reading Goals** (`GET /status/goals`)
   - **Why:** Goal setting improves engagement
   - **User Impact:** No target completion dates
   - **Effort:** Medium (display + date picker)

6. **Reading Timeline** (`GET /statistics/reading-history`)
   - **Why:** Visual progress history is motivating
   - **User Impact:** Cannot see historical patterns
   - **Effort:** Medium (timeline UI component)

7. **Non-Library Book Status** (`POST /status/non-library/set`)
   - **Why:** Users may want to track external books
   - **User Impact:** Limited to library books only
   - **Effort:** Medium (requires book entry form)

### Low Priority - Advanced Features

8. **User Messaging** (`GET /messages`, `POST /messages`, `POST /messages/{id}/acknowledge`)
   - **Why:** Social feature, but recommendations already exist
   - **User Impact:** No direct user communication
   - **Effort:** High (complete messaging UI)

9. **Health Monitoring** (`GET /health`, `GET /health/ping`)
   - **Why:** Useful for debugging, not user-facing
   - **User Impact:** No system status visibility
   - **Effort:** Low (debug screen only)

10. **Individual File Downloads** (`GET /books/{id}/download/{filename}`)
    - **Why:** Manifest-based downloads may be sufficient
    - **User Impact:** Cannot download specific files
    - **Effort:** Low (already have file URLs)

---

## API Discrepancies

### OpenAPI Spec vs. Actual Implementation

The client uses several endpoints not in the OpenAPI spec:

**Additional Endpoints:**
- `GET /books/enhanced` - Enhanced book queries
- `GET /genres` - Genre listing
- `GET /authors` - Author listing and filtering
- `GET /authors/{id}` - Author details
- `POST /authors/{id}/favorite` - Favorite management
- `GET /series` - Series listing
- `GET /series/{id}` - Series details
- `POST /series/{id}/favorite` - Favorite management
- `GET /progress` - All progress (not `/progress/device/{deviceId}`)
- `PUT /books/{bookId}/progress` - Progress update (different from `/progress/{bookId}/update`)
- `POST /books/{bookId}/recommend` - Recommendations
- `POST /recommendations/{id}/acknowledge` - Recommendation acknowledgment
- `POST /analytics/event` - Client analytics
- `POST /auth/google` - Google Sign-In
- `POST /auth/refresh` - Token refresh
- `POST /auth/logout` - Logout
- `POST /check-status` - Account status check
- All `/badges/*` endpoints

**Recommendation:** The OpenAPI spec should be updated to reflect the actual API implementation. This discrepancy suggests the spec is outdated or incomplete.

---

## Conclusion

The client currently implements **61% of documented API endpoints**, with particularly strong coverage in:
- Authentication (100%)
- Reading Progress (80%)
- Statistics (80%)

**Major gaps exist in:**
- Messaging (0%)
- Health Monitoring (0%)
- User Profile Management (50%)

**Key findings:**
1. The client has implemented many **custom endpoints** (badges, recommendations, enhanced queries) not in the OpenAPI spec
2. The **OpenAPI spec appears outdated** - many production endpoints are missing
3. Several **basic CRUD operations** are incomplete (bookmark deletion, queue reordering, profile updates)
4. The **messaging system is completely unused** - recommendations may serve a similar purpose
5. **Health monitoring** endpoints are not integrated - useful for diagnostics

**Next steps:**
1. Update the OpenAPI specification to match actual implementation
2. Prioritize bookmark deletion and queue reordering (quick wins)
3. Consider implementing user profile editing
4. Evaluate if the messaging system should be implemented or deprecated
5. Add health check integration for debugging/support purposes
