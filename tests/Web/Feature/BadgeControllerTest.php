<?php

declare(strict_types=1);

namespace Tests\Web\Feature;

use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(\App\Http\Controllers\BadgeController::class)]
class BadgeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    protected function validCriteriaPayload(): string
    {
        return json_encode([
            'version' => 2,
            'logic' => 'AND',
            'conditions' => [
                ['stat' => 'books_completed', 'operator' => '>=', 'value' => 5],
            ],
        ]);
    }

    #[Test]
    public function indexRendersSuccessfully(): void
    {
        Badge::create([
            'key' => 'index_view_badge',
            'name' => 'Index View Badge',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'bronze',
            'points' => 5,
            'criteria' => ['books_completed' => 1],
        ]);

        $this->get(route('badges.index'))->assertOk()->assertSee('Index View Badge');
    }

    #[Test]
    public function createRendersSuccessfully(): void
    {
        $this->get(route('badges.create'))->assertOk()->assertSee('Create New Badge');
    }

    #[Test]
    public function editRendersSuccessfully(): void
    {
        $badge = Badge::create([
            'key' => 'edit_view_badge',
            'name' => 'Edit View Badge',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'bronze',
            'points' => 5,
            'criteria' => ['books_completed' => 1],
        ]);

        $this->get(route('badges.edit', $badge))->assertOk()->assertSee('Edit Badge: Edit View Badge');
    }

    #[Test]
    public function storeCreatesBadgeWithMultiConditionCriteria(): void
    {
        $response = $this->post(route('badges.store'), [
            'key' => 'test_badge',
            'name' => 'Test Badge',
            'description' => 'A badge for testing',
            'category' => 'milestone',
            'tier' => 'gold',
            'points' => 25,
            'criteria' => $this->validCriteriaPayload(),
        ]);

        $response->assertRedirect(route('badges.index'));

        $badge = Badge::where('key', 'test_badge')->first();
        $this->assertNotNull($badge);
        $this->assertSame('milestone', $badge->category);
        $this->assertSame(2, $badge->criteria['version']);
        $this->assertTrue($badge->evaluateCriteria(['books_completed' => 5]));
    }

    #[Test]
    public function storeRejectsUnknownStatKey(): void
    {
        $badCriteria = json_encode([
            'version' => 2,
            'logic' => 'AND',
            'conditions' => [
                ['stat' => 'not_a_real_stat', 'operator' => '>=', 'value' => 5],
            ],
        ]);

        $response = $this->post(route('badges.store'), [
            'key' => 'bad_badge',
            'name' => 'Bad Badge',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'gold',
            'points' => 25,
            'criteria' => $badCriteria,
        ]);

        $response->assertSessionHasErrors('criteria');
        $this->assertNull(Badge::where('key', 'bad_badge')->first());
    }

    #[Test]
    public function storeUploadsImageAndSetsImageUrl(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $file = UploadedFile::fake()->image('badge.png', 64, 64)->size(10);

        $response = $this->post(route('badges.store'), [
            'key' => 'image_badge',
            'name' => 'Image Badge',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'gold',
            'points' => 10,
            'criteria' => $this->validCriteriaPayload(),
            'image_file' => $file,
        ]);

        $response->assertRedirect(route('badges.index'));

        $badge = Badge::where('key', 'image_badge')->first();
        $this->assertNotNull($badge->image_url);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists(
            'badges/' . basename(parse_url($badge->image_url, PHP_URL_PATH))
        );
    }

    #[Test]
    public function destroyDeactivatesRatherThanDeletingBadge(): void
    {
        $badge = Badge::create([
            'key' => 'to_deactivate',
            'name' => 'To Deactivate',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'bronze',
            'points' => 5,
            'criteria' => ['books_completed' => 1],
            'is_active' => true,
        ]);

        $response = $this->delete(route('badges.destroy', $badge));

        $response->assertRedirect(route('badges.index'));
        $this->assertDatabaseHas('badges', ['id' => $badge->id, 'is_active' => false]);
        $this->assertDatabaseHas('badges', ['id' => $badge->id]);
    }

    #[Test]
    public function forceDestroyIsBlockedWhenBadgeHasBeenEarned(): void
    {
        $badge = Badge::create([
            'key' => 'earned_badge',
            'name' => 'Earned Badge',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'bronze',
            'points' => 5,
            'criteria' => ['books_completed' => 1],
            'is_active' => false,
        ]);

        UserBadge::create([
            'user_id' => 'some-user',
            'badge_id' => $badge->id,
            'earned_at' => now(),
            'criteria_met' => ['books_completed' => 1],
            'tier_level' => 1,
        ]);

        $response = $this->delete(route('badges.forceDestroy', $badge));

        $response->assertRedirect(route('badges.index'));
        $this->assertDatabaseHas('badges', ['id' => $badge->id]);
    }

    #[Test]
    public function forceDestroyDeletesBadgeNeverEarned(): void
    {
        $badge = Badge::create([
            'key' => 'never_earned',
            'name' => 'Never Earned',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'bronze',
            'points' => 5,
            'criteria' => ['books_completed' => 1],
            'is_active' => false,
        ]);

        $response = $this->delete(route('badges.forceDestroy', $badge));

        $response->assertRedirect(route('badges.index'));
        $this->assertDatabaseMissing('badges', ['id' => $badge->id]);
    }

    #[Test]
    public function activateReactivatesADeactivatedBadge(): void
    {
        $badge = Badge::create([
            'key' => 'reactivate_me',
            'name' => 'Reactivate Me',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'bronze',
            'points' => 5,
            'criteria' => ['books_completed' => 1],
            'is_active' => false,
        ]);

        $response = $this->post(route('badges.activate', $badge));

        $response->assertRedirect(route('badges.index'));
        $this->assertDatabaseHas('badges', ['id' => $badge->id, 'is_active' => true]);
    }

    #[Test]
    public function anyAuthenticatedUserCanViewBadgesIndex(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $this->get(route('badges.index'))->assertOk();
    }

    #[Test]
    public function unverifiedUserCannotCreateABadge(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $response = $this->post(route('badges.store'), [
            'key' => 'blocked_badge',
            'name' => 'Blocked Badge',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'gold',
            'points' => 25,
            'criteria' => $this->validCriteriaPayload(),
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function userWithManageBadgesPermissionCanCreateABadge(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $user->permissions()->attach(
            \App\Models\Permission::where('key', \App\Enums\PermissionKey::MANAGE_BADGES->value)->firstOrFail()
        );

        $this->actingAs($user);
        $response = $this->post(route('badges.store'), [
            'key' => 'allowed_badge',
            'name' => 'Allowed Badge',
            'description' => 'desc',
            'category' => 'milestone',
            'tier' => 'gold',
            'points' => 25,
            'criteria' => $this->validCriteriaPayload(),
        ]);

        $response->assertRedirect(route('badges.index'));
        $this->assertDatabaseHas('badges', ['key' => 'allowed_badge']);
    }
}
