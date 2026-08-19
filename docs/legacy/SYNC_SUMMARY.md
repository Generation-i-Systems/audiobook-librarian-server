# API Sync Summary - Quick Reference

**Date:** 2026-02-01
**Full Analysis:** See [API_SYNC_ANALYSIS.md](./API_SYNC_ANALYSIS.md)

## Critical Findings

### 🚨 Missing from Local Docs (Implement Client Support)

1. **Authentication**
   - `POST /auth/login` - Standard email/password login
   - `POST /auth/register` - Account registration
   - `POST /auth/forgot-password` & `POST /auth/reset-password`

2. **Core Book API**
   - `GET /books` - Primary library listing (CRITICAL)
   - `POST /books/batch` - Batch fetch for performance

3. **Progress Tracking**
   - `POST /sync/progress` - Bulk sync (CRITICAL)
   - `GET/PUT /books/{id}/progress` - Progress CRUD
   - `POST /books/{id}/sessions` - Session logging
   - `PUT /books/{id}/complete` - Mark finished

4. **Reading Status**
   - `GET/POST/PUT/DELETE /user/books` - Status management (CRITICAL)
   - Statuses: `queue`, `wishlist`, `completed`, `in_progress`, `paused`, `dropped`

5. **Customization**
   - `GET /skins`, `POST /skins`, `POST /skins/{id}/rate`
   - `GET /themes`, `POST /themes`, `POST /themes/{id}/rate`

### ✅ Backend Should Implement (From Local Docs)

**High Priority:**
- `POST /auth/google` - Google OAuth
- `POST /auth/refresh` - JWT refresh
- `POST /auth/logout` - Session logout
- `POST /authors/{id}/favorite` - Author favorites
- `POST /series/{id}/favorite` - Series favorites
- `DELETE /bookmarks/{id}` - Bookmark deletion

**Medium Priority:**
- `GET /status/goals` - Reading goals with target dates
- `POST /status/queue/reorder` - Queue management
- `POST /status/non-library/set` - External book tracking
- `GET /statistics/reading-history` - Reading timeline
- `GET /progress/device/{deviceId}` - Device-specific progress

**Low Priority:**
- `GET /health`, `GET /health/ping` - Health checks
- `POST /analytics/event` - Client analytics
- `POST /check-status` - Account approval status

## Key Discrepancies

### Messaging System
- **Official:** `/messages/{userId}` - Conversation threads
- **Local:** `/messages` - Inbox with read receipts
- **Action:** Clarify if both models exist or merge

### Bookmark Paths
- **Official:** `/bookmarks/{bookId}` (path param)
- **Local:** `/bookmarks?bookId=X` (query) + `/bookmarks/{id}` (delete)
- **Action:** Standardize RESTful design

### Reading Status
- **Official:** UserBookStatus with 6 states
- **Local:** Custom queue and goals system
- **Action:** Document relationship between systems

## Recommended Actions

### Week 1: Critical Alignment
1. Document core official endpoints in local docs
2. Implement client support for `GET /books`
3. Implement sync/progress endpoints
4. Implement user/books status management

### Week 2-3: High Priority Features
5. Backend: Implement OAuth and JWT refresh
6. Backend: Add author/series favorites
7. Backend: Add bookmark deletion
8. Client: Update authentication flow

### Month 2: Medium Priority
9. Backend: Implement reading goals and queue reorder
10. Backend: Add reading timeline statistics
11. Client: Integrate new features

### Ongoing: API Improvements
12. Standardize path structures and pagination
13. Add comprehensive error schemas
14. Improve documentation with examples
15. Add rate limiting and caching headers

## Quick Win: Bookmark Deletion

Already implemented in client (commit d40799b1):
- ✅ Client code ready
- ✅ Local docs updated
- ⏳ Verify backend endpoint exists
- ⏳ Add to official API docs if missing

## Priority Matrix

| Feature | Client Need | Backend Impl | Priority |
|---------|-------------|--------------|----------|
| GET /books | HIGH | ✅ Exists | CRITICAL |
| POST /sync/progress | HIGH | ✅ Exists | CRITICAL |
| GET /user/books | HIGH | ✅ Exists | CRITICAL |
| POST /auth/google | HIGH | ❌ Missing | HIGH |
| DELETE /bookmarks/{id} | MEDIUM | ❓ Verify | HIGH |
| POST /status/queue/reorder | LOW | ❌ Missing | MEDIUM |
| GET /statistics/reading-history | LOW | ❌ Missing | MEDIUM |

## Next Steps

1. **Client Team:** Read API_SYNC_ANALYSIS.md sections 1, 3, 4
2. **Backend Team:** Read API_SYNC_ANALYSIS.md sections 2, 5
3. **Both Teams:** Schedule sync meeting to align roadmap
4. **Product:** Prioritize feature backlog based on analysis

---

**Full details:** [API_SYNC_ANALYSIS.md](./API_SYNC_ANALYSIS.md) (6,000+ words)
