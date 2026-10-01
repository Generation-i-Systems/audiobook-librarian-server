<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FollowApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'library-user']);
        $this->headers = ['Authorization' => 'Bearer ' . $this->user->createToken('api-token')->plainTextToken];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function followableTypes(): array
    {
        return ['author' => ['author'], 'series' => ['series']];
    }

    #[DataProvider('followableTypes')]
    public function testFollowUsesThePathParametersAndStoresTheFollow(string $type): void
    {
        $id = 42;

        $this->withHeaders($this->headers)->postJson("/api/v1/follow/{$type}/{$id}")
            ->assertCreated()
            ->assertExactJson(['message' => 'Followed successfully.']);

        $this->assertSame(
            [[$this->user->id, $type, $id]],
            DB::table('follows')->get()->map(fn ($f): array => [$f->user_id, $f->followable_type, $f->followable_id])->all()
        );
    }

    public function testFollowingTwiceIsRejectedAndStoresOneRow(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/follow/author/7')->assertCreated();

        $this->withHeaders($this->headers)->postJson('/api/v1/follow/author/7')
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Already following.']);

        $this->assertSame(1, DB::table('follows')->count());
    }

    public function testUnfollowUsesThePathParametersAndRemovesTheFollow(): void
    {
        $this->withHeaders($this->headers)->postJson('/api/v1/follow/series/9')->assertCreated();

        $this->withHeaders($this->headers)->deleteJson('/api/v1/unfollow/series/9')
            ->assertOk()
            ->assertExactJson(['message' => 'Successfully unfollowed!']);

        $this->assertSame(0, DB::table('follows')->count());
    }

    public function testFollowsAreScopedToTheCurrentUser(): void
    {
        $other = User::factory()->create(['role' => 'library-user']);
        $otherHeaders = ['Authorization' => 'Bearer ' . $other->createToken('api-token')->plainTextToken];
        $this->withHeaders($otherHeaders)->postJson('/api/v1/follow/author/5')->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->headers)->postJson('/api/v1/follow/author/5')->assertCreated();
        $this->withHeaders($this->headers)->deleteJson('/api/v1/unfollow/author/5')->assertOk();

        $this->assertSame([$other->id], DB::table('follows')->pluck('user_id')->all());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidTargets(): array
    {
        return [
            'unknown type' => ['book', '1', 'followable_type'],
            'non-numeric id' => ['author', 'abc', 'followable_id'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidPathValuesAreRejectedWith422(string $type, string $id, string $field): void
    {
        $this->withHeaders($this->headers)->postJson("/api/v1/follow/{$type}/{$id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
        $this->withHeaders($this->headers)->deleteJson("/api/v1/unfollow/{$type}/{$id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public function testFollowRequiresAuthentication(): void
    {
        $this->withHeaders(['Authorization' => ''])->postJson('/api/v1/follow/author/1')->assertStatus(401);
    }
}
