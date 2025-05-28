<?php

namespace Tests\Feature\Controllers\Admin;

use Tests\TestCase;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class BookControllerTest extends TestCase
{
    protected $firestore;
    protected $booksCollection;
    protected $genresCollection;
    protected $seriesCollection;
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->firestore = new FirestoreClient([
            'projectId' => config('firebase.project_id'),
            'keyFilePath' => config('firebase.credentials.file'),
        ]);

        $this->booksCollection = $this->firestore->collection('books');
        $this->genresCollection = $this->firestore->collection('genres');
        $this->seriesCollection = $this->firestore->collection('series');

        $this->clearTestData();

        // Create an admin user for testing
        $this->admin = $this->createAdminUser();
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

    protected function createAdminUser()
    {
        $userRef = $this->firestore->collection('users')->add([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        return $userRef->snapshot();
    }

    public function testIndexReturnsBooks()
    {
        // Create test books
        $this->booksCollection->add([
            'title' => 'Test Book 1',
            'authors' => ['Author 1'],
            'genre' => 'Fiction',
        ]);

        $this->booksCollection->add([
            'title' => 'Test Book 2',
            'authors' => ['Author 2'],
            'genre' => 'Non-Fiction',
        ]);

        $response = $this->actingAs($this->admin->data())
            ->get(route('admin.books.index'));

        $response->assertStatus(200)
            ->assertSee('Test Book 1')
            ->assertSee('Test Book 2');
    }

    public function test_store_creates_book()
    {
        $genreRef = $this->genresCollection->add(['name' => 'Fiction']);
        $seriesRef = $this->seriesCollection->add(['name' => 'Test Series']);

        Storage::fake('public');
        $file = UploadedFile::fake()->create('cover.jpg', 1024);

        $response = $this->actingAs($this->admin->data())
            ->post(route('admin.books.store'), [
                'title' => 'New Test Book',
                'authors' => ['Test Author'],
                'genre' => 'Fiction',
                'series' => ['Test Series' => '1'],
                'cover_image' => $file,
                'description' => 'Test description',
            ]);

        $response->assertRedirect(route('admin.books.index'));

        // Verify book was created
        $books = $this->booksCollection->where('title', '=', 'New Test Book')
            ->documents();

        $this->assertFalse($books->isEmpty());
        $book = $books->rows()[0]->data();
        $this->assertEquals('Test Author', $book['authors'][0]);
        $this->assertEquals('Fiction', $book['genre']);
        $this->assertEquals(['Test Series' => 1], $book['series']);
    }

    public function test_update_modifies_book()
    {
        // Create a test book
        $bookRef = $this->booksCollection->add([
            'title' => 'Old Title',
            'authors' => ['Old Author'],
            'genre' => 'Old Genre',
            'series' => [],
        ]);

        $bookId = $bookRef->id();

        $response = $this->actingAs($this->admin->data())
            ->put(route('admin.books.update', $bookId), [
                'title' => 'Updated Title',
                'authors' => ['New Author'],
                'genre' => 'New Genre',
                'series' => [],
                'description' => 'Updated description',
            ]);

        $response->assertRedirect(route('admin.books.index'));

        // Verify book was updated
        $book = $this->booksCollection->document($bookId)->snapshot();
        $this->assertEquals('Updated Title', $book['title']);
        $this->assertEquals(['New Author'], $book['authors']);
        $this->assertEquals('New Genre', $book['genre']);
    }

    public function test_destroy_deletes_book()
    {
        // Create a test book
        $bookRef = $this->booksCollection->add([
            'title' => 'Book to Delete',
            'authors' => ['Author'],
            'genre' => 'Fiction',
        ]);

        $bookId = $bookRef->id();

        $response = $this->actingAs($this->admin->data())
            ->delete(route('admin.books.destroy', $bookId));

        $response->assertRedirect(route('admin.books.index'));

        // Verify book was deleted
        $book = $this->booksCollection->document($bookId)->snapshot();
        $this->assertFalse($book->exists());
    }
}
