<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManageSeriesControllerTest extends TestCase
{
    use RefreshDatabase;

    private DocumentstoreUser $admin;
    private $documentStoreService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->actingAs($this->admin);

        $this->documentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->documentStoreService->shouldReceive('isAdmin')
            ->with($this->admin->getAuthIdentifier())
            ->andReturn(true);
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
    public function indexUsesLightweightGroupedQueryInsteadOfLoadingAllBooks(): void
    {
        // Regression guard for the 502: this route must never call getAllBooks(),
        // which loads the entire library into memory.
        $this->documentStoreService->shouldNotReceive('getAllBooks');

        $this->documentStoreService->shouldReceive('getBooksGroupedBySeries')
            ->once()
            ->with(null, 1, 25)
            ->andReturn([
                'data' => [
                    'Alpha Series' => [
                        ['_id' => '1', 'title' => 'Book A', 'author' => ['Author A'], 'directoryPath' => 'Alpha/Book A', 'audioFileCount' => 5],
                    ],
                ],
                'total' => 1,
            ]);

        $this->documentStoreService->shouldReceive('listSeries')
            ->once()
            ->andReturn([
                ['id' => 10, 'name' => 'Alpha Series'],
            ]);

        $response = $this->get(route('admin.series.manage'));

        $response->assertOk();
        $response->assertViewHas('seriesGroups', ['Alpha Series' => [
            ['_id' => '1', 'title' => 'Book A', 'author' => ['Author A'], 'directoryPath' => 'Alpha/Book A', 'audioFileCount' => 5],
        ]]);
        $response->assertViewHas('seriesIds', ['Alpha Series' => 10]);
    }

    #[Test]
    public function indexPassesSearchTermThroughToTheService(): void
    {
        $this->documentStoreService->shouldReceive('getBooksGroupedBySeries')
            ->once()
            ->with('dune', 1, 25)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreService->shouldReceive('listSeries')->once()->andReturn([]);

        $response = $this->get(route('admin.series.manage', ['search' => 'dune']));

        $response->assertOk();
        $response->assertViewHas('search', 'dune');
    }

    #[Test]
    public function indexPassesPageParameterThroughToTheServiceAndBuildsAPaginator(): void
    {
        $this->documentStoreService->shouldReceive('getBooksGroupedBySeries')
            ->once()
            ->with(null, 3, 25)
            ->andReturn(['data' => ['Series C' => []], 'total' => 100]);

        $this->documentStoreService->shouldReceive('listSeries')->once()->andReturn([]);

        $response = $this->get(route('admin.series.manage', ['page' => 3]));

        $response->assertOk();
        $paginator = $response->viewData('paginator');
        $this->assertSame(3, $paginator->currentPage());
        $this->assertSame(100, $paginator->total());
    }

    #[Test]
    public function indexDetectsPotentialMergesForBooksSharingAParentDirectory(): void
    {
        $this->documentStoreService->shouldReceive('getBooksGroupedBySeries')
            ->once()
            ->andReturn([
                'data' => [
                    'Split Series' => [
                        ['_id' => '1', 'title' => 'Part 1', 'author' => [], 'directoryPath' => 'Series/Split Series/Part 1', 'audioFileCount' => 2],
                        ['_id' => '2', 'title' => 'Part 2', 'author' => [], 'directoryPath' => 'Series/Split Series/Part 2', 'audioFileCount' => 2],
                    ],
                ],
                'total' => 1,
            ]);

        $this->documentStoreService->shouldReceive('listSeries')->once()->andReturn([]);

        $response = $this->get(route('admin.series.manage'));

        $response->assertOk();
        $potentialMerges = $response->viewData('potentialMerges');

        $this->assertCount(1, $potentialMerges);
        $this->assertSame('Split Series', $potentialMerges[0]['series']);
        $this->assertSame('Series/Split Series', $potentialMerges[0]['parentPath']);
        $this->assertCount(2, $potentialMerges[0]['books']);
    }

    #[Test]
    public function renameQueriesOnlyTheAffectedSeriesInsteadOfLoadingAllBooks(): void
    {
        // Real rows so the controller's targeted DB query (books/book_series/series)
        // has something to find; the document store itself stays mocked.
        $oldSeries = Series::factory()->create(['name' => 'Old Name']);
        $book = Book::factory()->create(['title' => 'Renamed Book']);
        $book->series()->attach($oldSeries->id, ['series_number' => 3]);

        // Regression guard for the 502: rename must never call getAllBooks() either.
        $this->documentStoreService->shouldNotReceive('getAllBooks');

        $bookId = (string) $book->id;
        $bookData = ['_id' => $bookId, 'series' => ['Old Name' => 3], 'seriesName' => 'Old Name'];

        $this->documentStoreService->shouldReceive('getBook')
            ->with($bookId)
            ->once()
            ->andReturn($bookData);

        $this->documentStoreService->shouldReceive('updateBook')
            ->once()
            ->with($bookId, Mockery::on(function ($updated) {
                return $updated['series'] === ['New Name' => 3] && $updated['seriesName'] === 'New Name';
            }))
            ->andReturn(true);

        $response = $this->from(route('admin.series.manage'))->post(route('admin.series.rename'), [
            'old_name' => 'Old Name',
            'new_name' => 'New Name',
        ]);

        $response->assertRedirect(route('admin.series.manage'));
        $response->assertSessionHas('success');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
