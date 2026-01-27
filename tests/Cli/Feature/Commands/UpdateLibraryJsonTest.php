<?php

namespace Tests\Cli\Feature\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateLibraryJsonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure clean state for books disk
        if (config('filesystems.disks.books.root') === '/tmp/ab-librarian-test-books') {
            File::cleanDirectory('/tmp/ab-librarian-test-books');
        }
    }

    protected function createBookDirectory(string $path): void
    {
        $fullPath = Storage::disk('books')->path($path);
        if (!File::isDirectory($fullPath)) {
            File::makeDirectory($fullPath, 0755, true);
        }
        // Create a dummy audio file so HandlesLibraryJson doesn't skip it
        File::put($fullPath . '/dummy.mp3', 'dummy content');
    }

    public function testUpdateJsonCommandWithSingleBook(): void
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir = 'test-author/test-book';
        $this->createBookDirectory($bookDir);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Test Book',
            'directory_path' => $bookDir,
            'description' => 'Test description',
        ]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);

        $this->artisan('books:update-json', [
            '--book-id' => $book->id,
            '--no-confirm' => true,
        ])->assertExitCode(0);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');
        $this->assertFileExists($jsonPath);

        $jsonContent = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertIsArray($jsonContent);
        $this->assertEquals('Test Book', $jsonContent['title']);
        $this->assertEquals('Test description', $jsonContent['description']);
    }

    public function testUpdateJsonCommandWithDryRun(): void
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir = 'test-author/dry-run-book';
        $this->createBookDirectory($bookDir);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Dry Run Book',
            'directory_path' => $bookDir,
        ]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');

        // Observer may have created the file, delete it to test dry-run behavior
        if (file_exists($jsonPath)) {
            unlink($jsonPath);
        }

        $this->artisan('books:update-json', [
            '--book-id' => $book->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($jsonPath);
    }

    public function testUpdateJsonCommandWithNoConfirm(): void
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir = 'test-author/no-confirm-book';
        $this->createBookDirectory($bookDir);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'No Confirm Book',
            'directory_path' => $bookDir,
        ]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);

        $this->artisan('books:update-json', [
            '--book-id' => $book->id,
            '--no-confirm' => true,
        ])->assertExitCode(0);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');
        $this->assertFileExists($jsonPath);
    }

    public function testUpdateJsonCommandSkipsBooksWithoutDirectory(): void
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        /** @var Book $book */
        $book = Book::factory()->create([
            'title' => 'Book Without Directory',
            'directory_path' => null,
        ]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);

        $this->artisan('books:update-json', [
            '--book-id' => $book->id,
            '--no-confirm' => true,
        ])->assertExitCode(0)
            ->expectsOutput('No field differences detected. All books are already in sync.');
    }

    public function testUpdateJsonCommandWithMultipleBooks(): void
    {
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Test Author']);
        /** @var Genre $genre */
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir1 = 'test-author/book-1';
        $bookDir2 = 'test-author/book-2';
        $this->createBookDirectory($bookDir1);
        $this->createBookDirectory($bookDir2);

        /** @var Book $book1 */
        $book1 = Book::factory()->create([
            'title' => 'Book 1',
            'directory_path' => $bookDir1,
        ]);
        $book1->authors()->attach($author->id);
        $book1->genres()->attach($genre->id);

        /** @var Book $book2 */
        $book2 = Book::factory()->create([
            'title' => 'Book 2',
            'directory_path' => $bookDir2,
        ]);
        $book2->authors()->attach($author->id);
        $book2->genres()->attach($genre->id);

        $this->artisan('books:update-json', [
            '--all' => true,
            '--no-confirm' => true,
        ])->assertExitCode(0);

        $jsonPath1 = Storage::disk('books')->path($bookDir1 . '/librarian.json');
        $jsonPath2 = Storage::disk('books')->path($bookDir2 . '/librarian.json');

        $this->assertFileExists($jsonPath1);
        $this->assertFileExists($jsonPath2);

        $jsonContent1 = json_decode((string) file_get_contents($jsonPath1), true);
        $jsonContent2 = json_decode((string) file_get_contents($jsonPath2), true);
        $this->assertIsArray($jsonContent1);
        $this->assertIsArray($jsonContent2);

        $this->assertEquals('Book 1', $jsonContent1['title']);
        $this->assertEquals('Book 2', $jsonContent2['title']);
    }
}
