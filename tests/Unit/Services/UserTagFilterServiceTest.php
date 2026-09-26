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

    public function testParseTagSpecsFlattensNestedArrayFromRepeatedTagsInput(): void
    {
        // Mirrors array_filter([$request->input('tag'), $request->input('tags')])
        // when the client sends repeated ?tags[]= parameters.
        $rawTags = [null, ['cozy', '-spoilers']];

        $parsed = UserTagFilterService::parseTagSpecs($rawTags);

        $this->assertSame(['cozy'], $parsed['required']);
        $this->assertSame(['spoilers'], $parsed['banned']);
    }

    public function testParseTagSpecsFlattensCommaSeparatedStringsInsideNestedArray(): void
    {
        $rawTags = ['fantasy,-sci-fi', ['litrpg', '-romance']];

        $parsed = UserTagFilterService::parseTagSpecs($rawTags);

        $this->assertSame(['fantasy', 'litrpg'], $parsed['required']);
        $this->assertSame(['sci-fi', 'romance'], $parsed['banned']);
    }

    public function testSetFilterCreatesAPersonalRow(): void
    {
        $user = User::factory()->create();

        $filter = $this->service->setFilter($user, $user, 'cozy', UserTagFilter::MODE_REQUIRE, UserTagFilter::SCOPE_USER);

        $this->assertSame('cozy', $filter->tag);
        $this->assertSame(UserTagFilter::MODE_REQUIRE, $filter->mode);
        $this->assertSame(UserTagFilter::SCOPE_USER, $filter->scope);
        $this->assertSame('user:' . $user->id, $filter->owner_key);
    }

    public function testOrdinaryUserCannotSetASystemFilterForThemselves(): void
    {
        $user = User::factory()->create();

        $this->expectException(HttpException::class);
        $this->service->setFilter($user, $user, 'mature', UserTagFilter::MODE_BAN, UserTagFilter::SCOPE_SYSTEM);
    }

    public function testAdminCanSetASystemFilterOnATarget(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();

        $filter = $this->service->setFilter($admin, $target, 'mature', UserTagFilter::MODE_BAN, UserTagFilter::SCOPE_SYSTEM);

        $this->assertSame(UserTagFilter::SCOPE_SYSTEM, $filter->scope);
        $this->assertSame('account:' . $target->id, $filter->owner_key);
    }

    public function testAccountParentCanSetASystemFilterForAChild(): void
    {
        $parent = User::factory()->create();
        $child = User::factory()->create(['parent_user_id' => $parent->id]);

        $filter = $this->service->setFilter($parent, $child, 'mature', UserTagFilter::MODE_BAN, UserTagFilter::SCOPE_SYSTEM);

        $this->assertSame('account:' . $parent->id, $filter->owner_key);
    }

    public function testDesignatedFilterManagerCanSetASystemFilterForTheAccount(): void
    {
        $parent = User::factory()->create();
        $manager = User::factory()->create(['parent_user_id' => $parent->id, 'is_filter_manager' => true]);

        $filter = $this->service->setFilter($manager, $parent, 'mature', UserTagFilter::MODE_BAN, UserTagFilter::SCOPE_SYSTEM);

        $this->assertSame('account:' . $parent->id, $filter->owner_key);
    }

    public function testOrdinaryChildCannotSetASystemFilterForTheAccount(): void
    {
        $parent = User::factory()->create();
        $child = User::factory()->create(['parent_user_id' => $parent->id, 'is_filter_manager' => false]);

        $this->expectException(HttpException::class);
        $this->service->setFilter($child, $parent, 'mature', UserTagFilter::MODE_BAN, UserTagFilter::SCOPE_SYSTEM);
    }

    public function testUserCannotRemoveASystemFilter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $filter = $this->service->setFilter($admin, $user, 'mature', UserTagFilter::MODE_BAN, UserTagFilter::SCOPE_SYSTEM);

        $this->expectException(HttpException::class);
        $this->service->removeFilter($user, $user, $filter->id, UserTagFilter::SCOPE_USER);
    }

    public function testUserCanRemoveTheirOwnFilter(): void
    {
        $user = User::factory()->create();
        $filter = $this->service->setFilter($user, $user, 'cozy', UserTagFilter::MODE_REQUIRE, UserTagFilter::SCOPE_USER);

        $this->service->removeFilter($user, $user, $filter->id, UserTagFilter::SCOPE_USER);

        $this->assertDatabaseMissing('user_tag_filters', ['id' => $filter->id]);
    }

    public function testApplyToBookQueryRequiresASystemScopeTag(): void
    {
        $user = User::factory()->create();
        $this->service->setFilter($user, $user, 'cozy', UserTagFilter::MODE_REQUIRE, UserTagFilter::SCOPE_USER);

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
        $this->service->setFilter($user, $user, 'spoilers', UserTagFilter::MODE_BAN, UserTagFilter::SCOPE_USER);

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

    public function testApplyToBookQueryAppliesTheAccountsSystemFilterToAChild(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $parent = User::factory()->create();
        $child = User::factory()->create(['parent_user_id' => $parent->id]);
        $this->service->setFilter($admin, $parent, 'spoilers', UserTagFilter::MODE_BAN, UserTagFilter::SCOPE_SYSTEM);

        $banned = Book::factory()->create();
        BookTag::create(['book_id' => $banned->id, 'scope' => 'system', 'owner_key' => 'system', 'tags' => ['spoilers']]);
        $allowed = Book::factory()->create();

        $query = Book::query();
        $this->service->applyToBookQuery($query, $child->id);

        $this->assertSame([$allowed->id], $query->pluck('id')->all());
    }
}
