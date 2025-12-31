<?php

namespace Tests\Web\Feature\Controllers;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NeedsReviewControllerTest extends TestCase
{
    private MockInterface|LegacyMockInterface|DocumentStoreServiceInterface $documentStoreServiceMock;
    protected DocumentstoreUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentStoreServiceMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreServiceMock);

        Event::fake();

        $this->admin = $this->createAdminUser();
        $this->actingAs($this->admin);

        $this->documentStoreServiceMock->shouldReceive('isAdmin')
            ->with($this->admin->getAuthIdentifier())
            ->andReturn(true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    protected function createAdminUser(): DocumentstoreUser
    {
        $userData = [
            'id' => 'test-admin-user',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ];

        return new DocumentstoreUser($userData);
    }

    #[Test]
    public function indexShowsNeedsReviewList(): void
    {
        $reasons = ['missing_author', 'bad_directory_path'];
        $items = [
            [
                'id' => '1',
                'title' => 'Needs Fix 1',
                'directoryPath' => '/a/b/one',
                'needsReviewReasons' => ['missing_author'],
                'createdAt' => now()->toIso8601String(),
            ],
            [
                'id' => '2',
                'title' => 'Needs Fix 2',
                'directoryPath' => '/a/b/two',
                'needsReviewReasons' => ['bad_directory_path', 'missing_author'],
                'createdAt' => now()->toIso8601String(),
            ],
        ];

        $this->documentStoreServiceMock->shouldReceive('listNeedsReviewReasons')->once()->andReturn($reasons);
        $this->documentStoreServiceMock->shouldReceive('countNeedsReviewBooks')
            ->once()
            ->with(null)
            ->andReturn(2);
        $this->documentStoreServiceMock->shouldReceive('listNeedsReviewBooks')
            ->once()
            ->with(null, 20, 1)
            ->andReturn($items);

        $response = $this->get(route('admin.needs_review.index'));

        $response->assertStatus(200)
            ->assertViewIs('admin.needs_review.index')
            ->assertSee('Books Needing Review')
            ->assertSee('Needs Fix 1')
            ->assertSee('Needs Fix 2')
            ->assertSee('/a/b/one')
            ->assertSee('/a/b/two')
            ->assertSee('missing_author')
            ->assertSee('bad_directory_path');
    }

    #[Test]
    public function indexFiltersByReason(): void
    {
        $reasons = ['missing_author', 'bad_directory_path'];
        $filtered = [
            [
                'id' => '3',
                'title' => 'Only Missing Author',
                'directoryPath' => '/x/y/z',
                'needsReviewReasons' => ['missing_author'],
                'createdAt' => now()->toIso8601String(),
            ],
        ];

        $this->documentStoreServiceMock->shouldReceive('listNeedsReviewReasons')->once()->andReturn($reasons);
        $this->documentStoreServiceMock->shouldReceive('countNeedsReviewBooks')
            ->once()
            ->with('missing_author')
            ->andReturn(1);
        $this->documentStoreServiceMock->shouldReceive('listNeedsReviewBooks')
            ->once()
            ->with('missing_author', 20, 1)
            ->andReturn($filtered);

        $response = $this->get(route('admin.needs_review.index', ['reason' => 'missing_author']));

        $response->assertStatus(200)
            ->assertViewIs('admin.needs_review.index')
            ->assertSee('Only Missing Author')
            ->assertSee('missing_author');
    }
}
