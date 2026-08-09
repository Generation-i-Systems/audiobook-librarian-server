<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\User;
use App\Models\UserTagFilter;
use App\Services\UserTagFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UserTagFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserTagFilterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserTagFilterService();
    }

    public function testSetFilterCreatesARequireRow(): void
    {
        $user = User::factory()->create();

        $filter = $this->service->setFilter($user, 'cozy', UserTagFilter::MODE_REQUIRE, lockedByAdmin: false, actingAsAdmin: false);

        $this->assertSame('cozy', $filter->tag);
        $this->assertSame(UserTagFilter::MODE_REQUIRE, $filter->mode);
        $this->assertFalse($filter->locked_by_admin);
    }

    public function testUserCannotOverwriteAnAdminLockedFilter(): void
    {
        $user = User::factory()->create();
        $this->service->setFilter($user, 'mature', UserTagFilter::MODE_BAN, lockedByAdmin: true, actingAsAdmin: true);

        $this->expectException(HttpException::class);
        $this->service->setFilter($user, 'mature', UserTagFilter::MODE_REQUIRE, lockedByAdmin: false, actingAsAdmin: false);
    }

    public function testAdminCanOverwriteALockedFilter(): void
    {
        $user = User::factory()->create();
        $this->service->setFilter($user, 'mature', UserTagFilter::MODE_BAN, lockedByAdmin: true, actingAsAdmin: true);

        $filter = $this->service->setFilter($user, 'mature', UserTagFilter::MODE_REQUIRE, lockedByAdmin: true, actingAsAdmin: true);

        $this->assertSame(UserTagFilter::MODE_REQUIRE, $filter->mode);
    }

    public function testUserCannotRemoveALockedFilter(): void
    {
        $user = User::factory()->create();
        $filter = $this->service->setFilter($user, 'mature', UserTagFilter::MODE_BAN, lockedByAdmin: true, actingAsAdmin: true);

        $this->expectException(HttpException::class);
        $this->service->removeFilter($user, $filter->id, actingAsAdmin: false);
    }

    public function testUserCanRemoveTheirOwnFilter(): void
    {
        $user = User::factory()->create();
        $filter = $this->service->setFilter($user, 'cozy', UserTagFilter::MODE_REQUIRE, lockedByAdmin: false, actingAsAdmin: false);

        $this->service->removeFilter($user, $filter->id, actingAsAdmin: false);

        $this->assertDatabaseMissing('user_tag_filters', ['id' => $filter->id]);
    }

    public function testApplyToBookQueryRequiresASystemScopeTag(): void
    {
        $user = User::factory()->create();
        $this->service->setFilter($user, 'cozy', UserTagFilter::MODE_REQUIRE, lockedByAdmin: false, actingAsAdmin: false);

        $matching = Book::factory()->create();
        BookTag::create(['book_id' => $matching->id, 'scope' => 'system', 'owner_key' => 'system', 'tags' => ['cozy']]);

        $nonMatching = Book::factory()->create();

        $query = Book::query();
        $this->service->applyToBookQuery($query, $user->id);

        $results = $query->pluck('id')->all();
        $this->assertSame([$matching->id], $results);
    }

    public function testApplyToBookQueryBansASystemScopeTag(): void
    {
        $user = User::factory()->create();
        $this->service->setFilter($user, 'spoilers', UserTagFilter::MODE_BAN, lockedByAdmin: false, actingAsAdmin: false);

        $banned = Book::factory()->create();
        BookTag::create(['book_id' => $banned->id, 'scope' => 'system', 'owner_key' => 'system', 'tags' => ['spoilers']]);

        $allowed = Book::factory()->create();

        $query = Book::query();
        $this->service->applyToBookQuery($query, $user->id);

        $results = $query->pluck('id')->all();
        $this->assertSame([$allowed->id], $results);
    }

    public function testApplyToBookQueryDoesNothingWhenUserHasNoFilters(): void
    {
        $user = User::factory()->create();
        Book::factory()->count(2)->create();

        $query = Book::query();
        $this->service->applyToBookQuery($query, $user->id);

        $this->assertSame(2, $query->count());
    }
}
