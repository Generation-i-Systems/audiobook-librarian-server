<?php

namespace Tests\Import\Feature;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportBookTest extends TestCase
{
    use RefreshDatabase;

    private string $testDir;
    private string $bookRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookRoot = env('BOOK_STORAGE_PATH');
        $this->testDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        // Create test directory
        File::makeDirectory($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (File::exists($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_shows_help_when_no_paths_and_no_audio_files()
    {
        $this->artisan('books:import')
            ->expectsOutput('Import Book - Quick Import Tool')
            ->assertExitCode(1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_imports_book_from_directory()
    {
        // Arrange
        $bookDir = $this->testDir . '/Test Book';
        File::makeDirectory($bookDir);

        // Create test audio file
        file_put_contents($bookDir . '/test.m4b', 'fake audio data');

        // Create metadata file
        $metadata = <<<ABS
title=Test Book
author=Test Author
genre=Fantasy
ABS;
        file_put_contents($bookDir . '/metadata.abs', $metadata);

        // Act
        $this->artisan('books:import', ['paths' => [$bookDir]])
            ->assertExitCode(0);

        // Assert
        $this->assertDatabaseHas('books', [
            'title' => 'Test Book',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_dry_run_mode()
    {
        // Arrange
        $bookDir = $this->testDir . '/Test Book';
        File::makeDirectory($bookDir);
        file_put_contents($bookDir . '/test.m4b', 'fake audio data');

        $metadata = <<<ABS
title=Dry Run Book
author=Test Author
ABS;
        file_put_contents($bookDir . '/metadata.abs', $metadata);

        // Act
        $this->artisan('books:import', [
            'paths' => [$bookDir],
            '--dry-run' => true,
        ])->assertExitCode(0);

        // Assert - no book should be created
        $this->assertDatabaseMissing('books', [
            'title' => 'Dry Run Book',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_skips_existing_books()
    {
        // Arrange
        Book::create([
            'title' => 'Existing Book',
            'directory_path' => 'Fantasy/Test Author/Existing Book',
        ]);

        $bookDir = $this->testDir . '/Existing Book';
        File::makeDirectory($bookDir);
        file_put_contents($bookDir . '/test.m4b', 'fake audio data');

        $metadata = <<<ABS
title=Existing Book
author=Test Author
ABS;
        file_put_contents($bookDir . '/metadata.abs', $metadata);

        // Act
        $this->artisan('books:import', ['paths' => [$bookDir]])
            ->expectsOutput('  Book already exists: Existing Book')
            ->assertExitCode(0);

        // Assert - only one book should exist
        $this->assertEquals(1, Book::where('title', 'Existing Book')->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_existing_books_with_force()
    {
        // Arrange
        $book = Book::create([
            'title' => 'Existing Book',
            'directory_path' => 'Fantasy/Test Author/Existing Book',
            'description' => 'Old description',
        ]);

        $bookDir = $this->testDir . '/Existing Book';
        File::makeDirectory($bookDir);
        file_put_contents($bookDir . '/test.m4b', 'fake audio data');

        $metadata = <<<ABS
title=Existing Book
author=Test Author
description=New description
ABS;
        file_put_contents($bookDir . '/metadata.abs', $metadata);

        // Act
        $this->artisan('books:import', [
            'paths' => [$bookDir],
            '--force' => true,
        ])->assertExitCode(0);

        // Assert
        $book->refresh();
        $this->assertEquals('New description', $book->description);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_multiple_paths()
    {
        // Arrange
        $book1Dir = $this->testDir . '/Book1';
        $book2Dir = $this->testDir . '/Book2';

        File::makeDirectory($book1Dir);
        File::makeDirectory($book2Dir);

        file_put_contents($book1Dir . '/test.m4b', 'fake audio data');
        file_put_contents($book2Dir . '/test.m4b', 'fake audio data');

        file_put_contents($book1Dir . '/metadata.abs', "title=Book One\nauthor=Author One");
        file_put_contents($book2Dir . '/metadata.abs', "title=Book Two\nauthor=Author Two");

        // Act
        $this->artisan('books:import', [
            'paths' => [$book1Dir, $book2Dir],
        ])->assertExitCode(0);

        // Assert
        $this->assertDatabaseHas('books', ['title' => 'Book One']);
        $this->assertDatabaseHas('books', ['title' => 'Book Two']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_audio_file_path()
    {
        // Arrange
        $bookDir = $this->testDir . '/Book';
        File::makeDirectory($bookDir);

        $audioFile = $bookDir . '/audiobook.m4b';
        file_put_contents($audioFile, 'fake audio data');

        file_put_contents($bookDir . '/metadata.abs', "title=Audio File Book\nauthor=Test Author");

        // Act - pass audio file path instead of directory
        $this->artisan('books:import', ['paths' => [$audioFile]])
            ->assertExitCode(0);

        // Assert
        $this->assertDatabaseHas('books', ['title' => 'Audio File Book']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_displays_summary()
    {
        // Arrange
        $bookDir = $this->testDir . '/Test Book';
        File::makeDirectory($bookDir);
        file_put_contents($bookDir . '/test.m4b', 'fake audio data');
        file_put_contents($bookDir . '/metadata.abs', "title=Summary Test\nauthor=Test");

        // Act & Assert
        $this->artisan('books:import', ['paths' => [$bookDir]])
            ->expectsOutput('Import Summary')
            ->expectsOutput('  Total processed:  1')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_invalid_paths()
    {
        // Act & Assert
        $this->artisan('books:import', ['paths' => ['/nonexistent/path']])
            ->expectsOutput('Path does not exist: /nonexistent/path')
            ->assertExitCode(1);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_skips_non_audio_files()
    {
        // Arrange
        $textFile = $this->testDir . '/document.txt';
        file_put_contents($textFile, 'not an audio file');

        // Act & Assert
        $this->artisan('books:import', ['paths' => [$textFile]])
            ->expectsOutput("Skipping non-audio file: {$textFile}")
            ->assertExitCode(0);
    }
}
