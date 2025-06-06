<?php

// NOTE: Firestore project configuration is missing, causing ValidationException in tests. All tests will be skipped if Firestore is not configured.

namespace Tests\Feature;

use Tests\TestCase;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use App\Traits\BookImportTrait;

class BookImportTest extends TestCase
{
    use BookImportTrait;

    protected $firestore;
    protected $booksCollection;
    protected $genresCollection;
    protected $seriesCollection;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->shouldSkipFirestoreTests()) {
            $this->markTestSkipped('Firestore config missing: skipping Firestore-dependent tests.');
        }

        $this->firestore = new FirestoreClient([
            'projectId' => config('firebase.project_id'),
            'keyFilePath' => config('firebase.credentials.file')
        ]);

        $this->booksCollection = $this->firestore->collection('books');
        $this->genresCollection = $this->firestore->collection('genres');
        $this->seriesCollection = $this->firestore->collection('series');

        $this->clearTestData();
    }

    /**
     * Helper to check if Firestore config is missing.
     * @return bool
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
        $series = $this->seriesCollection->where('name', '=', 'Test Series')
            ->documents();
        foreach ($series as $item) {
            $item->reference()->delete();
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_process_dir_path_with_series(): void
    {
        // Create test genre and series
        $genreRef = $this->genresCollection->add(['name' => 'Test Genre']);
        $seriesRef = $this->seriesCollection->add(['name' => 'Test Series']);

        $dirPath = '/Test Genre/Test Author/Test Series/1 Test Book';
        $result = $this->processDirPath($dirPath);

        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals('Test Author', $result['authors'][0]);
        $this->assertEquals('Test Genre', $result['genre']);
        $this->assertEquals('Test Series', $result['series']);
        $this->assertEquals(1, $result['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_process_dir_path_without_series(): void
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_import_book_from_path(): void
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
                ]
            ]
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
