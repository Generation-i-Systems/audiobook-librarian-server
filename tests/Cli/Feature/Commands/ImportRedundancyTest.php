<?php

namespace Tests\Cli\Feature\Commands;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportRedundancyTest extends TestCase
{
    use RefreshDatabase;

    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/test_redundancy_' . uniqid();
        File::makeDirectory($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_import_subdirectories_when_specific_files_are_passed()
    {
        // Setup:
        // downloads/book1.m4b
        // downloads/subdir/book2.m4b

        $book1File = $this->testDir . '/book1.m4b';
        file_put_contents($book1File, 'audio1');

        $subdir = $this->testDir . '/subdir';
        File::makeDirectory($subdir);
        $book2File = $subdir . '/book2.m4b';
        file_put_contents($book2File, 'audio2');

        // Act: Import ONLY book1.m4b
        $this->artisan('book:import', ['path' => [$book1File]])
            ->assertExitCode(0);

        // Assert: Only book1 should be in the database
        $this->assertEquals(1, Book::count(), "Expected only 1 book to be imported, but found " . Book::count());
        $this->assertDatabaseHas('books', ['title' => 'book1']);
        $this->assertDatabaseMissing('books', ['title' => 'book2']);
    }
}
