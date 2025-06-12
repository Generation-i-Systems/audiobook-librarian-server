<?php

namespace Tests\Feature;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookDownloadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_user_can_download_book_bundle()
    {
        // Create a book with a directory and some files
        $book = Book::factory()->create([
            'directoryPath' => 'books/test_book',
        ]);

        // Create directory
        Storage::fake('public');  // use fake storage
        Storage::disk('public')->makeDirectory($book->directoryPath);
        Storage::disk('public')->put($book->directoryPath . '/file1.txt', 'Test Content 1');
        Storage::disk('public')->put($book->directoryPath . '/file2.txt', 'Test Content 2');

        // Act
        $response = $this->get(route('books.download', $book));

        // Assert
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/zip');
        $response->assertHeader('Content-Disposition', 'attachment; filename="' . str_replace(' ', '_', $book->title) . '.zip"');

        // Clean up directory after the test
        Storage::disk('public')->deleteDirectory($book->directoryPath);
    }
}
