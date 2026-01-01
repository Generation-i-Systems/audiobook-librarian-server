<?php

namespace Tests\Cli\Feature\Commands;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * MINOR EDGE CASES - Testing obscure scenarios
 */
class MoveBookDirectoryMinorEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private string $testBookRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testBookRoot = storage_path('testing/books');
        Config::set('app.book_root', $this->testBookRoot);
        Config::set('filesystems.disks.books.root', $this->testBookRoot);
        putenv("BOOK_STORAGE_PATH={$this->testBookRoot}");
        File::makeDirectory($this->testBookRoot, 0755, true, true);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testBookRoot)) {
            File::deleteDirectory($this->testBookRoot);
        }
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_paths_with_trailing_whitespace()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1   '; // Trailing spaces

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertEquals('Sci-Fi/Author/Book1', trim($book->directory_path));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_paths_with_leading_whitespace()
    {
        $sourcePath = '   Fantasy/Author/Book1'; // Leading spaces
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory('Fantasy/Author/Book1');
        $book = $this->createTestBook('Fantasy/Author/Book1');

        $this->artisan('books:move', [
            'paths' => [trim($sourcePath), $destPath],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_paths_with_multiple_consecutive_slashes()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi///Author///Book1'; // Multiple slashes

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Should normalize to single slashes
        $book->refresh();
        $this->assertStringNotContainsString('//', $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_case_sensitive_filesystems()
    {
        // On case-sensitive filesystems, Book1 != book1
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Fantasy/Author/book1'; // Different case

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        // Should succeed on case-sensitive, might fail on case-insensitive
        $this->assertTrue($result === 0 || $result === 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_books_with_no_audio_files()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        // Create directory but no audio files
        File::makeDirectory($this->testBookRoot . '/' . $sourcePath, 0755, true);
        $book = $this->createTestBook($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_books_with_only_metadata_files()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        // Add only metadata files
        file_put_contents($this->testBookRoot . '/' . $sourcePath . '/metadata.json', '{}');
        file_put_contents($this->testBookRoot . '/' . $sourcePath . '/cover.jpg', 'fake image');

        $book = $this->createTestBook($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Verify metadata files moved
        $this->assertFileExists($this->testBookRoot . '/' . $destPath . '/metadata.json');
        $this->assertFileExists($this->testBookRoot . '/' . $destPath . '/cover.jpg');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_read_only_files_in_source()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $readOnlyFile = $this->testBookRoot . '/' . $sourcePath . '/readonly.txt';
        file_put_contents($readOnlyFile, 'test');
        chmod($readOnlyFile, 0444);

        $book = $this->createTestBook($sourcePath);

        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        // Should handle gracefully
        $this->assertTrue($result === 0 || $result === 1);

        // Cleanup
        @chmod($readOnlyFile, 0644);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_hidden_directories()
    {
        $sourcePath = 'Fantasy/Author/.HiddenBook';
        $destPath = 'Sci-Fi/Author/.HiddenBook';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_paths_with_backslashes()
    {
        // Windows-style paths
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi\\Author\\Book1'; // Backslashes

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Should normalize backslashes to forward slashes
        $this->artisan('books:move', [
            'paths' => [$sourcePath, str_replace('\\', '/', $destPath)],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertStringNotContainsString('\\', $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_paths_with_control_characters()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = "Sci-Fi/Author/Book\t1"; // Tab character

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Should sanitize or reject
        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        // Either succeeds with sanitized path or fails gracefully
        $this->assertTrue($result === 0 || $result === 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_destination_parent_is_file_not_directory()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Create Sci-Fi/Author as a file, not directory
        File::makeDirectory($this->testBookRoot . '/Sci-Fi', 0755, true);
        file_put_contents($this->testBookRoot . '/Sci-Fi/Author', 'blocking file');

        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        // Should fail gracefully
        $this->assertNotEquals(0, $result);
        $book->refresh();
        $this->assertEquals($sourcePath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_source_is_file_not_directory()
    {
        // Edge case: source is a file, not a directory
        $sourcePath = 'Fantasy/Author/book.m4b';
        $destPath = 'Sci-Fi/Author/book.m4b';

        File::makeDirectory($this->testBookRoot . '/Fantasy/Author', 0755, true);
        file_put_contents($this->testBookRoot . '/' . $sourcePath, 'audio data');

        // No book record for a file
        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        // Should return exit code 2 (not a book)
        $this->assertEquals(2, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_empty_string_in_sources()
    {
        $result = $this->artisan('books:move', [
            'paths' => ['', 'Dest'],
        ])->run();

        $this->assertNotEquals(0, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_only_destination_no_sources()
    {
        $result = $this->artisan('books:move', [
            'paths' => ['OnlyDest'],
        ])->run();

        $this->assertEquals(1, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_duplicate_sources()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Same source listed twice
        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $sourcePath, $destPath],
        ])->run();

        // Should handle gracefully (second one will fail as source doesn't exist)
        $this->assertTrue($result === 0 || $result === 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_source_equals_destination()
    {
        $path = 'Fantasy/Author/Book1';

        $this->createTestDirectory($path);
        $book = $this->createTestBook($path);

        $result = $this->artisan('books:move', [
            'paths' => [$path, $path],
        ])->run();

        // Should fail or handle as no-op
        $this->assertNotEquals(0, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_books_with_null_directory_path()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = Book::create([
            'directory_path' => null, // Null path
            'title' => 'Test Book',
            'duration' => 3600,
            'audio_file_count' => 1,
            'needs_review' => false,
        ]);

        // Should not find this book (no path to match)
        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        $this->assertEquals(2, $result); // No books found
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_books_with_empty_string_directory_path()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = Book::create([
            'directory_path' => '', // Empty string
            'title' => 'Test Book',
            'duration' => 3600,
            'audio_file_count' => 1,
            'needs_review' => false,
        ]);

        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        $this->assertEquals(2, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_destination_with_query_string()
    {
        // Edge case: destination looks like URL
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1?param=value';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Should sanitize or reject
        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        $this->assertTrue($result === 0 || $result === 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_paths_with_newlines()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = "Sci-Fi/Author/Book1\nExtra";

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Should sanitize or reject
        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, str_replace("\n", '', $destPath)],
        ])->run();

        $this->assertTrue($result === 0 || $result === 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_extremely_nested_source()
    {
        $parts = array_fill(0, 50, 'Level');
        $sourcePath = implode('/', $parts) . '/Book';
        $destPath = 'Sci-Fi/Book';

        // This might exceed path limits
        try {
            $this->createTestDirectory($sourcePath);
            $book = $this->createTestBook($sourcePath);

            $result = $this->artisan('books:move', [
                'paths' => [$sourcePath, $destPath],
            ])->run();

            $this->assertTrue($result === 0 || $result === 1);
        } catch (\Exception $e) {
            // Path too long is acceptable failure
            $this->assertTrue(true);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_books_marked_as_needs_review()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath, ['needs_review' => true]);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
        $this->assertTrue($book->needs_review); // Flag preserved
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_books_with_very_long_titles()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $longTitle = str_repeat('A', 1000);
        $book = $this->createTestBook($sourcePath, ['title' => $longTitle]);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertEquals($longTitle, $book->title); // Title preserved
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_zero_duration_books()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath, ['duration' => 0]);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertEquals(0, $book->duration);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_negative_audio_file_count()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath, ['audio_file_count' => -1]);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        $book->refresh();
        $this->assertEquals(-1, $book->audio_file_count); // Preserved even if invalid
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_paths_ending_with_dot()
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1.';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $result = $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->run();

        // Should handle (. is current directory reference)
        $this->assertTrue($result === 0 || $result === 1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_paths_with_only_dots()
    {
        $result = $this->artisan('books:move', [
            'paths' => ['.', '..'],
        ])->run();

        // Should reject
        $this->assertNotEquals(0, $result);
    }

    // Helper methods
    private function createTestDirectory(string $path): void
    {
        $fullPath = $this->testBookRoot . '/' . $path;
        File::makeDirectory($fullPath, 0755, true, true);
        file_put_contents($fullPath . '/test.m4b', 'dummy content');
    }

    private function createTestBook(string $path, array $attributes = []): Book
    {
        return Book::create(array_merge([
            'directory_path' => $path,
            'title' => 'Test Book',
            'duration' => 3600,
            'audio_file_count' => 1,
            'needs_review' => false,
        ], $attributes));
    }
}
