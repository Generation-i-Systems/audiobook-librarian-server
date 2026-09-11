<?php

namespace Tests\Feature\Models;

use App\Models\Badge;
use App\Models\UserBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function makeBadge(string $key): Badge
    {
        return Badge::create([
            'key' => $key . '_' . Str::random(6),
            'name' => $key,
            'description' => $key,
            'icon' => 'icon.png',
            'image_url' => null,
            'category' => 'listening',
            'tier' => 'bronze',
            'points' => 10,
            'criteria' => [],
            'is_active' => true,
            'is_repeatable' => true,
            'sort_order' => 1,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function times_earned_is_not_inflated_by_another_badges_device_matched_rows(): void
    {
        $badgeA = $this->makeBadge('badge_a');
        $badgeB = $this->makeBadge('badge_b');

        $deviceId = 'device-' . Str::random(8);

        // Badge A earned once by the authenticated user, on a device that is not the one we
        // will query with below.
        UserBadge::create([
            'user_id' => 'user-1',
            'device_id' => null,
            'badge_id' => $badgeA->id,
            'earned_at' => now(),
            'tier_level' => 1,
        ]);

        // Badge B earned twice by an unrelated device that happens to match the device_id
        // used when querying badge A's stats below.
        UserBadge::create([
            'user_id' => $deviceId,
            'device_id' => $deviceId,
            'badge_id' => $badgeB->id,
            'earned_at' => now(),
            'tier_level' => 1,
        ]);
        UserBadge::create([
            'user_id' => $deviceId,
            'device_id' => $deviceId,
            'badge_id' => $badgeB->id,
            'earned_at' => now(),
            'tier_level' => 2,
        ]);

        // Badge A's own count must not include badge B's device-matched rows.
        $this->assertSame(1, $badgeA->getTimesEarnedByUser('user-1', $deviceId));
        $this->assertTrue($badgeA->hasBeenEarnedByUser('user-1', $deviceId));

        // Badge B's count is unaffected and still correct.
        $this->assertSame(2, $badgeB->getTimesEarnedByUser($deviceId, $deviceId));

        // A badge nobody has earned must not appear earned just because the device has
        // earned some other badge.
        $badgeC = $this->makeBadge('badge_c');
        $this->assertSame(0, $badgeC->getTimesEarnedByUser('user-1', $deviceId));
        $this->assertFalse($badgeC->hasBeenEarnedByUser('user-1', $deviceId));
    }
}
