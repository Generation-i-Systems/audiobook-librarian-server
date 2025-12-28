<?php

namespace Tests\Feature\Commands;

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

class UpdateLibraryJsonTest extends TestCase
{
    use RefreshDatabase;

    protected function createBookDirectory(string $path): void
    {
        $fullPath = Storage::disk('books')->path($path);
        if (!File::isDirectory($fullPath)) {
            File::makeDirectory($fullPath, 0755, true);
        }
    }

    public function testUpdateJsonCommandWithSingleBook()
    {
        $author = Author::factory()->create(['name' => 'Test Author']);
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir = 'test-author/test-book';
        $this->createBookDirectory($bookDir);

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

        $jsonContent = json_decode(file_get_contents($jsonPath), true);
        $this->assertEquals('Test Book', $jsonContent['title']);
        $this->assertEquals('Test description', $jsonContent['description']);
    }

    public function testUpdateJsonCommandWithDryRun()
    {
        $author = Author::factory()->create(['name' => 'Test Author']);
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir = 'test-author/dry-run-book';
        $this->createBookDirectory($bookDir);

        $book = Book::factory()->create([
            'title' => 'Dry Run Book',
            'directory_path' => $bookDir,
        ]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);

        $this->artisan('books:update-json', [
            '--book-id' => $book->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $jsonPath = Storage::disk('books')->path($bookDir . '/librarian.json');
        $this->assertFileDoesNotExist($jsonPath);
    }

    public function testUpdateJsonCommandWithNoConfirm()
    {
        $author = Author::factory()->create(['name' => 'Test Author']);
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir = 'test-author/no-confirm-book';
        $this->createBookDirectory($bookDir);

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

    public function testUpdateJsonCommandSkipsBooksWithoutDirectory()
    {
        $author = Author::factory()->create(['name' => 'Test Author']);
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

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
            ->expectsOutput('Skipped: 1');
    }

    public function testUpdateJsonCommandWithMultipleBooks()
    {
        $author = Author::factory()->create(['name' => 'Test Author']);
        $genre = Genre::factory()->create(['name' => 'Test Genre']);

        $bookDir1 = 'test-author/book-1';
        $bookDir2 = 'test-author/book-2';
        $this->createBookDirectory($bookDir1);
        $this->createBookDirectory($bookDir2);

        $book1 = Book::factory()->create([
            'title' => 'Book 1',
            'directory_path' => $bookDir1,
        ]);
        $book1->authors()->attach($author->id);
        $book1->genres()->attach($genre->id);

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

        $jsonContent1 = json_decode(file_get_contents($jsonPath1), true);
        $jsonContent2 = json_decode(file_get_contents($jsonPath2), true);

        $this->assertEquals('Book 1', $jsonContent1['title']);
        $this->assertEquals('Book 2', $jsonContent2['title']);
    }
}
