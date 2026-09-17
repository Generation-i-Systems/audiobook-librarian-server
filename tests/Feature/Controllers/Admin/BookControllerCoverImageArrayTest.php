<?php

namespace Tests\Feature\Controllers\Admin;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use Mockery;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookControllerCoverImageArrayTest extends TestCase
{
    private MockInterface|LegacyMockInterface|DocumentStoreServiceInterface $documentStoreServiceMock;
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentStoreServiceMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreServiceMock);

        $this->admin = $this->createAdminUser();
        $this->actingAs($this->admin);

        $this->documentStoreServiceMock->shouldReceive('isAdmin')
            ->with($this->admin->getAuthIdentifier())
            ->andReturn(true);
        // The merged books.index page also loads filter options and recent books
        // regardless of permission level; stub them so the mock doesn't choke.
        $this->documentStoreServiceMock->shouldReceive('getUniqueValues')->andReturn([]);
        $this->documentStoreServiceMock->shouldReceive('getRecentBooks')->andReturn([]);
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
    public function indexHandlesCoverImageAsString(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Test Book 1',
                'coverImage' => 'path/to/cover.jpg',
            ],
        ];

        $this->documentStoreServiceMock->shouldReceive('listBooks')->andReturn([
            'data' => $books,
            'total' => count($books),
        ]);

        $response = $this->get(route('books.index'));

        $response->assertStatus(200)
            ->assertViewIs('books.index')
            ->assertSee('Test Book 1');
    }

    #[Test]
    public function indexHandlesCoverImageAsArrayWithPath(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Test Book 1',
                'coverImage' => ['path' => 'path/to/cover.jpg', 'size' => '83mb'],
            ],
        ];

        $this->documentStoreServiceMock->shouldReceive('listBooks')->andReturn([
            'data' => $books,
            'total' => count($books),
        ]);

        $response = $this->get(route('books.index'));

        $response->assertStatus(200)
            ->assertViewIs('books.index')
            ->assertSee('Test Book 1');
    }

    #[Test]
    public function indexHandlesCoverImageAsArrayWithoutPath(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Test Book 1',
                'coverImage' => ['path/to/cover.jpg'],
            ],
        ];

        $this->documentStoreServiceMock->shouldReceive('listBooks')->andReturn([
            'data' => $books,
            'total' => count($books),
        ]);

        $response = $this->get(route('books.index'));

        $response->assertStatus(200)
            ->assertViewIs('books.index')
            ->assertSee('Test Book 1');
    }

    #[Test]
    public function indexHandlesCoverImageAsNull(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Test Book 1',
                'coverImage' => null,
            ],
        ];

        $this->documentStoreServiceMock->shouldReceive('listBooks')->andReturn([
            'data' => $books,
            'total' => count($books),
        ]);

        $response = $this->get(route('books.index'));

        $response->assertStatus(200)
            ->assertViewIs('books.index')
            ->assertSee('Test Book 1');
    }

    #[Test]
    public function indexHandlesCoverImageAsEmptyArray(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Test Book 1',
                'coverImage' => [],
            ],
        ];

        $this->documentStoreServiceMock->shouldReceive('listBooks')->andReturn([
            'data' => $books,
            'total' => count($books),
        ]);

        $response = $this->get(route('books.index'));

        $response->assertStatus(200)
            ->assertViewIs('books.index')
            ->assertSee('Test Book 1');
    }
}
