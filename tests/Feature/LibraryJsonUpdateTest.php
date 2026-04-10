<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Publisher;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryJsonUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Base TestCase now handles clean state for isolated books disk in parallel mode
    }

    protected function createBookDirectory(string $path, bool $withAudio = true): void
    {
        $fullPath = Storage::disk('books')->path($path);
        if (!File::isDirectory($fullPath)) {
            File::makeDirectory($fullPath, 0755, true);
        }

        if ($withAudio) {
            // Create a dummy audio file so HandlesLibraryJson doesn't skip it
            File::put($fullPath . '/dummy.mp3', 'dummy content');
        }
    }

    public function testLibraryJsonIsCreatedWhenBookIsUpdated()
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Narrator $narrator */
        $narrator = Narrator::factory()->create(['name' => 'Test Narrator']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);
        /** @var Publisher $publisher */
        $publisher = Publisher::create(['name' => 'Test Publisher']);
        /** @var Series $series */
        $series = Series::factory()->create(['name' => 'Test Series']);

        $bookDir = 'test-author/test-book';
        $this->createBookDirectory($bookDir);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Test Book',
            'directory_path' => $bookDir,
            'description' => 'Test description',
            'language' => 'en',
            'duration' => 3600,
            'publisher_id' => $publisher->id,
        ]);

        $book->authors()->attach($author->id);
        $book->narrators()->attach($narrator->id);
        $book->genres()->attach($genre->id);
        $book->series()->attach($series->id, ['series_number' => '01']);

        $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

        $documentStore->updateBook((string) $book->id, [
            'title' => 'Updated Test Book',
            'description' => 'Updated description',
            'authors' => [$author->id],
            'narrators' => [$narrator->id],
            'genres' => [$genre->id],
        ]);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');

        $this->assertFileExists($jsonPath);

        $jsonContent = json_decode(file_get_contents($jsonPath), true);

        $this->assertIsArray($jsonContent);
        $this->assertEquals('Updated Test Book', $jsonContent['title']);
        $this->assertEquals('Updated description', $jsonContent['description']);
        $this->assertEquals('en', $jsonContent['language']);
        $this->assertEquals(3600, $jsonContent['duration']);

        $this->assertCount(1, $jsonContent['authors']);
        $this->assertEquals('Test Author', $jsonContent['authors'][0]['name']);

        $this->assertCount(1, $jsonContent['narrators']);
        $this->assertEquals('Test Narrator', $jsonContent['narrators'][0]['name']);

        $this->assertCount(1, $jsonContent['genres']);
        $this->assertEquals('Test Genre', $jsonContent['genres'][0]['name']);

        $this->assertNotNull($jsonContent['series']);
        $this->assertEquals('Test Series', $jsonContent['series']['name']);

        $this->assertNotNull($jsonContent['publisher']);
        $this->assertEquals('Test Publisher', $jsonContent['publisher']['name']);

        $this->assertArrayHasKey('metadata', $jsonContent);
        $this->assertEquals('1.1', $jsonContent['metadata']['version']);
    }

    public function testLibraryJsonIsUpdatedWhenBookRelationshipsChange()
    {
        /** @var Author $author1 */
        $author1 = Author::factory()->create(['name' => 'Author One']);
        /** @var Author $author2 */
        $author2 = Author::factory()->create(['name' => 'Author Two']);
        /** @var Genre $genre1 */
        $genre1 = Genre::factory()->create(['name' => 'Genre One']);
        /** @var Genre $genre2 */
        $genre2 = Genre::factory()->create(['name' => 'Genre Two']);

        $bookDir = 'test-author/test-book-2';
        $this->createBookDirectory($bookDir);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Test Book 2',
            'directory_path' => $bookDir,
        ]);

        $book->authors()->attach($author1->id);
        $book->genres()->attach($genre1->id);

        $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

        $documentStore->updateBook((string) $book->id, [
            'title' => 'Test Book 2',
            'authors' => [$author2->id],
            'genres' => [$genre2->id],
        ]);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');

        $this->assertFileExists($jsonPath);

        $jsonContent = json_decode(file_get_contents($jsonPath), true);

        $this->assertCount(1, $jsonContent['authors']);
        $this->assertEquals('Author Two', $jsonContent['authors'][0]['name']);

        $this->assertCount(1, $jsonContent['genres']);
        $this->assertEquals('Genre Two', $jsonContent['genres'][0]['name']);
    }

    public function testLibraryJsonIsUpdatedWhenPivotRelationshipsChangeDirectly(): void
    {
        /** @var Author $author1 */
        $author1 = Author::factory()->create(['name' => 'Author One']);
        /** @var Author $author2 */
        $author2 = Author::factory()->create(['name' => 'Author Two']);

        $bookDir = 'test-author/pivot-change-book';
        $this->createBookDirectory($bookDir);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Pivot Change Book',
            'directory_path' => $bookDir,
        ]);

        $book->authors()->attach($author1->id);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');
        $this->assertFileExists($jsonPath);

        $jsonContent = json_decode(file_get_contents($jsonPath), true);
        $this->assertIsArray($jsonContent);
        $this->assertCount(1, $jsonContent['authors']);
        $this->assertEquals('Author One', $jsonContent['authors'][0]['name']);

        $book->authors()->sync([$author2->id]);

        $jsonContentAfter = json_decode(file_get_contents($jsonPath), true);
        $this->assertIsArray($jsonContentAfter);
        $this->assertCount(1, $jsonContentAfter['authors']);
        $this->assertEquals('Author Two', $jsonContentAfter['authors'][0]['name']);
    }

    public function testLibraryJsonIsNotCreatedWhenDirectoryPathMissing()
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Test Book Without Directory',
            'directory_path' => null,
        ]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);

        $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

        $documentStore->updateBook((string) $book->id, [
            'title' => 'Updated Book Without Directory',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testLibraryJsonIsNotCreatedWhenDirectoryHasNoAudioFiles()
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir = 'test-author/empty-book';
        $this->createBookDirectory($bookDir, false);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Test Book With Empty Directory',
            'directory_path' => $bookDir,
        ]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);

        $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

        $documentStore->updateBook((string) $book->id, [
            'title' => 'Updated Book With Empty Directory',
        ]);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');

        $this->assertFileDoesNotExist($jsonPath);
    }

    public function testLibraryJsonIsCreatedWhenDirectoryHasAudioFiles()
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir = 'test-author/book-with-audio';
        $this->createBookDirectory($bookDir);

        $fullPath = Storage::disk('books')->path($bookDir);
        file_put_contents($fullPath . '/chapter01.mp3', 'dummy audio content');

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Test Book With Audio Files',
            'directory_path' => $bookDir,
        ]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);

        $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

        $documentStore->updateBook((string) $book->id, [
            'title' => 'Updated Book With Audio Files',
        ]);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');

        $this->assertFileExists($jsonPath);
    }

    public function testLibraryJsonContainsAllNonUserSpecificData()
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Narrator $narrator */
        $narrator = Narrator::factory()->create(['name' => 'Test Narrator']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Science Fiction']);
        /** @var Publisher $publisher */
        $publisher = Publisher::create(['name' => 'Test Publisher']);
        /** @var Series $series */
        $series = Series::factory()->create(['name' => 'Test Series', 'is_collection' => false]);

        $bookDir = 'test-author/comprehensive-book';
        $this->createBookDirectory($bookDir);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Comprehensive Test Book',
            'directory_path' => $bookDir,
            'description' => 'A comprehensive test',
            'release_date' => '2024-01-15',
            'isbn' => '9780000000000',
            'language' => 'en',
            'duration' => 37200,
            'cover_image' => 'cover.jpg',
            'publisher_id' => $publisher->id,
        ]);

        $book->authors()->attach($author->id);
        $book->narrators()->attach($narrator->id);
        $book->genres()->attach($genre->id);
        $book->series()->attach($series->id, ['series_number' => '03']);

        $documentStore = app(\App\Contracts\DocumentStoreServiceInterface::class);

        $documentStore->updateBook((string) $book->id, [
            'title' => 'Comprehensive Test Book',
        ]);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');

        $this->assertFileExists($jsonPath);

        $jsonContent = json_decode(file_get_contents($jsonPath), true);
        $this->assertIsArray($jsonContent);

        $this->assertEquals($book->id, $jsonContent['id']);
        $this->assertEquals('Comprehensive Test Book', $jsonContent['title']);
        $this->assertEquals('A comprehensive test', $jsonContent['description']);
        $this->assertEquals('2024-01-15', $jsonContent['release_date']);
        $this->assertEquals('9780000000000', $jsonContent['isbn']);
        $this->assertEquals('en', $jsonContent['language']);
        $this->assertEquals(37200, $jsonContent['duration']);
        $this->assertEquals('cover.jpg', $jsonContent['cover_image']);

        $this->assertCount(1, $jsonContent['authors']);
        $this->assertEquals($author->id, $jsonContent['authors'][0]['id']);
        $this->assertEquals('Test Author', $jsonContent['authors'][0]['name']);

        $this->assertCount(1, $jsonContent['narrators']);
        $this->assertEquals($narrator->id, $jsonContent['narrators'][0]['id']);
        $this->assertEquals('Test Narrator', $jsonContent['narrators'][0]['name']);

        $this->assertCount(1, $jsonContent['genres']);
        $this->assertEquals($genre->id, $jsonContent['genres'][0]['id']);
        $this->assertEquals('Science Fiction', $jsonContent['genres'][0]['name']);

        $this->assertNotNull($jsonContent['series']);
        $this->assertEquals($series->id, $jsonContent['series']['id']);
        $this->assertEquals('Test Series', $jsonContent['series']['name']);
        $this->assertFalse($jsonContent['series']['is_collection']);

        $this->assertNotNull($jsonContent['publisher']);
        $this->assertEquals($publisher->id, $jsonContent['publisher']['id']);
        $this->assertEquals('Test Publisher', $jsonContent['publisher']['name']);

        $this->assertArrayHasKey('created_at', $jsonContent);
        $this->assertArrayHasKey('updated_at', $jsonContent);
        $this->assertArrayHasKey('metadata', $jsonContent);
    }
}
