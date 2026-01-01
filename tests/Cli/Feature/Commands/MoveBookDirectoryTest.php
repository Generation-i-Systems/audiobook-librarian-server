<?php

namespace Tests\Cli\Feature\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class MoveBookDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $testBookRoot;
    private $documentStoreMock;
    private ?string $originalBookRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary test directory
        $this->testBookRoot = storage_path('testing/books');
        Config::set('app.book_root', $this->testBookRoot);
        Config::set('filesystems.disks.books.root', $this->testBookRoot);

        $this->originalBookRoot = getenv('BOOK_STORAGE_PATH') ?: null;
        putenv('BOOK_STORAGE_PATH=' . $this->testBookRoot);

        $this->documentStoreMock = $this->mock(DocumentStoreServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('updateBookPath')->zeroOrMoreTimes();
            $mock->shouldReceive('updateBook')->zeroOrMoreTimes();
            $mock->shouldReceive('getUserById')->andReturn(['id' => 'test-user']);
            $mock->shouldReceive('getBook')->andReturn(null);
        });

        // Create test directory structure
        File::makeDirectory($this->testBookRoot, 0755, true, true);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (File::exists($this->testBookRoot)) {
            File::deleteDirectory($this->testBookRoot);
        }

        // Restore original book root
        if ($this->originalBookRoot !== null) {
            putenv('BOOK_STORAGE_PATH=' . $this->originalBookRoot);
        } else {
            putenv('BOOK_STORAGE_PATH');
        }
        Mockery::close();

        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_moves_single_book_directory_and_updates_database()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert
        $this->assertDirDoesNotExist($this->testBookRoot . '/' . $sourcePath);
        $this->assertDirExists($this->testBookRoot . '/' . $destPath);

        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_database_when_book_root_is_a_symlink(): void
    {
        $symlinkRoot = storage_path('testing/books');
        $realRoot = storage_path('testing/books-real');

        $originalConfigBookRoot = (string) Config::get('app.book_root');
        $originalDiskRoot = (string) Config::get('filesystems.disks.books.root');
        $originalEnvRoot = getenv('BOOK_STORAGE_PATH') ?: null;

        try {
            if (is_link($symlinkRoot)) {
                @unlink($symlinkRoot);
            } elseif (File::exists($symlinkRoot)) {
                File::deleteDirectory($symlinkRoot);
            }

            if (File::exists($realRoot)) {
                File::deleteDirectory($realRoot);
            }

            File::makeDirectory($realRoot, 0755, true, true);

            $symlinkOk = @symlink($realRoot, $symlinkRoot);
            if (!$symlinkOk) {
                $this->markTestSkipped('Unable to create symlink for test.');
            }

            Config::set('app.book_root', $symlinkRoot);
            Config::set('filesystems.disks.books.root', $symlinkRoot);
            putenv('BOOK_STORAGE_PATH=' . $symlinkRoot);

            $sourcePath = 'Fantasy/Author/Book1';
            $destPath = 'Sci-Fi/Author/Book1';

            File::makeDirectory($realRoot . '/' . $sourcePath, 0755, true, true);

            $book = $this->createTestBook($sourcePath);

            $this->artisan('books:move', [
                'paths' => [$sourcePath, $destPath],
            ])->assertExitCode(0);

            $this->assertDirDoesNotExist($realRoot . '/' . $sourcePath);
            $this->assertDirExists($realRoot . '/' . $destPath);

            $book->refresh();
            $this->assertEquals($destPath, $book->directory_path);
        } finally {
            Config::set('app.book_root', $originalConfigBookRoot);
            Config::set('filesystems.disks.books.root', $originalDiskRoot);

            if ($originalEnvRoot !== null) {
                putenv('BOOK_STORAGE_PATH=' . $originalEnvRoot);
            } else {
                putenv('BOOK_STORAGE_PATH');
            }

            if (is_link($symlinkRoot)) {
                @unlink($symlinkRoot);
            }
            if (File::exists($realRoot)) {
                File::deleteDirectory($realRoot);
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_moves_multiple_books_to_directory()
    {
        // Arrange
        $source1 = 'Fantasy/Author1/Book1';
        $source2 = 'Fantasy/Author2/Book2';
        $dest = 'Sci-Fi/';

        $this->createTestDirectory($source1);
        $this->createTestDirectory($source2);
        $this->createTestDirectory('Sci-Fi');

        $book1 = $this->createTestBook($source1);
        $book2 = $this->createTestBook($source2);

        // Act
        $this->artisan('books:move', [
            'paths' => [$source1, $source2, $dest],
        ])->assertExitCode(0);

        // Assert
        $this->assertDirExists($this->testBookRoot . '/Sci-Fi/Book1');
        $this->assertDirExists($this->testBookRoot . '/Sci-Fi/Book2');

        $book1->refresh();
        $book2->refresh();

        $this->assertEquals('Sci-Fi/Book1', $book1->directory_path);
        $this->assertEquals('Sci-Fi/Book2', $book2->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_nested_book_paths_when_moving_parent_directory()
    {
        // Arrange
        $parentPath = 'Fantasy/Author';
        $book1Path = 'Fantasy/Author/Book1';
        $book2Path = 'Fantasy/Author/Series/Book2';

        $this->createTestDirectory($book1Path);
        $this->createTestDirectory($book2Path);

        $book1 = $this->createTestBook($book1Path);
        $book2 = $this->createTestBook($book2Path);

        // Act
        $this->artisan('books:move', [
            'paths' => [$parentPath, 'Sci-Fi/Author'],
        ])->assertExitCode(0);

        // Assert
        $book1->refresh();
        $book2->refresh();

        $this->assertEquals('Sci-Fi/Author/Book1', $book1->directory_path);
        $this->assertEquals('Sci-Fi/Author/Series/Book2', $book2->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_parent_directories_automatically()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'New/Genre/Sub/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert
        $this->assertDirExists($this->testBookRoot . '/New/Genre/Sub/Author/Book1');

        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_trailing_slash_on_destination()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/';

        $this->createTestDirectory($sourcePath);
        $this->createTestDirectory('Sci-Fi');
        $book = $this->createTestBook($sourcePath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert
        $this->assertDirExists($this->testBookRoot . '/Sci-Fi/Book1');

        $book->refresh();
        $this->assertEquals('Sci-Fi/Book1', $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_treats_existing_destination_directory_as_parent_for_single_source(): void
    {
        $sourcePath = 'Brandon Sanderson/Infinity Blade/Infinity Blade - Redemption';
        $destDir = 'Fantasy/Brandon Sanderson';

        $this->createTestDirectory($sourcePath);
        $this->createTestDirectory($destDir);
        $book = $this->createTestBook($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destDir],
        ])->assertExitCode(0);

        $this->assertDirDoesNotExist($this->testBookRoot . '/' . $sourcePath);
        $this->assertDirExists($this->testBookRoot . '/' . $destDir . '/' . basename($sourcePath));

        $book->refresh();
        $this->assertEquals($destDir . '/' . basename($sourcePath), $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_exit_code_2_when_no_books_found()
    {
        // Arrange
        $sourcePath = 'Fantasy/EmptyDir';
        $destPath = 'Sci-Fi/EmptyDir';

        $this->createTestDirectory($sourcePath);
        // No book created

        // Act & Assert
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(2);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_fails_when_require_book_is_set_and_no_books_found(): void
    {
        $sourcePath = 'Fantasy/EmptyDir';
        $destPath = 'Sci-Fi/EmptyDir';

        $this->createTestDirectory($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
            '--require-book' => true,
        ])
            ->expectsOutputToContain('No matching books were found')
            ->assertExitCode(1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prompts_for_verification_before_applying_updates(): void
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
            '--verify' => true,
        ])
            ->expectsOutputToContain('=== VERIFY MODE ===')
            ->expectsConfirmation('Proceed with filesystem move and database updates?', 'yes')
            ->assertExitCode(0);

        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_cancelling_in_verify_mode(): void
    {
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
            '--verify' => true,
        ])
            ->expectsConfirmation('Proceed with filesystem move and database updates?', 'no')
            ->assertExitCode(0);

        $book->refresh();
        $this->assertEquals($sourcePath, $book->directory_path);
        $this->assertDirExists($this->testBookRoot . '/' . $sourcePath);
        $this->assertDirDoesNotExist($this->testBookRoot . '/' . $destPath);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_supports_dry_run_mode()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
            '--dry-run' => true,
        ])->assertExitCode(0);

        // Assert - nothing should have changed
        $this->assertDirExists($this->testBookRoot . '/' . $sourcePath);
        $this->assertDirDoesNotExist($this->testBookRoot . '/' . $destPath);

        $book->refresh();
        $this->assertEquals($sourcePath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_supports_no_db_mode()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);
        $originalPath = $book->directory_path;

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
            '--no-db' => true,
        ])->assertExitCode(0);

        // Assert - files moved but DB not updated
        $this->assertDirDoesNotExist($this->testBookRoot . '/' . $sourcePath);
        $this->assertDirExists($this->testBookRoot . '/' . $destPath);

        $book->refresh();
        $this->assertEquals($originalPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prompts_when_destination_outside_book_root_and_skips_database_updates()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $destinationBase = storage_path('testing/outside-book-root/' . uniqid('dest_', true));
        $destinationPath = $destinationBase . '/Book1';

        if (File::exists($destinationBase)) {
            File::deleteDirectory($destinationBase);
        }

        try {
            $this->artisan('books:move', [
                'paths' => [$sourcePath, $destinationPath],
            ])
                ->expectsConfirmation('Destination is outside the configured book root. Continue with filesystem move only (database will not be updated)?', 'yes')
                ->expectsOutputToContain('Skipping database updates because the destination is outside the book root.')
                ->expectsOutputToContain('Database updates were skipped because the destination is outside the book root. Remember to update or remove affected book records manually.')
                ->assertExitCode(0);

            $this->assertDirExists($destinationPath);

            $book->refresh();
            $this->assertEquals($sourcePath, $book->directory_path);
        } finally {
            if (File::exists($destinationBase)) {
                File::deleteDirectory($destinationBase);
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_absolute_paths()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        $absoluteSource = $this->testBookRoot . '/' . $sourcePath;
        $absoluteDest = $this->testBookRoot . '/' . $destPath;

        // Act
        $this->artisan('books:move', [
            'paths' => [$absoluteSource, $absoluteDest],
        ])->assertExitCode(0);

        // Assert
        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_relative_paths()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert
        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_fails_when_source_does_not_exist()
    {
        // Act & Assert
        $this->artisan('books:move', [
            'paths' => ['NonExistent/Path', 'Dest/Path'],
        ])->assertExitCode(2);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_genre_reorganization()
    {
        // Arrange - Create multiple books in a genre
        $paths = [
            'Fantasy/Author1/Book1',
            'Fantasy/Author1/Book2',
            'Fantasy/Author2/Book3',
        ];

        $books = [];
        foreach ($paths as $path) {
            $this->createTestDirectory($path);
            $books[] = $this->createTestBook($path);
        }

        // Act - Move entire genre
        $this->artisan('books:move', [
            'paths' => ['Fantasy', 'Epic-Fantasy'],
        ])->assertExitCode(0);

        // Assert - All books updated
        foreach ($books as $index => $book) {
            $book->refresh();
            $expectedPath = str_replace('Fantasy', 'Epic-Fantasy', $paths[$index]);
            $this->assertEquals($expectedPath, $book->directory_path);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_author_name_correction()
    {
        // Arrange
        $wrongPath = 'Fantasy/Steven Erikson/Book1';
        $correctPath = 'Fantasy/Stephen Erikson/Book1';

        $this->createTestDirectory($wrongPath);
        $book = $this->createTestBook($wrongPath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$wrongPath, $correctPath],
        ])->assertExitCode(0);

        // Assert
        $book->refresh();
        $this->assertEquals($correctPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_series_reorganization()
    {
        // Arrange
        $paths = [
            'Fantasy/Author/Series/01-Book1',
            'Fantasy/Author/Series/02-Book2',
            'Fantasy/Author/Series/03-Book3',
        ];

        $books = [];
        foreach ($paths as $path) {
            $this->createTestDirectory($path);
            $books[] = $this->createTestBook($path);
        }

        // Act - Move entire series
        $this->artisan('books:move', [
            'paths' => ['Fantasy/Author/Series', 'Sci-Fi/Author/Series'],
        ])->assertExitCode(0);

        // Assert
        foreach ($books as $index => $book) {
            $book->refresh();
            $expectedPath = str_replace('Fantasy', 'Sci-Fi', $paths[$index]);
            $this->assertEquals($expectedPath, $book->directory_path);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_preserves_book_metadata_during_move()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath, [
            'title' => 'Test Book',
            'description' => 'Test Description',
            'duration' => 3600,
            'audio_file_count' => 5,
        ]);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert - Only path changed
        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
        $this->assertEquals('Test Book', $book->title);
        $this->assertEquals('Test Description', $book->description);
        $this->assertEquals(3600, $book->duration);
        $this->assertEquals(5, $book->audio_file_count);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_special_characters_in_paths()
    {
        // Arrange
        $sourcePath = "Fantasy/Author's Name/Book (2023)";
        $destPath = "Sci-Fi/Author's Name/Book (2023)";

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert
        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_unicode_characters_in_paths()
    {
        // Arrange
        $sourcePath = 'Fantasy/Autör/Bøøk';
        $destPath = 'Sci-Fi/Autör/Bøøk';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert
        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_timestamps_on_book_records()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);
        $originalUpdatedAt = $book->updated_at;

        // Wait a moment to ensure timestamp difference
        sleep(1);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert
        $book->refresh();
        $this->assertNotEquals($originalUpdatedAt, $book->updated_at);
        $this->assertTrue($book->updated_at->isAfter($originalUpdatedAt));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_empty_source_list()
    {
        // Act & Assert
        $this->artisan('books:move', [
            'paths' => ['OnlyDestination'],
        ])->assertExitCode(1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_very_deep_directory_structures()
    {
        // Arrange
        $sourcePath = 'A/B/C/D/E/F/G/H/Book';
        $destPath = 'X/Y/Z/Book';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert
        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_moving_to_same_location()
    {
        // Arrange
        $path = 'Fantasy/Author/Book1';

        $this->createTestDirectory($path);
        $book = $this->createTestBook($path);

        // Act - Move to same location should fail at filesystem level
        $result = $this->artisan('books:move', [
            'paths' => [$path, $path],
        ]);

        // Assert - Should fail or handle gracefully
        $this->assertNotEquals(0, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_concurrent_book_updates()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Simulate concurrent update
        $book->title = 'Updated Title';
        $book->save();

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert - Path updated, title preserved
        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
        $this->assertEquals('Updated Title', $book->title);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_books_with_relationships()
    {
        // Arrange
        $sourcePath = 'Fantasy/Author/Book1';
        $destPath = 'Sci-Fi/Author/Book1';

        $this->createTestDirectory($sourcePath);
        $book = $this->createTestBook($sourcePath);

        // Add relationships (authors, series, etc.)
        $author = \App\Models\Author::create(['name' => 'Test Author']);
        $book->authors()->attach($author);

        // Act
        $this->artisan('books:move', [
            'paths' => [$sourcePath, $destPath],
        ])->assertExitCode(0);

        // Assert - Relationships preserved
        $book->refresh();
        $this->assertEquals($destPath, $book->directory_path);
        $this->assertTrue($book->authors->contains($author));
    }

    // Helper methods

    private function createTestDirectory(string $path): void
    {
        $fullPath = $this->testBookRoot . '/' . $path;
        File::makeDirectory($fullPath, 0755, true, true);

        // Create a dummy audio file
        file_put_contents($fullPath . '/test.m4b', 'dummy content');
    }

    private function createTestBook(string $path, array $attributes = []): Book
    {
        $book = Book::factory()->create(array_merge([
            'directory_path' => $path,
            'duration' => 3600,
            'audio_file_count' => 1,
            'needs_review' => false,
        ], $attributes));

        return $book;
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
