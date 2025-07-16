<?php

namespace Tests\Feature;

use App\Traits\BookImportTrait;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookImportTest extends TestCase
{
    use BookImportTrait;

    protected $documentStore;

    protected $booksCollection;

    protected $genresCollection;

    protected $seriesCollection;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->shouldSkipFirestoreTests()) {
            $this->markTestSkipped('Firestore config missing: skipping Firestore-dependent tests.');
        }

        // TODO: Replace with a proper mock or test double for DocumentStoreServiceInterface
        $this->documentStore = $this->createMock(DocumentStoreServiceInterface::class);

        $this->booksCollection = $this->documentStore->collection('books');
        $this->genresCollection = $this->documentStore->collection('genres');
        $this->seriesCollection = $this->documentStore->collection('series');

        $this->clearTestData();
    }

    /**
     * Helper to check if Firestore config is missing.
     */
    protected function shouldSkipFirestoreTests(): bool
    {
        $projectId = config('firebase.project_id');
        $keyFile = config('firebase.credentials.file');

        return empty($projectId) || empty($keyFile) || !file_exists($keyFile);
    }

    protected function tearDown(): void
    {
        $this->clearTestData();
        parent::tearDown();
    }

    protected function clearTestData()
    {
        // Clear test books
        $books = $this->booksCollection->where('title', '=', 'Test Book')
            ->documents();
        foreach ($books as $book) {
            $book->reference()->delete();
        }

        // Clear test genres
        $genres = $this->genresCollection->where('name', '=', 'Test Genre')
            ->documents();
        foreach ($genres as $genre) {
            $genre->reference()->delete();
        }

        // Clear test series
        $series = $this->seriesCollection->where('seriesName', '=', 'Test Series')
            ->documents();
        foreach ($series as $item) {
            $item->reference()->delete();
        }
    }

    #[Test]
    public function testProcessDirPathWithSeries(): void
    {
        // Create test genre and series
        $genreRef = $this->genresCollection->add(['name' => 'Test Genre']);
        $seriesRef = $this->seriesCollection->add(['seriesName' => 'Test Series']);

        $dirPath = '/Test Genre/Test Author/Test Series/1 Test Book';
        $result = $this->processDirPath($dirPath);

        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals('Test Author', $result['authors'][0]);
        $this->assertEquals('Test Genre', $result['genre']);
        $this->assertEquals('Test Series', $result['series']);
        $this->assertEquals(1, $result['series_number']);
    }

    #[Test]
    public function testProcessDirPathWithoutSeries(): void
    {
        $genreRef = $this->genresCollection->add(['name' => 'Test Genre']);

        $dirPath = '/Test Genre/Test Author/Test Book';
        $result = $this->processDirPath($dirPath);

        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals('Test Author', $result['authors'][0]);
        $this->assertEquals('Test Genre', $result['genre']);
        $this->assertArrayNotHasKey('series', $result);
        $this->assertArrayNotHasKey('series_number', $result);
    }

    #[Test]
    public function testImportBookFromPath(): void
    {
        Storage::fake('local');

        // Create test file
        $file = UploadedFile::fake()->create('test.mp3', 1024);
        $path = 'test/path/' . $file->getClientOriginalName();
        Storage::put($path, file_get_contents($file->getPathname()));

        // Create test data
        $bookData = [
            'title' => 'Test Book',
            'authors' => ['Test Author'],
            'genre' => 'Test Genre',
            'files' => [
                [
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                ],
            ],
        ];

        // Import the book
        $bookId = $this->importBookFromPath($bookData);

        // Verify book was created
        $book = $this->booksCollection->document($bookId)->snapshot();

        $this->assertTrue($book->exists());
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals(['Test Author'], $book['authors']);
        $this->assertEquals('Test Genre', $book['genre']);
        $this->assertCount(1, $book['files']);
        $this->assertEquals($file->getClientOriginalName(), $book['files'][0]['name']);
    }
}
