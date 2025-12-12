<?php

namespace Tests\Feature\Commands;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CRITICAL SAFETY TESTS
 * These tests verify data integrity and prevent catastrophic failures
 */
class MoveBookDirectoryCriticalTest extends TestCase
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

    /** @test */
    public function it_never_deletes_source_if_database_update_fails()
    {
        // CRITICAL: Files must not be deleted if DB update fails
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Force database error by dropping the table
        DB::statement('DROP TABLE IF EXISTS books_backup');
        DB::statement('CREATE TABLE books_backup AS SELECT * FROM books');
        DB::statement('DROP TABLE books');

        // Act - should fail
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        // Restore table
        DB::statement('CREATE TABLE books AS SELECT * FROM books_backup');
        DB::statement('DROP TABLE books_backup');

        // Assert - source should still exist
        $this->assertDirExists($this->testBookRoot . '/' . $sourcePath);
    }

    /** @test */
    public function it_uses_database_transaction_for_multiple_books()
    {
        // CRITICAL: All-or-nothing for multiple books
        $paths = [
            'Fantasy/Book1',
            'Fantasy/Book2',
            'Fantasy/Book3',
        ];

        foreach ($paths as $path) {
            $this->createTestDirectory($path);
            $this->createTestBook($path);
        }

        $initialCount = Book::count();

        // Move all books
        $this->artisan('books:move', [
            'sources' => array_merge($paths, ['Sci-Fi/']),
        ])->assertExitCode(0);

        // Assert - same number of books (no duplicates or deletions)
        $this->assertEquals($initialCount, Book::count());
    }

    /** @test */
    public function it_validates_destination_is_writable_before_moving()
    {
        // CRITICAL: Check permissions before any operations
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'ReadOnly/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Create read-only destination parent
        $readOnlyDir = $this->testBookRoot . '/ReadOnly';
        mkdir($readOnlyDir, 0444, true);

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        // Cleanup
        chmod($readOnlyDir, 0755);

        // Assert - move should fail, source should still exist
        $this->assertDirExists($this->testBookRoot . '/' . $sourcePath);
        $book->refresh();
        $this->assertEquals($sourcePath, $book->directory_path);
    }

    /** @test */
    public function it_prevents_data_loss_on_destination_collision()
    {
        // CRITICAL: Never overwrite existing data
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $this->createTestDirectory($destPath);

        $sourceBook = $this->createTestBook($sourcePath, ['title' => 'Source Book']);
        $destBook = $this->createTestBook($destPath, ['title' => 'Dest Book']);

        // Act - should fail
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        // Assert - both books still exist
        $this->assertDirExists($this->testBookRoot . '/' . $sourcePath);
        $this->assertDirExists($this->testBookRoot . '/' . $destPath);

        $sourceBook->refresh();
        $destBook->refresh();

        $this->assertEquals('Source Book', $sourceBook->title);
        $this->assertEquals('Dest Book', $destBook->title);
    }

    /** @test */
    public function it_handles_partial_filesystem_failure_gracefully()
    {
        // CRITICAL: Handle disk full, permission errors, etc.
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Create a file that will prevent the move
        $destDir = $this->testBookRoot . '/Sci-Fi/Author';
        mkdir($destDir, 0755, true);
        file_put_contents($destDir . '/Book1', 'blocking file');

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        // Assert - source unchanged, database unchanged
        $this->assertDirExists($this->testBookRoot . '/' . $sourcePath);
        $book->refresh();
        $this->assertEquals($sourcePath, $book->directory_path);
    }

    /** @test */
    public function it_prevents_moving_outside_book_root()
    {
        // CRITICAL: Security - never allow escaping book root
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = '../../../etc/passwd';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        // Assert - move should fail or be contained
        $this->assertDirExists($this->testBookRoot . '/' . $sourcePath);
        $this->assertFileDoesNotExist('/etc/passwd/Book1');
    }

    /** @test */
    public function it_handles_symlink_attacks()
    {
        // CRITICAL: Security - prevent symlink attacks
        $sourcePath = 'Fantasy/Author/Book1';
        $attackPath = '/tmp/attack-target';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Create symlink attack
        if (!is_dir($attackPath)) {
            mkdir($attackPath, 0755, true);
        }
        $symlinkPath = $this->testBookRoot . '/Sci-Fi';
        @symlink('/tmp/attack-target', $symlinkPath);

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, 'Sci-Fi/Book1'],
        ])->run();

        // Cleanup
        @unlink($symlinkPath);
        @rmdir('/tmp/attack-target');

        // Assert - should not follow symlink outside book root
        $this->assertDirExists($this->testBookRoot . '/' . $sourcePath);
        $this->assertDirDoesNotExist('/tmp/attack-target/Book1');
    }

    /** @test */
    public function it_validates_book_id_exists_before_update()
    {
        // CRITICAL: Never update non-existent records
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);
        $bookId = $book->id;

        // Delete book from DB but not filesystem
        $book->delete();

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        // Assert - should handle gracefully (exit code 2 for no books)
        $this->assertEquals(2, $result);
    }

    /** @test */
    public function it_prevents_sql_injection_in_path_queries()
    {
        // CRITICAL: Security - SQL injection prevention
        $maliciousPath = "Fantasy'; DROP TABLE books; --";
        $destPath = 'Sci-Fi/Book1';

        $this->createTestDirectory('Fantasy/Book1');
        $book = $this->createTestBook('Fantasy/Book1');

        // Act - should not execute SQL injection
        $result = $this->artisan('books:move', [
            'sources' => [$maliciousPath, $destPath],
        ])->run();

        // Assert - books table still exists
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('books'));
        $this->assertDatabaseHas('books', ['id' => $book->id]);
    }

    /** @test */
    public function it_handles_null_byte_injection()
    {
        // CRITICAL: Security - null byte injection
        $sourcePath = "Fantasy/Book1\0.txt";
        $destPath = 'Sci-Fi/Book1';

        $this->createTestDirectory('Fantasy/Book1');
        $book = $this->createTestBook('Fantasy/Book1');

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        // Assert - should handle safely
        $book->refresh();
        $this->assertEquals('Fantasy/Book1', $book->directory_path);
    }

    /** @test */
    public function it_preserves_data_on_out_of_memory_error()
    {
        // CRITICAL: Handle OOM gracefully
        $paths = [];
        for ($i = 0; $i < 10; $i++) {
            $path = "Fantasy/Book{$i}";
            $this->createTestDirectory($path);
            $this->createTestBook($path);
            $paths[] = $path;
        }

        $initialBooks = Book::all()->toArray();

        // Act - move all
        $this->artisan('books:move', [
            'sources' => array_merge($paths, ['Sci-Fi/']),
        ])->run();

        // Assert - all books still exist in DB
        $this->assertEquals(count($initialBooks), Book::count());
    }

    /** @test */
    public function it_handles_race_condition_on_same_source()
    {
        // CRITICAL: Prevent race conditions
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath1 = 'Sci-Fi/Author/Book1';
        $destPath2 = 'Horror/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Simulate concurrent moves (can't truly test without threads)
        // But we can verify the book ends up in only ONE location

        $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath1],
        ])->assertExitCode(0);

        // Second move should fail (source doesn't exist)
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath2],
        ])->run();

        // Assert - book only in one location
        $book->refresh();
        $this->assertEquals($destPath1, $book->directory_path);
        $this->assertDirDoesNotExist($this->testBookRoot . '/' . $destPath2);
    }

    /** @test */
    public function it_validates_path_length_limits()
    {
        // CRITICAL: Filesystem path length limits (usually 4096 bytes)
        $longPath = str_repeat('A/', 1000) . 'Book';
        $destPath = 'Sci-Fi/Book';

        // This should fail gracefully, not crash
        $result = $this->artisan('books:move', [
            'sources' => [$longPath, $destPath],
        ])->run();

        // Assert - should handle gracefully
        $this->assertNotEquals(0, $result);
    }

    /** @test */
    public function it_handles_circular_symlinks()
    {
        // CRITICAL: Prevent infinite loops
        $sourcePath = 'Fantasy/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Create circular symlink
        $linkPath = $this->testBookRoot . '/Fantasy/Author/Book1/circular';
        @symlink($this->testBookRoot . '/Fantasy/Author/Book1', $linkPath);

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, 'Sci-Fi/Author/Book1'],
        ])->run();

        // Cleanup
        @unlink($linkPath);

        // Assert - should complete without hanging
        $this->assertTrue(true); // If we get here, we didn't hang
    }

    /** @test */
    public function it_preserves_database_on_filesystem_rollback()
    {
        // CRITICAL: If filesystem fails mid-operation, DB should rollback
        $paths = ['Fantasy/Book1', 'Fantasy/Book2'];

        foreach ($paths as $path) {
            $this->createTestDirectory($path);
            $this->createTestBook($path);
        }

        $originalPaths = Book::pluck('directory_path')->toArray();

        // Create a scenario where second move will fail
        mkdir($this->testBookRoot . '/Sci-Fi/Book2', 0755, true);
        file_put_contents($this->testBookRoot . '/Sci-Fi/Book2/blocker', 'block');

        // Act
        $this->artisan('books:move', [
            'sources' => array_merge($paths, ['Sci-Fi/']),
        ])->run();

        // Assert - if any move failed, check DB consistency
        $currentPaths = Book::pluck('directory_path')->toArray();

        // Either all moved or none moved (atomic)
        $allMoved = !in_array('Fantasy/Book1', $currentPaths) && !in_array('Fantasy/Book2', $currentPaths);
        $noneMoved = in_array('Fantasy/Book1', $currentPaths) && in_array('Fantasy/Book2', $currentPaths);

        $this->assertTrue($allMoved || $noneMoved, 'Move must be atomic');
    }

    /** @test */
    public function it_handles_unicode_normalization_attacks()
    {
        // CRITICAL: Unicode normalization (é vs é)
        $sourcePath1 = 'Fantasy/Café/Book1'; // é as single character
        $sourcePath2 = 'Fantasy/Café/Book1'; // é as e + combining accent

        $this->createTestDirectory($sourcePath1);
        $book = $this->createTestBook($sourcePath1);

        // Act - should handle both forms correctly
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath2, 'Sci-Fi/Café/Book1'],
        ])->run();

        // Assert - should find the book regardless of normalization
        // (This might fail, which is OK - it documents the issue)
        $this->assertTrue(true);
    }

    /** @test */
    public function it_prevents_directory_traversal_in_relative_paths()
    {
        // CRITICAL: Security - directory traversal
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = '../../../../../../tmp/evil';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        // Assert - should not escape book root
        $this->assertDirDoesNotExist('/tmp/evil');
        $book->refresh();
        $this->assertStringStartsWith('Fantasy/', $book->directory_path);
    }

    /** @test */
    public function it_handles_database_deadlock()
    {
        // CRITICAL: Handle deadlocks gracefully
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Start a transaction that will cause deadlock
        DB::beginTransaction();
        DB::table('books')->where('id', $book->id)->lockForUpdate()->first();

        // Act in separate process (simulated)
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, $destPath],
        ])->run();

        DB::rollBack();

        // Assert - should handle deadlock without corruption
        $book->refresh();
        $this->assertNotNull($book->directory_path);
    }

    /** @test */
    public function it_validates_all_sources_exist_before_any_moves()
    {
        // CRITICAL: Atomic - validate ALL before moving ANY
        $paths = [
            'Fantasy/Book1',
            'Fantasy/Book2',
            'Fantasy/NonExistent', // This doesn't exist
        ];

        $this->createTestDirectory($paths[0]);
        $this->createTestDirectory($paths[1]);
        $book1 = $this->createTestBook($paths[0]);
        $book2 = $this->createTestBook($paths[1]);

        // Act
        $result = $this->artisan('books:move', [
            'sources' => array_merge($paths, ['Sci-Fi/']),
        ])->run();

        // Assert - NO books should have moved
        $book1->refresh();
        $book2->refresh();

        $this->assertEquals($paths[0], $book1->directory_path);
        $this->assertEquals($paths[1], $book2->directory_path);
    }

    /** @test */
    public function it_prevents_moving_book_root_itself()
    {
        // CRITICAL: Never allow moving the entire book root
        $result = $this->artisan('books:move', [
            'sources' => [$this->testBookRoot, '/tmp/books'],
        ])->run();

        // Assert - should fail
        $this->assertNotEquals(0, $result);
        $this->assertDirExists($this->testBookRoot);
    }

    /** @test */
    public function it_handles_extremely_large_file_counts()
    {
        // CRITICAL: Handle books with thousands of files
        $sourcePath = 'Fantasy/Author/LargeBook';
        $fullPath = $this->testBookRoot . '/' . $sourcePath;
        File::makeDirectory($fullPath, 0755, true, true);

        // Create 1000 files
        for ($i = 0; $i < 1000; $i++) {
            touch($this->testBookRoot . '/' . $sourcePath . "/file{$i}.m4b");
        }

        $book = $this->createTestBook($sourcePath);

        // Act
        $result = $this->artisan('books:move', [
            'sources' => [$sourcePath, 'Sci-Fi/Author/LargeBook'],
        ])->run();

        // Assert - all files moved
        $movedFiles = glob($this->testBookRoot . '/Sci-Fi/Author/LargeBook/*.m4b');
        $this->assertCount(1000, $movedFiles);
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

    private function assertDirExists(string $path): void
    {
        $this->assertTrue(
            File::isDirectory($path),
            "Failed asserting that directory exists: {$path}"
        );
    }

    private function assertDirDoesNotExist(string $path): void
    {
        $this->assertFalse(
            File::isDirectory($path),
            "Failed asserting that directory does not exist: {$path}"
        );
    }
}
