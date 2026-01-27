<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Services\AudiobookBayService;
use App\Services\LibraryRepairService;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LibraryRepairControllerTest extends TestCase
{
    /** @var MockInterface */
    private MockInterface $documentStoreService;

    private DocumentstoreUser $admin;

    /** @var MockInterface */
    private MockInterface $libraryRepairService;

    /** @var MockInterface */
    private MockInterface $audiobookBayService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreService);

        $this->libraryRepairService = Mockery::mock(LibraryRepairService::class);
        $this->app->instance(LibraryRepairService::class, $this->libraryRepairService);

        $this->audiobookBayService = Mockery::mock(AudiobookBayService::class);
        $this->app->instance(AudiobookBayService::class, $this->audiobookBayService);

        Event::fake();

        $this->admin = $this->createAdminUser();
        $this->actingAs($this->admin);

        $this->documentStoreService->shouldReceive('isAdmin')
            ->with($this->admin->getAuthIdentifier())
            ->andReturn(true)
        ;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function indexShowsPaginatedIssues(): void
    {
        $issues = [
            [
                'id' => 1,
                'issueType' => 'missing_directory',
                'status' => 'pending',
                'directoryPath' => 'books/example-one',
                'metadata' => [],
                'autoResolved' => false,
                'createdAt' => now()->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
                'resolvedAt' => null,
                'resolutionNotes' => null,
                'book' => [
                    'id' => 42,
                    'title' => 'Example One',
                    'authors' => ['Author A'],
                    'directoryPath' => 'books/example-one',
                ],
            ],
            [
                'id' => 2,
                'issueType' => 'duplicate_directory',
                'status' => 'resolved',
                'directoryPath' => 'books/example-two',
                'metadata' => ['book_ids' => [1, 2]],
                'autoResolved' => true,
                'createdAt' => now()->subDay()->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
                'resolvedAt' => now()->toIso8601String(),
                'resolutionNotes' => 'Merged duplicates',
                'book' => null,
            ],
        ];

        $this->documentStoreService->shouldReceive('listLibraryRepairIssues')
            ->once()
            ->with([
                'show_resolved' => false,
                'status' => 'pending',
            ], 25, 1)
            ->andReturn($issues)
        ;

        $this->documentStoreService->shouldReceive('countLibraryRepairIssues')
            ->once()
            ->with([
                'show_resolved' => false,
                'status' => 'pending',
            ])
            ->andReturn(count($issues))
        ;

        $this->audiobookBayService->shouldReceive('buildSearchUrl')
            ->once()
            ->with('author a example one')
            ->andReturn('https://abb.test/?s=author+a+example+one')
        ;

        $response = $this->get(route('admin.library-repair.index'));

        $response->assertStatus(200)
            ->assertViewIs('admin.library-repair.index')
            ->assertSee('Library Repair')
            ->assertSee('books/example-one')
            ->assertSee('books/example-two')
            ->assertSee('Example One')
            ->assertSee('Search on AudiobookBay')
        ;
    }

    #[Test]
    public function indexHonorsFilters(): void
    {
        $this->audiobookBayService->shouldReceive('buildSearchUrl')->never();

        $this->documentStoreService->shouldReceive('listLibraryRepairIssues')
            ->once()
            ->with([
                'issue_type' => 'orphan_directory',
                'search' => 'missing',
                'show_resolved' => true,
            ], 10, 2)
            ->andReturn([])
        ;

        $this->documentStoreService->shouldReceive('countLibraryRepairIssues')
            ->once()
            ->with([
                'issue_type' => 'orphan_directory',
                'search' => 'missing',
                'show_resolved' => true,
            ])
            ->andReturn(0)
        ;

        $response = $this->get(route('admin.library-repair.index', [
            'issue_type' => 'orphan_directory',
            'search' => 'missing',
            'show_resolved' => 1,
            'limit' => 10,
            'page' => 2,
        ]));

        $response->assertStatus(200)
            ->assertSee('No issues found')
        ;
    }

    #[Test]
    public function resolveMarksIssueAsResolved(): void
    {
        $this->documentStoreService->shouldReceive('resolveLibraryRepairIssue')
            ->once()
            ->with(7, 'Fixed path')
            ->andReturn(true)
        ;

        $response = $this->post(route('admin.library-repair.resolve', ['issue' => 7]), [
            'resolution_notes' => 'Fixed path',
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success', 'Library repair issue resolved.')
        ;
    }

    #[Test]
    public function resolveHandlesFailure(): void
    {
        $this->documentStoreService->shouldReceive('resolveLibraryRepairIssue')
            ->once()
            ->with(999, null)
            ->andReturn(false)
        ;

        $response = $this->post(route('admin.library-repair.resolve', ['issue' => 999]));

        $response->assertRedirect()
            ->assertSessionHas('error', 'Unable to resolve library repair issue. It may have already been resolved.')
        ;
    }

    #[Test]
    public function rescanIssueShowsFlashMessage(): void
    {
        $this->libraryRepairService->shouldReceive('rescanIssue')
            ->once()
            ->with(42)
            ->andReturnUsing(fn () => [
                'status' => 'resolved',
                'message' => 'Issue resolved.',
            ])
        ;

        $response = $this
            ->from(route('admin.library-repair.index'))
            ->post(route('admin.library-repair.rescan', 42));

        $response->assertRedirect(route('admin.library-repair.index'))
            ->assertSessionHas('success', 'Issue resolved.')
        ;
    }

    #[Test]
    public function importMissingDirectoryShowsInfoMessage(): void
    {
        $this->libraryRepairService->shouldReceive('importMissingDirectoryIssue')
            ->once()
            ->with(55, '/tmp/example')
            ->andReturnUsing(fn () => [
                'status' => 'pending',
                'message' => 'Import queued.',
            ])
        ;

        $response = $this
            ->from(route('admin.library-repair.index'))
            ->post(route('admin.library-repair.import-missing', 55), [
                'import_path' => '/tmp/example',
            ]);

        $response->assertRedirect(route('admin.library-repair.index'))
            ->assertSessionHas('info', 'Import queued.')
        ;
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
}
