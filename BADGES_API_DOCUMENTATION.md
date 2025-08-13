# Audiobook Librarian - Badges & Achievements API Documentation

## Overview

The Badges & Achievements system provides a gamification layer for audiobook listening. Users can earn badges based on their listening habits, completion milestones, and various other criteria. The system supports both authenticated users and device-based tracking for anonymous users.

## Base URL
```
https://books.thelin.org/api/v1
```

## Authentication
All badge endpoints require authentication via Bearer token:
```
Authorization: Bearer YOUR_API_TOKEN
```

For device-based tracking (anonymous users), include a device identifier:
```
X-Device-ID: unique-device-identifier
```

---

## Badge System Concepts

### Badge Categories
- **listening** - Basic listening achievements
- **milestone** - Progress milestones (hours listened, books completed)
- **streak** - Consecutive day achievements  
- **variety** - Exploring different content (genres, authors, narrators)
- **social** - Community and sharing achievements
- **completion** - Finishing books and series
- **speed** - Reading/listening speed achievements
- **exploration** - Discovering new content
- **dedication** - Long-term commitment achievements
- **discovery** - Finding hidden gems and new releases
- **seasonal** - Time-based and seasonal achievements
- **collection** - Library building achievements
- **challenge** - Special challenge achievements
- **time_based** - Time-of-day listening patterns
- **quality** - High-quality content achievements
- **community** - Community engagement
- **special** - Special events and promotions
- **habit** - Daily habit formation
- **mastery** - Expert-level achievements

### Badge Tiers
- **bronze** - Entry level (1-2 points)
- **silver** - Intermediate (3-5 points)
- **gold** - Advanced (6-10 points)
- **platinum** - Expert (11-20 points)
- **diamond** - Master (21+ points)

---

## API Endpoints

### 1. Get All Badges with Progress
**`GET /badges`**

Retrieves all available badges with user progress and earned status.

#### Query Parameters
- `category` (optional): Filter by category
- `tier` (optional): Filter by tier (bronze, silver, gold, platinum, diamond)
- `earned_only` (optional): Show only earned badges (boolean)

#### Response
```json
{
    "badges": [
        {
            "id": 1,
            "key": "first_book",
            "name": "Getting Started",
            "description": "Complete your first audiobook",
            "icon": "🎧",
            "category": "milestone",
            "tier": "bronze",
            "points": 5,
            "is_repeatable": false,
            "earned": true,
            "earned_at": "2025-08-10T15:30:00Z",
            "times_earned": 1,
            "tier_level": 1,
            "progress_percentage": 100,
            "can_earn_again": false
        }
    ],
    "total_badges": 50,
    "earned_badges": 12
}
```

---

### 2. Get User's Earned Badges
**`GET /badges/user`**

Retrieves badges the user has already earned.

#### Query Parameters
- `limit` (optional): Limit number of results (1-100)
- `recent_only` (optional): Only show recently earned badges (last 7 days)

#### Response
```json
{
    "badges": [
        {
            "id": 1,
            "key": "first_book",
            "name": "Getting Started",
            "description": "Complete your first audiobook",
            "icon": "🎧",
            "category": "milestone",
            "tier": "bronze",
            "points": 5,
            "earned_at": "2025-08-10T15:30:00Z",
            "earned_at_human": "2 days ago",
            "tier_level": 1,
            "criteria_met": {
                "books_completed": 1
            },
            "is_notified": true
        }
    ],
    "total_earned": 12
}
```

---

### 3. Get User Badge Statistics
**`GET /badges/stats`**

Retrieves comprehensive badge statistics for the user.

#### Response
```json
{
    "user_id": "user123",
    "stats": {
        "total_badges": 12,
        "total_points": 85,
        "categories": {
            "milestone": 4,
            "listening": 3,
            "streak": 2,
            "variety": 3
        },
        "tiers": {
            "bronze": 6,
            "silver": 4,
            "gold": 2
        },
        "recent_badges": 3,
        "latest_badge": "Speed Reader"
    }
}
```

---

### 4. Get Badges by Category
**`GET /badges/categories`**

Groups all badges by category with earned status.

#### Response
```json
{
    "categories": [
        {
            "category": "milestone",
            "category_name": "Milestone",
            "badges": [
                {
                    "id": 1,
                    "key": "first_book",
                    "name": "Getting Started",
                    "description": "Complete your first audiobook",
                    "icon": "🎧",
                    "tier": "bronze",
                    "points": 5,
                    "earned": true,
                    "earned_at": "2025-08-10T15:30:00Z",
                    "times_earned": 1
                }
            ],
            "total_in_category": 8,
            "earned_in_category": 3
        }
    ]
}
```

---

### 5. Get Badge Progress
**`GET /badges/progress`**

Shows progress towards unearned badges and details for earned ones.

#### Query Parameters
- `show_earned` (optional): Include earned badges in results
- `category` (optional): Filter by category
- `min_progress` (optional): Only show badges with minimum progress percentage (0-100)

#### Response
```json
{
    "progress": [
        {
            "badge": {
                "id": 15,
                "key": "book_worm",
                "name": "Book Worm",
                "description": "Complete 25 audiobooks",
                "icon": "📚",
                "category": "milestone",
                "tier": "gold",
                "points": 15
            },
            "earned": false,
            "progress": 68,
            "times_earned": 0,
            "can_earn_again": true
        }
    ],
    "total_badges": 50,
    "filtered_count": 15
}
```

---

### 6. Get Unnotified Badges
**`GET /badges/unnotified`**

Retrieves badges that have been earned but not yet seen by the user.

#### Response
```json
{
    "badges": [
        {
            "id": 8,
            "key": "weekend_warrior",
            "name": "Weekend Warrior",
            "description": "Listen for 5 hours on a weekend",
            "icon": "🎮",
            "category": "habit",
            "tier": "silver",
            "points": 8,
            "earned_at": "2025-08-11T09:15:00Z",
            "tier_level": 1,
            "criteria_met": {
                "weekend_listening": 5
            }
        }
    ],
    "count": 1
}
```

---

### 7. Mark Badges as Notified
**`POST /badges/mark-notified`**

Marks badges as seen/notified to remove them from the unnotified list.

#### Request Body
```json
{
    "badge_ids": [8, 12, 15]
}
```

#### Response
```json
{
    "success": true,
    "message": "Badges marked as notified",
    "marked_count": 3
}
```

---

### 8. Get Badge Leaderboard
**`GET /badges/leaderboard`**

Shows top badge earners (if social features are enabled).

#### Query Parameters
- `timeframe` (optional): week, month, all_time (default: month)
- `limit` (optional): Number of results (1-50, default: 10)

#### Response
```json
{
    "leaderboard": [
        {
            "rank": 1,
            "user_id": "user123",
            "device_id": "device456",
            "total_points": 245,
            "total_badges": 28,
            "display_name": "User 12345678"
        }
    ],
    "timeframe": "month",
    "total_entries": 10
}
```

---

## Implementation Guide

### 1. Basic Badge Display

```kotlin
// Fetch all badges with progress
fun fetchBadges() {
    apiService.getBadges()
        .enqueue(object : Callback<BadgeResponse> {
            override fun onResponse(call: Call<BadgeResponse>, response: Response<BadgeResponse>) {
                if (response.isSuccessful) {
                    updateBadgeGrid(response.body()?.badges ?: emptyList())
                }
            }
            
            override fun onFailure(call: Call<BadgeResponse>, t: Throwable) {
                // Handle error
            }
        })
}
```

### 2. Badge Notifications

```kotlin
// Check for new badges periodically
fun checkForNewBadges() {
    apiService.getUnnotifiedBadges()
        .enqueue(object : Callback<UnnotifiedBadgesResponse> {
            override fun onResponse(call: Call<UnnotifiedBadgesResponse>, response: Response<UnnotifiedBadgesResponse>) {
                if (response.isSuccessful) {
                    val newBadges = response.body()?.badges ?: emptyList()
                    if (newBadges.isNotEmpty()) {
                        showBadgeNotifications(newBadges)
                        markBadgesAsNotified(newBadges.map { it.id })
                    }
                }
            }
            
            override fun onFailure(call: Call<UnnotifiedBadgesResponse>, t: Throwable) {
                // Handle error
            }
        })
}

fun markBadgesAsNotified(badgeIds: List<Int>) {
    val request = MarkNotifiedRequest(badgeIds)
    apiService.markBadgesNotified(request).enqueue(callback)
}
```

### 3. Progress Tracking

```kotlin
// Show progress for unearned badges
fun fetchBadgeProgress() {
    apiService.getBadgeProgress(showEarned = false, minProgress = 1)
        .enqueue(object : Callback<BadgeProgressResponse> {
            override fun onResponse(call: Call<BadgeProgressResponse>, response: Response<BadgeProgressResponse>) {
                if (response.isSuccessful) {
                    updateProgressDisplay(response.body()?.progress ?: emptyList())
                }
            }
            
            override fun onFailure(call: Call<BadgeProgressResponse>, t: Throwable) {
                // Handle error
            }
        })
}
```

### 4. Category-based Display

```kotlin
// Display badges organized by category
fun fetchBadgesByCategory() {
    apiService.getBadgesByCategory()
        .enqueue(object : Callback<BadgeCategoriesResponse> {
            override fun onResponse(call: Call<BadgeCategoriesResponse>, response: Response<BadgeCategoriesResponse>) {
                if (response.isSuccessful) {
                    setupCategoryTabs(response.body()?.categories ?: emptyList())
                }
            }
            
            override fun onFailure(call: Call<BadgeCategoriesResponse>, t: Throwable) {
                // Handle error
            }
        })
}
```

---

## Data Models

### Badge Model
```kotlin
data class Badge(
    val id: Int,
    val key: String,
    val name: String,
    val description: String,
    val icon: String,
    val category: String,
    val tier: String,
    val points: Int,
    val isRepeatable: Boolean,
    val earned: Boolean,
    val earnedAt: String?,
    val timesEarned: Int,
    val tierLevel: Int,
    val progressPercentage: Int,
    val canEarnAgain: Boolean
)
```

### Badge Progress Model
```kotlin
data class BadgeProgress(
    val badge: Badge,
    val earned: Boolean,
    val progress: Int,
    val timesEarned: Int,
    val canEarnAgain: Boolean
)
```

### Badge Statistics Model
```kotlin
data class BadgeStats(
    val totalBadges: Int,
    val totalPoints: Int,
    val categories: Map<String, Int>,
    val tiers: Map<String, Int>,
    val recentBadges: Int,
    val latestBadge: String?
)
```

---

## UI Implementation Tips

### 1. Badge Grid Display
- Use a grid layout to display badges
- Show progress bars for unearned badges
- Use different visual states for earned/unearned badges
- Implement category filtering tabs

### 2. Badge Notifications
- Show toast notifications for newly earned badges
- Use badge icons and tier colors for visual appeal
- Implement a celebration animation for badge earning
- Queue multiple notifications if several badges are earned

### 3. Progress Indicators
- Use circular progress indicators for badge progress
- Show percentage and/or fraction (e.g., "12/25 books")
- Color-code progress bars based on completion percentage
- Animate progress changes

### 4. Badge Details
- Show detailed badge information in a modal/dialog
- Display criteria and requirements clearly
- Show earning history for repeatable badges
- Include tips for earning unearned badges

### 5. Statistics Dashboard
- Create a summary view showing total points, badges by tier/category
- Use charts to visualize badge distribution
- Show recent achievements
- Include leaderboard comparison (if social features enabled)

---

## Error Handling

### Common HTTP Status Codes
- **200**: Success
- **401**: Unauthorized (invalid/expired token)
- **403**: Forbidden (insufficient permissions)
- **404**: Not found
- **422**: Validation error (invalid parameters)
- **500**: Server error

### Error Response Format
```json
{
    "error": true,
    "message": "Error description",
    "code": 422,
    "details": {
        "field": ["validation error message"]
    }
}
```

---

## Performance Considerations

1. **Caching**: Cache badge data locally and refresh periodically
2. **Lazy Loading**: Load badge images and icons on demand
3. **Pagination**: Use limit parameters for large badge collections
4. **Background Updates**: Check for new badges in background, not on every app launch
5. **Offline Support**: Store earned badges locally for offline viewing

---

## Testing Scenarios

1. **First Time User**: Test badge display with no earned badges
2. **Progress Display**: Verify progress bars show correct percentages
3. **New Badge Earning**: Test notification flow for newly earned badges
4. **Category Filtering**: Ensure filtering works correctly
5. **Offline Mode**: Verify cached badge data displays properly
6. **Network Errors**: Test error handling for network failures
7. **Anonymous Users**: Test device-based tracking without authentication

---

## Badge Criteria Examples

The system supports various criteria types for badge requirements:

```json
{
    "books_completed": 10,
    "total_listening_time": 36000,
    "listening_streak": 7,
    "genres_explored": 5,
    "authors_explored": 3,
    "weekend_listening": 5,
    "long_session": 7200
}
```

This flexible system allows for complex achievement requirements and can be extended with new criteria types as needed.