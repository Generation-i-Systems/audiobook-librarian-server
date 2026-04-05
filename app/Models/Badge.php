<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string|null $icon
 * @property string|null $icon_path
 * @property string|null $image_url
 * @property string $category
 * @property string $tier
 * @property int $points
 * @property array $criteria
 * @property bool $is_active
 * @property bool $is_repeatable
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read string $display_name
 * @property-read int $tier_weight
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserBadge> $userBadges
 * @property-read int|null $user_badges_count
 * @method static Builder<static>|Badge active()
 * @method static Builder<static>|Badge category(string $category)
 * @method static Builder<static>|Badge newModelQuery()
 * @method static Builder<static>|Badge newQuery()
 * @method static Builder<static>|Badge ordered()
 * @method static Builder<static>|Badge query()
 * @method static Builder<static>|Badge tier(string $tier)
 * @method static Builder<static>|Badge whereCategory($value)
 * @method static Builder<static>|Badge whereCreatedAt($value)
 * @method static Builder<static>|Badge whereCriteria($value)
 * @method static Builder<static>|Badge whereDescription($value)
 * @method static Builder<static>|Badge whereIcon($value)
 * @method static Builder<static>|Badge whereId($value)
 * @method static Builder<static>|Badge whereImageUrl($value)
 * @method static Builder<static>|Badge whereIsActive($value)
 * @method static Builder<static>|Badge whereIsRepeatable($value)
 * @method static Builder<static>|Badge whereKey($value)
 * @method static Builder<static>|Badge whereName($value)
 * @method static Builder<static>|Badge wherePoints($value)
 * @method static Builder<static>|Badge whereSortOrder($value)
 * @method static Builder<static>|Badge whereTier($value)
 * @method static Builder<static>|Badge whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Badge extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'icon',
        'image_url',
        'category',
        'tier',
        'points',
        'criteria',
        'is_active',
        'is_repeatable',
        'sort_order',
    ];

    protected $casts = [
        'criteria'      => 'array',
        'is_active'     => 'boolean',
        'is_repeatable' => 'boolean',
    ];

    /**
     * Badge categories
     */
    public const CATEGORIES = [
        'listening'   => 'Listening',
        'milestone'   => 'Milestone',
        'streak'      => 'Streak',
        'variety'     => 'Variety',
        'social'      => 'Social',
        'completion'  => 'Completion',
        'speed'       => 'Speed',
        'exploration' => 'Exploration',
        'dedication'  => 'Dedication',
        'discovery'   => 'Discovery',
        'seasonal'    => 'Seasonal',
        'collection'  => 'Collection',
        'challenge'   => 'Challenge',
        'time_based'  => 'Time-Based',
        'quality'     => 'Quality',
        'community'   => 'Community',
        'special'     => 'Special Events',
        'habit'       => 'Habit Building',
        'mastery'     => 'Mastery',
    ];

    /**
     * Badge tiers
     */
    public const TIERS = [
        'bronze'   => 'Bronze',
        'silver'   => 'Silver',
        'gold'     => 'Gold',
        'platinum' => 'Platinum',
        'diamond'  => 'Diamond',
    ];

    /**
     * Badge criteria types
     */
    public const CRITERIA_TYPES = [
        'total_listening_time'           => 'Total listening time in seconds',
        'books_completed'                => 'Number of books completed',
        'listening_streak'               => 'Consecutive days of listening',
        'genres_explored'                => 'Number of different genres listened to',
        'authors_explored'               => 'Number of different authors listened to',
        'session_count'                  => 'Number of listening sessions',
        'daily_goal_met'                 => 'Daily listening goals met',
        'weekend_listening'              => 'Weekend listening sessions',
        'long_session'                   => 'Single session duration in seconds',
        'books_in_timeframe'             => 'Books completed in specific timeframe',
        'series_completion'              => 'Complete book series finished',
        'narrator_variety'               => 'Different narrators listened to',
        'bookmarks_created'              => 'Number of bookmarks created',
        'books_reviewed'                 => 'Number of books reviewed or rated',
        'library_size'                   => 'Number of books in personal library',
        'reading_speed'                  => 'Average reading speed (words per minute)',
        'completion_rate'                => 'Percentage of started books completed',
        'discovery_rate'                 => 'New books discovered through recommendations',
        'seasonal_listening'             => 'Listening during specific seasons',
        'time_of_day_listening'          => 'Listening during specific times',
        'chapter_completion'             => 'Number of chapters completed',
        'pause_behavior'                 => 'Low pause count indicating focus',
        'device_variety'                 => 'Number of different devices used',
        'offline_listening'              => 'Hours listened while offline',
        'repeat_listening'               => 'Books listened to multiple times',
        'language_variety'               => 'Books in different languages',
        'length_preference'              => 'Books of specific lengths',
        'publication_era'                => 'Books from different publication eras',
        'award_winners'                  => 'Award-winning books completed',
        'bestseller_reading'             => 'Bestseller books completed',
        'indie_discovery'                => 'Independent/self-published books found',
        'first_time_author'              => 'Debut author books completed',
        'community_engagement'           => 'Community interactions and contributions',
        'listening_time_weekly'          => 'Listening time in a single week (seconds)',
        'listening_time_monthly'         => 'Listening time in a single month (seconds)',
        'current_streak'                 => 'Current listening streak (days)',
        'books_completed_this_week'      => 'Books completed in the current week',
        'books_completed_this_month'     => 'Books completed in the current month',
        'series_explored'                => 'Series where at least one book was completed',
        'total_listening_days'           => 'Total number of days with listening activity',
        'action_app_installed'           => 'App installed on a platform',
        'action_app_installed_android'   => 'App installed on Android',
        'action_app_installed_ios'       => 'App installed on iOS',
        'action_app_installed_desktop'   => 'App installed on Desktop',
        'action_book_downloaded'         => 'Books downloaded',
        'action_book_started'            => 'Books started playing',
        'action_skin_changed'            => 'Player skin changed',
        'action_gallery_skin_downloaded' => 'Skins downloaded from gallery',
        'action_theme_changed'           => 'Color theme changed',
        'action_drive_mode_activated'    => 'Drive mode activated',
        'action_bookmark_created'        => 'Bookmarks created via player',
    ];

    /**
     * Get user badges for this badge
     */
    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    /**
     * Check if a user has earned this badge
     */
    public function hasBeenEarnedByUser(string $userId, ?string $deviceId = null): bool
    {
        $query = $this->userBadges()->where('user_id', $userId);

        if ($deviceId) {
            $query->orWhere('device_id', $deviceId);
        }

        return $query->exists();
    }

    /**
     * Get the number of times this badge has been earned by a user (for repeatable badges)
     */
    public function getTimesEarnedByUser(string $userId, ?string $deviceId = null): int
    {
        $query = $this->userBadges()->where('user_id', $userId);

        if ($deviceId) {
            $query->orWhere('device_id', $deviceId);
        }

        return $query->count();
    }

    /**
     * Scope to get active badges only
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by category
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter by tier
     */
    public function scopeTier(Builder $query, string $tier): Builder
    {
        return $query->where('tier', $tier);
    }

    /**
     * Scope to order by sort order and name
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get the tier weight for sorting
     */
    public function getTierWeightAttribute(): int
    {
        $weights = [
            'bronze'   => 1,
            'silver'   => 2,
            'gold'     => 3,
            'platinum' => 4,
            'diamond'  => 5,
        ];

        return $weights[$this->tier] ?? 1;
    }

    /**
     * Get the display name with tier
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name . ' (' . ucfirst($this->tier) . ')';
    }

    /**
     * Check if this badge's criteria are met for given statistics
     */
    public function evaluateCriteria(array $userStats): bool
    {
        $criteria = $this->criteria;

        foreach ($criteria as $type => $requirement) {
            if (! $this->checkSingleCriterion($type, $requirement, $userStats)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a single criterion is met
     */
    protected function checkSingleCriterion(string $type, $requirement, array $userStats): bool
    {
        $value = $userStats[$type] ?? 0;

        // Handle different requirement formats
        if (is_numeric($requirement)) {
            return $value >= $requirement;
        }

        if (is_array($requirement)) {
            // Handle range requirements like ['min' => 5, 'max' => 10]
            if (isset($requirement['min']) && $value < $requirement['min']) {
                return false;
            }
            if (isset($requirement['max']) && $value > $requirement['max']) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Get progress towards this badge for given statistics (0-100)
     */
    public function getProgressPercentage(array $userStats): int
    {
        $criteria = $this->criteria;
        $totalCriteria = count($criteria);

        if ($totalCriteria === 0) {
            return 100;
        }

        $progress = 0.0;

        foreach ($criteria as $type => $requirement) {
            $progress += $this->getSingleCriterionProgress($type, $requirement, $userStats);
        }

        return (int) floor(($progress / $totalCriteria) * 100);
    }

    protected function getSingleCriterionProgress(string $type, $requirement, array $userStats): float
    {
        $value = $userStats[$type] ?? 0;

        if (is_numeric($requirement)) {
            if ((float) $requirement <= 0.0) {
                return 1.0;
            }

            return min(1.0, max(0.0, ((float) $value) / ((float) $requirement)));
        }

        if (is_array($requirement)) {
            $min = isset($requirement['min']) && is_numeric($requirement['min'])
                ? (float) $requirement['min']
                : null;
            $max = isset($requirement['max']) && is_numeric($requirement['max'])
                ? (float) $requirement['max']
                : null;

            if ($min !== null && $max !== null) {
                if ($value < $min) {
                    return $min > 0.0 ? min(1.0, max(0.0, ((float) $value) / $min)) : 0.0;
                }

                if ($value > $max) {
                    return 0.0;
                }

                return 1.0;
            }

            if ($min !== null) {
                return $min > 0.0 ? min(1.0, max(0.0, ((float) $value) / $min)) : 0.0;
            }

            if ($max !== null) {
                return $value <= $max ? 1.0 : 0.0;
            }
        }

        return $this->checkSingleCriterion($type, $requirement, $userStats) ? 1.0 : 0.0;
    }
}
