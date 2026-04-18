<?php

namespace Tests\Feature\Controllers\Admin;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use App\Services\AudibleService;
use App\Services\BookDirectoryParser;
use App\Services\GoogleBooksApiService;
use App\Services\ExternalCoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @var MockInterface */
    private MockInterface $documentStoreServiceMock;
    /** @var MockInterface */
    private MockInterface $externalCoverServiceMock;
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentStoreServiceMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreServiceMock);

        $this->externalCoverServiceMock = Mockery::mock(ExternalCoverService::class);
        $this->app->instance(ExternalCoverService::class, $this->externalCoverServiceMock);

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
    public function indexReturnsViewWithBooks(): void
    {
        $books = [
            ['id' => '1', 'title' => 'Test Book 1'],
            ['id' => '2', 'title' => 'Test Book 2'],
        ];

        // Mock listBooks to return expected structure with data and total keys
        $this->documentStoreServiceMock->shouldReceive('listBooks')->andReturn([
            'data' => $books,
            'total' => count($books),
        ]);

        $response = $this->get(route('admin.books.index'));

        $response->assertStatus(200)
            ->assertViewIs('admin.books.index')
            ->assertSee('Test Book 1')
            ->assertSee('Test Book 2');
    }

    #[Test]
    public function storeCreatesBook(): void
    {
        $this->withoutExceptionHandling();
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::fake('covers');
        $this->withoutMiddleware(\App\Http\Middleware\CheckAdminRole::class);

        $bookData = [
            'title' => 'New Test Book',
            'authors' => ['New Author'],
            'narrators' => [],
            'genres' => ['New Genre'],
            'series' => [
                ['seriesName' => 'Existing Series', 'number' => '1'],
                ['seriesName' => 'New Series', 'number' => '2'],
            ],
            'description' => 'Test description',
            'cover' => UploadedFile::fake()->image('cover.jpg'),
            'googleBooksId' => 'test-google-id',
        ];

        $this->documentStoreServiceMock
            ->shouldReceive('getSeriesByName')
            ->with('Existing Series')
            ->andReturn(['id' => 'existing-series-id', 'name' => 'Existing Series']);

        $this->documentStoreServiceMock
            ->shouldReceive('getSeriesByName')
            ->with('New Series')
            ->andReturn(null);

        $this->documentStoreServiceMock
            ->shouldReceive('createSeries')
            ->with('New Series', false)
            ->andReturn('new-series-id');

        $this->documentStoreServiceMock
            ->shouldReceive('findOrCreateMany')
            ->with('authors', Mockery::any())
            ->andReturn(['new-author-id']);

        $this->documentStoreServiceMock
            ->shouldReceive('findOrCreateMany')
            ->with('narrators', Mockery::any())
            ->andReturn([]);

        $this->documentStoreServiceMock
            ->shouldReceive('findOrCreateMany')
            ->with('genres', Mockery::any())
            ->andReturn(['new-genre-id']);



        $this->documentStoreServiceMock
            ->shouldReceive('createBook')
            ->once()
            ->with(Mockery::any())
            ->andReturn('new-book-id');

        // Mock the getBook call that happens after creation for the event
        $this->documentStoreServiceMock
            ->shouldReceive('getBook')
            ->with('new-book-id')
            ->andReturn(['id' => 'new-book-id', 'title' => 'New Test Book']);


        $bookData['cover'] = UploadedFile::fake()->image('cover.jpg');

        $response = $this->post(route('admin.books.store'), $bookData);

        $response->assertRedirect(route('admin.books.edit', 'new-book-id'));
    }

    #[Test]
    public function storeValidationError(): void
    {
        $response = $this->post(route('admin.books.store'), ['title' => '']); // Invalid data

        $response->assertSessionHasErrors('title');
    }

    #[Test]
    public function updateModifiesBook(): void
    {
        $bookId = 'existing-book-id';
        $updateData = [
            'title' => 'Updated Book Title',
            'author' => ['Updated Author'],
            'genre' => ['Updated Genre'],
            'series' => [['seriesName' => 'Updated Series', 'number' => '3']],
            'description' => 'Updated description',
        ];

        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->andReturn(['id' => $bookId]);
        $this->documentStoreServiceMock->shouldReceive('updateBook')->once();

        $response = $this->put(route('admin.books.update', $bookId), $updateData);

        $response->assertRedirect(route('admin.books.index'));
    }

    #[Test]
    public function indexIncludesAutofillFromPathAction(): void
    {
        $books = [
            ['id' => '1', 'title' => 'Test Book 1', 'directoryPath' => 'Author/Test Book 1'],
        ];

        $this->documentStoreServiceMock->shouldReceive('listBooks')->andReturn([
            'data' => $books,
            'total' => count($books),
        ]);

        $response = $this->get(route('admin.books.index'));

        $response->assertStatus(200)
            ->assertSee(route('admin.books.autofillFromPath', '1'), false);
    }

    #[Test]
    public function autofillFromPathPrefersAudibleAndUpdatesBook(): void
    {
        $bookId = 'existing-book-id';
        $bookData = [
            'id' => $bookId,
            'title' => 'Old Title',
            'directoryPath' => 'SciFi/John Doe/01 - New Path Title',
        ];

        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->once()->andReturn($bookData);

        $parserMock = Mockery::mock(BookDirectoryParser::class);
        $parserMock->shouldReceive('extractAuthorFromPath')->once()->andReturn('John Doe');
        $this->app->instance(BookDirectoryParser::class, $parserMock);

        $audibleServiceMock = Mockery::mock(AudibleService::class);
        $audibleServiceMock->shouldReceive('searchBooksWithFiltering')
            ->once()
            ->andReturn([
                [
                    'id' => 'B0TESTASIN',
                    'title' => 'New Path Title',
                    'author' => ['John Doe'],
                    'narrator' => ['Jane Voice'],
                    'series' => ['Cool Series' => '1'],
                    'description' => 'Auto description',
                    'publishedYear' => '2024',
                    'audibleCoverImageUrl' => 'https://example.com/cover.jpg',
                ],
            ]);
        $this->app->instance(AudibleService::class, $audibleServiceMock);

        $googleBooksMock = Mockery::mock(GoogleBooksApiService::class);
        $googleBooksMock->shouldNotReceive('searchBooks');
        $this->app->instance(GoogleBooksApiService::class, $googleBooksMock);

        $this->documentStoreServiceMock->shouldReceive('findOrCreateMany')
            ->with('authors', ['John Doe'])
            ->once()
            ->andReturn(['author-id']);
        $this->documentStoreServiceMock->shouldReceive('findOrCreateMany')
            ->with('narrators', ['Jane Voice'])
            ->once()
            ->andReturn(['narrator-id']);
        $this->documentStoreServiceMock->shouldReceive('getSeriesByName')
            ->with('Cool Series')
            ->once()
            ->andReturn(null);
        $this->documentStoreServiceMock->shouldReceive('createSeries')
            ->with('Cool Series')
            ->once()
            ->andReturn('series-id');

        $this->externalCoverServiceMock->shouldReceive('downloadCoverImage')
            ->once()
            ->andReturn([
                'success' => true,
                'path' => 'SciFi/John Doe/01 - New Path Title/cover_audible_B0TESTASIN.jpg',
            ]);

        $this->documentStoreServiceMock->shouldReceive('updateBook')
            ->with($bookId, Mockery::on(function (array $updates): bool {
                return ($updates['title'] ?? null) === 'New Path Title'
                    && ($updates['audibleId'] ?? null) === 'B0TESTASIN'
                    && ($updates['coverImage'] ?? null) === 'cover_audible_B0TESTASIN.jpg';
            }))
            ->once();

        $response = $this->post(route('admin.books.autofillFromPath', $bookId), [
            'return_url' => route('admin.books.index'),
        ]);

        $response->assertRedirect(route('admin.books.index'));
        $response->assertSessionHas('success');
    }

    #[Test]
    public function destroyPreservesFilesWhenDirectoryIsShared(): void
    {
        $directoryPath = 'shared/book/path';

        Book::factory()->create([
            'directory_path' => $directoryPath,
        ]);

        Book::factory()->create([
            'directory_path' => $directoryPath,
        ]);

        $bookId = 'existing-book-id';
        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->andReturn([
            'id' => $bookId,
            'title' => 'Test Book',
            'directoryPath' => $directoryPath,
        ]);

        // First attempt without confirmed flag - should redirect back with confirmation request
        $response = $this->delete(route('admin.books.destroy', $bookId));
        $response->assertRedirect();
        $response->assertSessionHas('requires_confirmation', true);
        $response->assertSessionHas('book_id', $bookId);

        // Second attempt with confirmed flag - should delete with files preserved
        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->andReturn([
            'id' => $bookId,
            'title' => 'Test Book',
            'directoryPath' => $directoryPath,
        ]);
        $this->documentStoreServiceMock->shouldReceive('deleteBook')->with($bookId, false)->once()->andReturn(true);

        $response = $this->delete(route('admin.books.destroy', $bookId), [
            'confirmed' => 'true',
            'delete_files' => 'true',
        ]);

        $response->assertRedirect(route('admin.books.index'));
    }

    #[Test]
    public function destroyDeletesBook(): void
    {
        $bookId = 'existing-book-id';
        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->andReturn(['id' => $bookId]);
        $this->documentStoreServiceMock->shouldReceive('deleteBook')->with($bookId, true)->once()->andReturn(true);

        $response = $this->delete(route('admin.books.destroy', $bookId));

        $response->assertRedirect(route('admin.books.index'));
    }

    #[Test]
    public function getRawJsonReturnsJsonForBook(): void
    {
        $bookId = 'existing-book-id';
        $bookData = [
            'id' => $bookId,
            'title' => 'Raw Test Book',
            'authors' => ['Raw Author'],
            'genre' => 'Raw Genre',
        ];
        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->andReturn($bookData);

        $response = $this->get(route('admin.books.rawJson', $bookId));

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Raw Test Book']);
    }

    #[Test]
    public function getRawJsonReturns404ForMissingBook(): void
    {
        $this->documentStoreServiceMock->shouldReceive('getBook')->with('nonexistent-id')->andReturn(null);

        $response = $this->get(route('admin.books.rawJson', 'nonexistent-id'));

        $response->assertStatus(404);
    }

    #[Test]
    public function saveRawJsonUpdatesBook(): void
    {
        $bookId = 'existing-book-id';
        $newJson = json_encode(['title' => 'New Raw Title']);
        $this->documentStoreServiceMock->shouldReceive('getBook')->with($bookId)->andReturn(['id' => $bookId]);
        $this->documentStoreServiceMock->shouldReceive('updateBook')->once();

        $response = $this->post(route('admin.books.saveRawJson', $bookId), [
            'json' => $newJson,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    #[Test]
    public function saveRawJsonReturnsErrorForInvalidJson(): void
    {
        $bookId = 'existing-book-id';
        $invalidJson = '{\"title\": \"New Raw Title\",}'; // Invalid JSON with trailing comma

        $response = $this->post(route('admin.books.saveRawJson', $bookId), [
            'json' => $invalidJson,
        ]);

        $response->assertStatus(422)->assertJsonStructure(['message']);
    }

    #[Test]
    public function saveRawJsonReturns404ForMissingBook(): void
    {
        $this->documentStoreServiceMock->shouldReceive('getBook')->with('nonexistent-id')->andReturn(null);
        $validJson = json_encode(['title' => 'A book']);

        $response = $this->post(route('admin.books.saveRawJson', 'nonexistent-id'), [
            'json' => $validJson,
        ]);

        $response->assertStatus(404);
    }
}
