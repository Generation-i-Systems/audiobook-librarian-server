<?php

namespace Tests\Web\Unit\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Events\NewBookAdded;
use App\Http\Controllers\Admin\BookController;
use App\Services\AudioFileAnalyzer;
use App\Services\ExternalCoverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class BookReleaseDateTest extends TestCase
{
    protected BookController $controller;

    protected $documentStore;
    protected $externalCoverService;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mocks
        $this->documentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->externalCoverService = Mockery::mock(ExternalCoverService::class);

        $this->controller = new BookController(
            $this->documentStore,
            $this->externalCoverService,
            new AudioFileAnalyzer()
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function storeNormalizesYearOnlyReleaseDateToFirstOfYear(): void
    {
        Event::fake([NewBookAdded::class]);

        $request = Request::create('/admin/books', 'POST', [
            'title' => 'Title',
            'authors' => ['Author'],
            'narrators' => [],
            'genres' => ['Genre'],
            'release_date' => '2011',
        ]);

        $this->documentStore
            ->shouldReceive('findOrCreateMany')->times(3)->andReturnUsing(function ($collection, $items) {
                return array_map(fn ($v) => ['id' => md5($v), 'name' => $v], $items ?? []);
            });

        $this->documentStore
            ->shouldReceive('createBook')
            ->once()
            ->with(Mockery::on(function ($data) {
                return isset($data['release_date']) && $data['release_date'] === '2011-01-01';
            }))
            ->andReturn('book-1');

        $this->documentStore->shouldReceive('getBook')->andReturn(['id' => 'book-1']);

        $response = $this->controller->store($request);

        $this->assertEquals(302, $response->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateNormalizesYearOnlyReleaseDateToFirstOfYear(): void
    {
        $existingId = 'book-xyz';
        $existing = [
            'id' => $existingId,
            'title' => 'Old',
            'author' => ['A'],
            'genre' => ['G'],
        ];

        $request = Request::create("/admin/books/{$existingId}", 'PUT', [
            'title' => 'New Title',
            'author' => ['A'],
            'genre' => ['G'],
            'release_date' => '1999',
        ]);

        $this->documentStore->shouldReceive('getBook')->andReturn($existing);
        $this->documentStore->shouldReceive('updateBook')
            ->once()
            ->with($existingId, Mockery::on(function ($data) {
                return isset($data['release_date']) && $data['release_date'] === '1999-01-01';
            }))
            ->andReturn(['success' => true]);

        $response = $this->controller->update($request, $existingId);
        $this->assertEquals(302, $response->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function showViewDisplaysOnlyYearWhenStoredAsFirstOfYear(): void
    {
        $book = [
            'id' => 'b1',
            'title' => 'T',
            'author' => ['A'],
            'genre' => ['G'],
            'release_date' => '2011-01-01',
        ];

        $html = view('admin.books.show', ['book' => $book])->render();
        $this->assertStringContainsString('Release Date:', $html);
        $this->assertStringContainsString('2011', $html);
        $this->assertStringNotContainsString('2011-01-01', $html);
    }
}
