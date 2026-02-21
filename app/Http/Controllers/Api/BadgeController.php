<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\UserBadge;
use App\Services\BadgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BadgeController extends Controller
{
    protected BadgeService $badgeService;

    public function __construct(BadgeService $badgeService)
    {
        $this->badgeService = $badgeService;
    }

    /**
     * Get all available badges with user progress
     */
    public function index(Request $request): JsonResponse
    {
        $userId = auth()->id() ?? $request->header('X-Device-ID', 'unknown');
        $deviceId = $request->header('X-Device-ID');

        $categoryRule = 'nullable|string|in:listening,milestone,streak,variety,social,completion,speed,exploration,';
        $categoryRule .= 'dedication,discovery,seasonal,collection,challenge,time_based,quality,community,special,';
        $categoryRule .= 'habit,mastery';

        $validated = $request->validate([
            'category' => $categoryRule,
            'tier' => 'nullable|string|in:bronze,silver,gold,platinum,diamond',
            'earned_only' => 'nullable|boolean',
        ]);

        $query = Badge::active()->ordered();

        // Filter by category if specified
        if (!empty($validated['category'])) {
            $query->category($validated['category']);
        }

        // Filter by tier if specified
        if (!empty($validated['tier'])) {
            $query->tier($validated['tier']);
        }

        $badges = $query->get();
        $userBadges = UserBadge::forUserOrDevice($userId, $deviceId)
            ->with('badge')
            ->get()
            ->keyBy('badge_id');

        $userStats = null;

        $result = $badges->map(function ($badge) use ($userBadges, $userId, $deviceId, &$userStats, $validated) {
            /** @var \App\Models\Badge $badge */
            $userBadge = $userBadges->get($badge->id);
            $hasEarned = $userBadge !== null;

            // Skip badges that aren't earned if earned_only filter is set
            if (!empty($validated['earned_only']) && !$hasEarned) {
                return null;
            }

            // Lazy load user stats only when needed for progress calculation
            if (!$hasEarned && $userStats === null) {
                $userStats = $this->badgeService->getUserBadgeProgress($userId, $deviceId);
            }

            $progress = 0;
            if (!$hasEarned && $userStats !== null) {
                $progressInfo = collect($userStats)->firstWhere('badge.id', $badge->id);
                $progress = $progressInfo['progress'] ?? 0;
            }

            return [
                'id' => $badge->id,
                'key' => $badge->key,
                'name' => $badge->name,
                'description' => $badge->description,
                'icon' => $badge->icon,
                'image_url' => $badge->image_url,
                'category' => $badge->category,
                'tier' => $badge->tier,
                'points' => $badge->points,
                'is_repeatable' => $badge->is_repeatable,
                'earned' => $hasEarned,
                'earned_at' => $userBadge?->earned_at?->toISOString(),
                'times_earned' => $badge->getTimesEarnedByUser($userId, $deviceId),
                'tier_level' => $userBadge?->tier_level ?? 0,
                'progress_percentage' => $hasEarned ? 100 : $progress,
                'can_earn_again' => $badge->is_repeatable || !$hasEarned,
            ];
        })->filter(); // Remove null values from earned_only filter

        // @phpstan-ignore-next-line
        $badgesArray = $result->values();

        return response()->json([
            'badges' => $badgesArray,
            // @phpstan-ignore-next-line
            'total_badges' => $badges->count(),
            'earned_badges' => $userBadges->count(),
        ]);
    }

    /**
     * Get user's earned badges
     */
    public function userBadges(Request $request): JsonResponse
    {
        $userId = auth()->id() ?? $request->header('X-Device-ID', 'unknown');
        $deviceId = $request->header('X-Device-ID');

        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'recent_only' => 'nullable|boolean',
        ]);

        $query = UserBadge::forUserOrDevice($userId, $deviceId)
            ->with(['badge'])
            ->newest();

        if (!empty($validated['recent_only'])) {
            $query->recentlyEarned(168); // Last 7 days
        }

        if (!empty($validated['limit'])) {
            $query->limit($validated['limit']);
        }

        $userBadges = $query->get();

        $badges = $userBadges->map(function ($userBadge) {
            return [
                'id' => $userBadge->badge->id,
                'key' => $userBadge->badge->key,
                'name' => $userBadge->badge->name,
                'description' => $userBadge->badge->description,
                'icon' => $userBadge->badge->icon,
                'image_url' => $userBadge->badge->image_url,
                'category' => $userBadge->badge->category,
                'tier' => $userBadge->badge->tier,
                'points' => $userBadge->badge->points,
                'earned_at' => $userBadge->earned_at->toISOString(),
                'earned_at_human' => $userBadge->earned_at_human,
                'tier_level' => $userBadge->tier_level,
                'criteria_met' => $userBadge->criteria_met,
                'is_notified' => $userBadge->is_notified,
            ];
        });

        return response()->json([
            'badges' => $badges,
            'total_earned' => $userBadges->count(),
        ]);
    }

    /**
     * Get user badge statistics and summary
     */
    public function userStats(Request $request): JsonResponse
    {
        $userId = auth()->id() ?? $request->header('X-Device-ID', 'unknown');
        $deviceId = $request->header('X-Device-ID');

        $stats = $this->badgeService->getUserBadgeSummary($userId, $deviceId);

        return response()->json([
            'user_id' => $userId,
            'stats' => $stats,
        ]);
    }

    /**
     * Get badges by category
     */
    public function byCategory(Request $request): JsonResponse
    {
        $userId = auth()->id() ?? $request->header('X-Device-ID', 'unknown');
        $deviceId = $request->header('X-Device-ID');

        $badges = Badge::active()->ordered()->get()->groupBy('category');
        $userBadges = UserBadge::forUserOrDevice($userId, $deviceId)
            ->with('badge')
            ->get()
            ->keyBy('badge_id');

        $categorizedBadges = $badges->map(function ($categoryBadges, $category) use ($userBadges) {
            $badgeData = $categoryBadges->map(function ($badge) use ($userBadges) {
                /** @var \App\Models\Badge $badge */
                $userBadge = $userBadges->get($badge->id);
                $userId = $userBadges->first()->user_id ?? 'unknown';

                return [
                    'id' => $badge->id,
                    'key' => $badge->key,
                    'name' => $badge->name,
                    'description' => $badge->description,
                    'icon' => $badge->icon,
                    'image_url' => $badge->image_url,
                    'tier' => $badge->tier,
                    'points' => $badge->points,
                    'earned' => $userBadge !== null,
                    'earned_at' => $userBadge?->earned_at?->toISOString(),
                    'times_earned' => $badge->getTimesEarnedByUser(
                        $userId,
                        $userBadges->first()?->device_id
                    ),
                ];
            });

            return [
                // @phpstan-ignore-next-line
                'category' => $category,
                // @phpstan-ignore-next-line
                'category_name' => Badge::CATEGORIES[$category] ?? $category,
                'badges' => $badgeData->values(),
                'total_in_category' => $categoryBadges->count(),
                'earned_in_category' => $badgeData->where('earned', true)->count(),
            ];
        });

        return response()->json([
            'categories' => $categorizedBadges->values(),
        ]);
    }

    /**
     * Get unnotified badges (newly earned badges user hasn't seen)
     */
    public function unnotified(Request $request): JsonResponse
    {
        $userId = auth()->id() ?? $request->header('X-Device-ID', 'unknown');
        $deviceId = $request->header('X-Device-ID');

        $unnotifiedUserBadges = $this->badgeService->getUnnotifiedBadges($userId, $deviceId);

        $badges = array_map(function (array $userBadge) {
            $badge = $userBadge['badge'] ?? [];

            return [
                'id' => (int) ($badge['id'] ?? 0),
                'key' => (string) ($badge['key'] ?? ''),
                'name' => (string) ($badge['name'] ?? ''),
                'description' => (string) ($badge['description'] ?? ''),
                'icon' => $badge['icon'] ?? null,
                'category' => (string) ($badge['category'] ?? ''),
                'tier' => (string) ($badge['tier'] ?? ''),
                'points' => (int) ($badge['points'] ?? 0),
                'is_repeatable' => (bool) ($badge['is_repeatable'] ?? false),
            ];
        }, $unnotifiedUserBadges);

        return response()->json([
            'badges' => $badges,
            'count' => count($badges),
        ]);
    }

    /**
     * Mark badges as notified (user has seen the notification)
     */
    public function markNotified(Request $request): JsonResponse
    {
        $userId = auth()->id() ?? $request->header('X-Device-ID', 'unknown');
        $deviceId = $request->header('X-Device-ID');

        $validator = Validator::make($request->all(), [
            'badge_ids' => 'required|array|min:1',
            'badge_ids.*' => 'integer|exists:badges,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $this->badgeService->markBadgesAsNotified($validated['badge_ids'], $userId, $deviceId);

        return response()->json([
            'success' => true,
            'message' => 'Badges marked as notified',
            'marked_count' => count($validated['badge_ids']),
        ]);
    }

    /**
     * Get badge progress for user (shows progress towards unearned badges)
     */
    public function progress(Request $request): JsonResponse
    {
        $userId = auth()->id() ?? $request->header('X-Device-ID', 'unknown');
        $deviceId = $request->header('X-Device-ID');

        $categoryRule = 'nullable|string|in:listening,milestone,streak,variety,social,completion,speed,exploration,';
        $categoryRule .= 'dedication,discovery,seasonal,collection,challenge,time_based,quality,community,special,';
        $categoryRule .= 'habit,mastery';

        $validated = $request->validate([
            'show_earned' => 'nullable|boolean',
            'category' => $categoryRule,
            'min_progress' => 'nullable|integer|min:0|max:100',
        ]);

        $badgeProgress = $this->badgeService->getUserBadgeProgress($userId, $deviceId);

        // Filter results based on request parameters
        $filtered = collect($badgeProgress)->filter(function ($item) use ($validated) {
            // Filter by earned status
            if (isset($validated['show_earned']) && !$validated['show_earned'] && $item['earned']) {
                return false;
            }

            // Filter by category
            if (!empty($validated['category']) && $item['badge']->category !== $validated['category']) {
                return false;
            }

            // Filter by minimum progress
            if (isset($validated['min_progress']) && $item['progress'] < $validated['min_progress']) {
                return false;
            }

            return true;
        });

        $result = $filtered->map(function ($item) {
            return [
                'badge' => [
                    'id' => $item['badge']->id,
                    'key' => $item['badge']->key,
                    'name' => $item['badge']->name,
                    'description' => $item['badge']->description,
                    'icon' => $item['badge']->icon,
                    'category' => $item['badge']->category,
                    'tier' => $item['badge']->tier,
                    'points' => $item['badge']->points,
                ],
                'earned' => $item['earned'],
                'progress' => $item['progress'],
                'times_earned' => $item['times_earned'],
                'can_earn_again' => $item['can_earn_again'],
            ];
        });

        return response()->json([
            'progress' => $result->values(),
            'total_badges' => count($badgeProgress),
            'filtered_count' => $result->count(),
        ]);
    }

    /**
     * Get leaderboard showing top badge earners (if social features are enabled)
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'timeframe' => 'nullable|string|in:week,month,all_time',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $timeframe = $validated['timeframe'] ?? 'month';
        $limit = $validated['limit'] ?? 10;

        $query = UserBadge::with(['badge'])
            ->selectRaw('user_id, device_id, SUM(badges.points) as total_points, COUNT(*) as total_badges')
            ->join('badges', 'user_badges.badge_id', '=', 'badges.id');

        // Apply timeframe filter
        switch ($timeframe) {
            case 'week':
                $query->where('earned_at', '>=', now()->startOfWeek());
                break;
            case 'month':
                $query->where('earned_at', '>=', now()->startOfMonth());
                break;
            case 'all_time':
            default:
                // No date filter for all time
                break;
        }

        $leaderboard = $query->groupBy('user_id', 'device_id')
            ->orderByDesc('total_points')
            ->orderByDesc('total_badges')
            ->limit($limit)
            ->get();

        $rankings = $leaderboard->map(function ($entry, $index) {
            return [
                'rank' => $index + 1,
                'user_id' => $entry->user_id,
                'device_id' => $entry->device_id,
                // @phpstan-ignore-next-line
                'total_points' => (int) $entry->total_points,
                // @phpstan-ignore-next-line
                'total_badges' => (int) $entry->total_badges,
                // Note: In a real app, you'd probably want to include user names/avatars here
                'display_name' => 'User ' . substr((string) $entry->user_id, 0, 8),
            ];
        });

        return response()->json([
            'leaderboard' => $rankings,
            'timeframe' => $timeframe,
            'total_entries' => $rankings->count(),
        ]);
    }
}
