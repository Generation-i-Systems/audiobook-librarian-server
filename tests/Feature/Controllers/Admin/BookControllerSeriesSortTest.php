<?php

namespace Tests\Feature\Controllers\Admin;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookControllerSeriesSortTest extends TestCase
{
    use RefreshDatabase;

    private DocumentstoreUser $admin;
    private $documentStoreService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->actingAs($this->admin);

        // Mock the document store service
        $this->documentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->documentStoreService->shouldReceive('isAdmin')
            ->with($this->admin->getAuthIdentifier())
            ->andReturn(true);
        // The merged books.index page also loads filter options and recent books
        // regardless of permission level; stub them so the mock doesn't choke.
        $this->documentStoreService->shouldReceive('getUniqueValues')->andReturn([]);
        $this->documentStoreService->shouldReceive('getRecentBooks')->andReturn([]);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreService);
    }

    private function createAdminUser(): DocumentstoreUser
    {
        return new DocumentstoreUser([
            'id' => 'admin-user',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);
    }

    #[Test]
    public function it_defaults_to_series_sorting_when_series_filter_is_applied(): void
    {
        // Mock the listBooks method to expect series sort (secondary sort by number handled in service)
        $this->documentStoreService->shouldReceive('listBooks')
            ->once()
            ->with(1, 12, ['series' => 'Test Series', 'include_needs_review' => true], true, 'series', 'asc', true)
            ->andReturn([
                'data' => [],
                'total' => 0,
            ]);

        // Make request with series filter but no sort parameter
        $response = $this->get(route('books.index', ['series' => 'Test Series']));

        $response->assertOk();
        $response->assertViewHas('sort', 'series_asc');
    }

    #[Test]
    public function it_respects_explicit_sort_parameter_even_with_series_filter(): void
    {
        // Mock the listBooks method to expect the explicit sort
        $this->documentStoreService->shouldReceive('listBooks')
            ->once()
            ->with(1, 12, ['series' => 'Test Series', 'include_needs_review' => true], true, 'title', 'asc', true)
            ->andReturn([
                'data' => [],
                'total' => 0,
            ]);

        // Make request with series filter and explicit sort parameter
        $response = $this->get(route('books.index', ['series' => 'Test Series', 'sort' => 'title_asc']));

        $response->assertOk();
        $response->assertViewHas('sort', 'title_asc');
    }

    #[Test]
    public function it_uses_default_sort_when_no_series_filter_is_applied(): void
    {
        // Mock the listBooks method to expect the shared default sort (title A-Z),
        // same as every other user gets on the merged books.index page.
        $this->documentStoreService->shouldReceive('listBooks')
            ->once()
            ->with(1, 12, ['include_needs_review' => true], true, 'title', 'asc', true)
            ->andReturn([
                'data' => [],
                'total' => 0,
            ]);

        // Make request with no filters
        $response = $this->get(route('books.index'));

        $response->assertOk();
        $response->assertViewHas('sort', 'title_asc');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
