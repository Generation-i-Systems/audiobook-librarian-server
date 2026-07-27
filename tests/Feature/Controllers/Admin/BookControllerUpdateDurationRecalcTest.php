<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Services\AudioFileAnalyzer;
use App\Services\ExternalCoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookControllerUpdateDurationRecalcTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $documentStoreServiceMock;

    private MockInterface $audioFileAnalyzerMock;

    private string $tempBookRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentStoreServiceMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreServiceMock);

        $this->app->instance(ExternalCoverService::class, Mockery::mock(ExternalCoverService::class));

        $this->audioFileAnalyzerMock = Mockery::mock(AudioFileAnalyzer::class);
        $this->app->instance(AudioFileAnalyzer::class, $this->audioFileAnalyzerMock);

        Event::fake();

        $admin = new DocumentstoreUser([
            'id' => 'test-admin-user',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
        $this->actingAs($admin);

        $this->documentStoreServiceMock->shouldReceive('isAdmin')->andReturn(true);

        // ResolveLibraryProfileFromHost overwrites app.book_root from the resolved
        // library profile on every request, which would clobber the override below.
        $this->withoutMiddleware(\App\Http\Middleware\ResolveLibraryProfileFromHost::class);

        $this->tempBookRoot = sys_get_temp_dir() . '/book-root-test-' . uniqid();
        mkdir($this->tempBookRoot . '/My Book', 0755, true);
        config(['app.book_root' => $this->tempBookRoot]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        if (is_dir($this->tempBookRoot)) {
            @rmdir($this->tempBookRoot . '/My Book');
            @rmdir($this->tempBookRoot);
        }
        parent::tearDown();
    }

    private function updatePayload(): array
    {
        return [
            'title' => 'My Book',
            'author' => ['Some Author'],
            'genre' => ['Fiction'],
            'directoryPath' => 'My Book',
        ];
    }

    #[Test]
    public function updateSkipsDurationRecalcWhenDirectoryUnchangedAndDurationAlreadySet(): void
    {
        $bookId = 'book-1';
        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->andReturn([
            'id' => $bookId,
            'directoryPath' => 'My Book',
            'duration' => 3600,
        ]);
        $this->documentStoreServiceMock->shouldReceive('updateBook')->once();

        $this->audioFileAnalyzerMock->shouldNotReceive('getDirectoryAudioDuration');

        $response = $this->put(route('admin.books.update', $bookId), $this->updatePayload());

        $response->assertRedirect(route('admin.books.index'));
    }

    #[Test]
    public function updateRecalculatesDurationWhenStoredDurationIsMissing(): void
    {
        $bookId = 'book-2';
        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->andReturn([
            'id' => $bookId,
            'directoryPath' => 'My Book',
            'duration' => 0,
        ]);
        // Called twice: once for the field update, once for the recalculated duration.
        $this->documentStoreServiceMock->shouldReceive('updateBook')->twice();

        $this->audioFileAnalyzerMock->shouldReceive('getDirectoryAudioDuration')
            ->once()
            ->andReturn(['total_seconds' => 4200, 'formatted' => '1h 10m']);

        $response = $this->put(route('admin.books.update', $bookId), $this->updatePayload());

        $response->assertRedirect(route('admin.books.index'));
    }
}
