<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $device_id
 * @property int $badge_id
 * @property \Illuminate\Support\Carbon $earned_at
 * @property array $criteria_met
 * @property int|null $progress_value
 * @property bool $is_notified
 * @property int $tier_level
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $total_points
 * @property int $total_badges
 * @property-read \App\Models\Badge $badge
 * @property-read \App\Models\User $user
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read string $earned_at_formatted
 * @property-read string $earned_at_human
 * @mixin \Illuminate\Database\Eloquent\Model
 * @method static Builder<static>|UserBadge forDevice(string $deviceId)
 * @method static Builder<static>|UserBadge forUser(string $userId)
 * @method static Builder<static>|UserBadge forUserOrDevice(string $userId, ?string $deviceId = null)
 * @method static Builder<static>|UserBadge newModelQuery()
 * @method static Builder<static>|UserBadge newQuery()
 * @method static Builder<static>|UserBadge newest()
 * @method static Builder<static>|UserBadge query()
 * @method static Builder<static>|UserBadge recentlyEarned(int $hours = 24)
 * @method static Builder<static>|UserBadge unnotified()
 * @method static Builder<static>|UserBadge whereBadgeId($value)
 * @method static Builder<static>|UserBadge whereCreatedAt($value)
 * @method static Builder<static>|UserBadge whereCriteriaMet($value)
 * @method static Builder<static>|UserBadge whereDeviceId($value)
 * @method static Builder<static>|UserBadge whereEarnedAt($value)
 * @method static Builder<static>|UserBadge whereId($value)
 * @method static Builder<static>|UserBadge whereIsNotified($value)
 * @method static Builder<static>|UserBadge whereProgressValue($value)
 * @method static Builder<static>|UserBadge whereTierLevel($value)
 * @method static Builder<static>|UserBadge whereUpdatedAt($value)
 * @method static Builder<static>|UserBadge whereUserId($value)
 * @mixin \Illuminate\Database\Eloquent\Model
 */
class UserBadge extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'badge_id',
        'earned_at',
        'criteria_met',
        'progress_value',
        'is_notified',
        'tier_level',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
        'criteria_met' => 'array',
        'is_notified' => 'boolean',
    ];

    /**
     * Get the badge that this user badge belongs to
     */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    /**
     * Get the user that owns this badge (if using User model)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Scope to filter by user ID
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by device ID
     */
    public function scopeForDevice(Builder $query, string $deviceId): Builder
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope to filter by user ID or device ID
     */
    public function scopeForUserOrDevice(Builder $query, string $userId, ?string $deviceId = null): Builder
    {
        $query->where('user_id', $userId);

        if ($deviceId) {
            $query->orWhere('device_id', $deviceId);
        }

        return $query;
    }

    /**
     * Scope to get unnotified badges
     */
    public function scopeUnnotified(Builder $query): Builder
    {
        return $query->where('is_notified', false);
    }

    /**
     * Scope to get recently earned badges
     */
    public function scopeRecentlyEarned(Builder $query, int $hours = 24): Builder
    {
        return $query->where('earned_at', '>=', Carbon::now()->subHours($hours));
    }

    /**
     * Scope to order by earned date (newest first)
     */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderByDesc('earned_at');
    }

    /**
     * Mark this badge as notified
     */
    public function markAsNotified(): void
    {
        $this->update(['is_notified' => true]);
    }

    /**
     * Get a formatted description of when this badge was earned
     */
    public function getEarnedAtFormattedAttribute(): string
    {
        return $this->earned_at->format('M j, Y');
    }

    /**
     * Get a human-readable time since earned
     */
    public function getEarnedAtHumanAttribute(): string
    {
        return $this->earned_at->diffForHumans();
    }

    /**
     * Award a badge to a user
     */
    public static function awardBadge(
        Badge $badge,
        string $userId,
        ?string $deviceId = null,
        array $criteriaMet = [],
        ?int $progressValue = null,
        ?Carbon $earnedAt = null
    ): self {
        // Check if badge is repeatable or if user already has it
        if (!$badge->is_repeatable && $badge->hasBeenEarnedByUser($userId, $deviceId)) {
            throw new \InvalidArgumentException('Badge has already been earned and is not repeatable');
        }

        // Calculate tier level for repeatable badges
        $tierLevel = 1;
        if ($badge->is_repeatable) {
            $tierLevel = $badge->getTimesEarnedByUser($userId, $deviceId) + 1;
        }

        return self::create([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'badge_id' => $badge->id,
            'earned_at' => $earnedAt ?? Carbon::now(),
            'criteria_met' => $criteriaMet,
            'progress_value' => $progressValue,
            'is_notified' => false,
            'tier_level' => $tierLevel,
        ]);
    }

    /**
     * Get badges earned by a user with badge details
     */
    public static function getUserBadgesWithDetails(string $userId, ?string $deviceId = null): \Illuminate\Database\Eloquent\Collection
    {
        return self::with(['badge'])
            ->forUserOrDevice($userId, $deviceId)
            ->newest()
            ->get();
    }

    /**
     * Get badge statistics for a user
     */
    public static function getUserBadgeStats(string $userId, ?string $deviceId = null): array
    {
        $badges = self::forUserOrDevice($userId, $deviceId)->with('badge')->get();

        $totalBadges = $badges->count();
        $totalPoints = $badges->sum(function ($userBadge) {
            return $userBadge->badge->points;
        });

        $categoryCounts = $badges->groupBy(function ($userBadge) {
            return $userBadge->badge->category;
        })->map->count();

        $tierCounts = $badges->groupBy(function ($userBadge) {
            return $userBadge->badge->tier;
        })->map->count();

        $recentBadges = $badges->filter(function ($userBadge) {
            return $userBadge->earned_at->isAfter(Carbon::now()->subDays(7));
        })->count();

        return [
            'total_badges' => $totalBadges,
            'total_points' => $totalPoints,
            'categories' => $categoryCounts->toArray(),
            'tiers' => $tierCounts->toArray(),
            'recent_badges' => $recentBadges,
            'latest_badge' => $badges->first()?->badge?->name,
        ];
    }
}
