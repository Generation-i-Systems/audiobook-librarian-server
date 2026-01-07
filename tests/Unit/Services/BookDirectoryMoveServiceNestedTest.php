<?php

namespace Tests\Unit\Services;

use App\Services\BookDirectoryMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookDirectoryMoveServiceNestedTest extends TestCase
{
    use RefreshDatabase;

    protected BookDirectoryMoveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('books');
        $this->service = new BookDirectoryMoveService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesNotCreateNestedDirectoryStructure(): void
    {
        $disk = Storage::disk('books');

        // Create source directory with a long filename
        $disk->makeDirectory('Fantasy/Author/Old Name');
        $disk->put('Fantasy/Author/Old Name/1. Brent Weeks - Night Angel 01 - The Way of Shadows.m4b', 'audio content');
        $disk->put('Fantasy/Author/Old Name/cover.jpg', 'cover content');
        $disk->put('Fantasy/Author/Old Name/librarian.json', '{"title": "Test"}');

        // Move to new directory
        $result = $this->service->moveBookDirectoryContents(
            'Fantasy/Author/Old Name',
            'Fantasy/Author/01 The Way of Shadows'
        );

        $this->assertTrue($result['moved']);
        $this->assertSame('Fantasy/Author/01 The Way of Shadows', $result['directoryPath']);

        // Verify files are in the correct location (not nested)
        $this->assertTrue($disk->exists('Fantasy/Author/01 The Way of Shadows/1. Brent Weeks - Night Angel 01 - The Way of Shadows.m4b'));
        $this->assertTrue($disk->exists('Fantasy/Author/01 The Way of Shadows/cover.jpg'));
        $this->assertTrue($disk->exists('Fantasy/Author/01 The Way of Shadows/librarian.json'));

        // Verify no nested directory was created
        $this->assertFalse($disk->exists('Fantasy/Author/01 The Way of Shadows/01 The Way of Shadows'));

        // Verify old directory was cleaned up
        $this->assertFalse($disk->exists('Fantasy/Author/Old Name'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itHandlesDirectoryNamesWithSamePatternAsFilename(): void
    {
        $disk = Storage::disk('books');

        // Create source directory
        $disk->makeDirectory('Fantasy/Author/Book Name');
        $disk->put('Fantasy/Author/Book Name/Book Name.m4b', 'audio content');

        // Move to new directory with same name
        $result = $this->service->moveBookDirectoryContents(
            'Fantasy/Author/Book Name',
            'Fantasy/Author/Book Name'
        );

        $this->assertTrue($result['moved']);

        // Verify file is at the root level, not nested
        $this->assertTrue($disk->exists('Fantasy/Author/Book Name/Book Name.m4b'));
        $this->assertFalse($disk->exists('Fantasy/Author/Book Name/Book Name/Book Name.m4b'));
    }
}
