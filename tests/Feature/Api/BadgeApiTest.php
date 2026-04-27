<?php

namespace Tests\Feature\Api;

use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BadgeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticateUser(): array
    {
        $user = User::factory()->create([
            'role' => 'library-user',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        return [
            $user,
            ['Authorization' => 'Bearer ' . $token],
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_returns_empty_payload_when_no_badges_exist(): void
    {
        [$user, $headers] = $this->authenticateUser();

        $response = $this->withHeaders($headers)->getJson('/api/v1/badges');

        $response->assertOk()
            ->assertJsonStructure([
                'badges',
                'total_badges',
                'earned_badges',
            ])
            ->assertJson([
                'badges' => [],
                'total_badges' => 0,
                'earned_badges' => 0,
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_includes_earned_flag_and_counts_when_user_has_badge(): void
    {
        [$user, $headers] = $this->authenticateUser();

        $badge = Badge::create([
            'key' => 'starter_' . Str::random(6),
            'name' => 'Starter',
            'description' => 'First steps',
            'icon' => 'starter.png',
            'image_url' => null,
            'category' => 'milestone',
            'tier' => 'bronze',
            'points' => 10,
            'criteria' => ['session_count' => 1],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 1,
        ]);

        UserBadge::create([
            'user_id' => (string) $user->id,
            'device_id' => null,
            'badge_id' => $badge->id,
            'earned_at' => now(),
            'tier_level' => 1,
            'criteria_met' => ['session_count' => 1],
            'is_notified' => false,
        ]);

        $response = $this->withHeaders($headers)->getJson('/api/v1/badges');

        $response->assertOk()
            ->assertJsonPath('total_badges', 1)
            ->assertJsonPath('earned_badges', 1)
            ->assertJsonPath('badges.0.earned', true)
            ->assertJsonPath('badges.0.id', $badge->id)
            ->assertJsonPath('badges.0.key', $badge->key)
            ->assertJsonPath('badges.0.name', $badge->name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function user_badges_endpoint_returns_only_earned_badges(): void
    {
        [$user, $headers] = $this->authenticateUser();

        $earned = Badge::create([
            'key' => 'earned_' . Str::random(6),
            'name' => 'Earned',
            'description' => 'Earned badge',
            'icon' => 'earned.png',
            'image_url' => null,
            'category' => 'listening',
            'tier' => 'silver',
            'points' => 20,
            'criteria' => ['session_count' => 5],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 2,
        ]);

        $notEarned = Badge::create([
            'key' => 'not_earned_' . Str::random(6),
            'name' => 'Not Earned',
            'description' => 'Not earned badge',
            'icon' => 'not.png',
            'image_url' => null,
            'category' => 'variety',
            'tier' => 'gold',
            'points' => 30,
            'criteria' => ['session_count' => 10],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 3,
        ]);

        UserBadge::create([
            'user_id' => (string) $user->id,
            'device_id' => null,
            'badge_id' => $earned->id,
            'earned_at' => now(),
            'tier_level' => 1,
            'criteria_met' => ['session_count' => 5],
            'is_notified' => false,
        ]);

        $response = $this->withHeaders($headers)->getJson('/api/v1/badges/user');

        $response->assertOk()
            ->assertJsonStructure([
                'badges',
                'total_earned',
            ])
            ->assertJsonPath('total_earned', 1)
            ->assertJsonCount(1, 'badges')
            ->assertJsonPath('badges.0.id', $earned->id)
            ->assertJsonMissingPath('badges.1');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function mark_notified_validates_and_marks_badges(): void
    {
        [$user, $headers] = $this->authenticateUser();

        $badge = Badge::create([
            'key' => 'notify_' . Str::random(6),
            'name' => 'Notify',
            'description' => 'To notify',
            'icon' => 'notify.png',
            'image_url' => null,
            'category' => 'habit',
            'tier' => 'bronze',
            'points' => 5,
            'criteria' => ['session_count' => 1],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 4,
        ]);

        $userBadge = UserBadge::create([
            'user_id' => (string) $user->id,
            'device_id' => null,
            'badge_id' => $badge->id,
            'earned_at' => now(),
            'tier_level' => 1,
            'criteria_met' => ['session_count' => 1],
            'is_notified' => false,
        ]);

        // Validation error when missing badge_ids
        $this->withHeaders($headers)
            ->postJson('/api/v1/badges/mark-notified', [])
            ->assertStatus(422);

        // Valid request
        $this->withHeaders($headers)
            ->postJson('/api/v1/badges/mark-notified', [
                'badge_ids' => [$badge->id],
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'marked_count' => 1,
            ]);

        $this->assertDatabaseHas('user_badges', [
            'id' => $userBadge->id,
            'is_notified' => 1,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unnotified_badges_returns_badge_fields_required_by_client(): void
    {
        [$user, $headers] = $this->authenticateUser();

        $badge = Badge::create([
            'key' => 'unnotified_' . Str::random(6),
            'name' => 'Unnotified',
            'description' => 'Unnotified badge',
            'icon' => 'unnotified.png',
            'image_url' => null,
            'category' => 'listening',
            'tier' => 'bronze',
            'points' => 10,
            'criteria' => ['session_count' => 1],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 1,
        ]);

        UserBadge::create([
            'user_id' => (string) $user->id,
            'device_id' => null,
            'badge_id' => $badge->id,
            'earned_at' => now(),
            'tier_level' => 1,
            'criteria_met' => [
                'session_count' => 1,
                'first_listening_date' => null,
                'last_listening_date' => null,
            ],
            'is_notified' => false,
        ]);

        $response = $this->withHeaders($headers)->getJson('/api/v1/badges/unnotified');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('badges.0.id', $badge->id)
            ->assertJsonPath('badges.0.key', $badge->key)
            ->assertJsonPath('badges.0.name', $badge->name)
            ->assertJsonPath('badges.0.description', $badge->description)
            ->assertJsonPath('badges.0.category', $badge->category)
            ->assertJsonPath('badges.0.tier', $badge->tier)
            ->assertJsonPath('badges.0.points', $badge->points)
            ->assertJsonPath('badges.0.is_repeatable', $badge->is_repeatable);
    }
}
