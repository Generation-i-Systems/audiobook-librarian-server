<?php

namespace Tests\Unit\Services;

use App\Services\BookDirectoryMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookDirectoryMoveServiceMetadataTest extends TestCase
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
    public function itReusesDirectoryWithOnlyLibrarianJson(): void
    {
        $disk = Storage::disk('books');

        // Create source directory with audio files
        $disk->makeDirectory('old/path');
        $disk->put('old/path/track01.mp3', 'audio content');
        $disk->put('old/path/track02.mp3', 'audio content');

        // Create target directory with only librarian.json
        $disk->makeDirectory('new/path');
        $disk->put('new/path/librarian.json', '{"title": "Test Book"}');

        $result = $this->service->moveBookDirectoryContents('old/path', 'new/path');

        $this->assertTrue($result['moved']);
        $this->assertSame('new/path', $result['directoryPath']);

        // Verify files were moved to the existing directory
        $this->assertTrue($disk->exists('new/path/track01.mp3'));
        $this->assertTrue($disk->exists('new/path/track02.mp3'));
        $this->assertTrue($disk->exists('new/path/librarian.json'));

        // Verify old directory was cleaned up
        $this->assertFalse($disk->exists('old/path'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWithOnlyCoverImage(): void
    {
        $disk = Storage::disk('books');

        // Create source directory with audio files
        $disk->makeDirectory('old/path');
        $disk->put('old/path/track01.mp3', 'audio content');

        // Create target directory with only cover image
        $disk->makeDirectory('new/path');
        $disk->put('new/path/cover.jpg', 'cover content');

        $result = $this->service->moveBookDirectoryContents('old/path', 'new/path');

        $this->assertTrue($result['moved']);
        $this->assertSame('new/path', $result['directoryPath']);

        // Verify files were moved to the existing directory
        $this->assertTrue($disk->exists('new/path/track01.mp3'));
        $this->assertTrue($disk->exists('new/path/cover.jpg'));

        // Verify old directory was cleaned up
        $this->assertFalse($disk->exists('old/path'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsTheMoveWhenTargetHasAudioFilesInsteadOfSuffixing(): void
    {
        // A directory collision on edit must be rejected, not silently resolved by
        // appending a suffix — the user confirmed this exact path.
        $disk = Storage::disk('books');

        // Create source directory with audio files
        $disk->makeDirectory('old/path');
        $disk->put('old/path/track01.mp3', 'source audio');

        // Create target directory with audio files (conflict)
        $disk->makeDirectory('new/path');
        $disk->put('new/path/track01.mp3', 'existing audio');

        $result = $this->service->moveBookDirectoryContents('old/path', 'new/path');

        $this->assertFalse($result['moved']);
        $this->assertStringContainsString('new/path', $result['error']);

        // Nothing moved — source stays put, target unchanged, no suffixed directory created.
        $this->assertTrue($disk->exists('old/path/track01.mp3'));
        $this->assertSame('existing audio', $disk->get('new/path/track01.mp3'));
        $this->assertFalse($disk->exists('new/path_01/track01.mp3'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWithMixedMetadataFiles(): void
    {
        $disk = Storage::disk('books');

        // Create source directory with audio files
        $disk->makeDirectory('old/path');
        $disk->put('old/path/track01.mp3', 'audio content');

        // Create target directory with multiple metadata files
        $disk->makeDirectory('new/path');
        $disk->put('new/path/librarian.json', '{"title": "Test"}');
        $disk->put('new/path/cover.jpg', 'cover content');
        $disk->put('new/path/cover.png', 'png cover');

        $result = $this->service->moveBookDirectoryContents('old/path', 'new/path');

        $this->assertTrue($result['moved']);
        $this->assertSame('new/path', $result['directoryPath']);

        // Verify files were moved and metadata was overwritten
        $this->assertTrue($disk->exists('new/path/track01.mp3'));
        $this->assertTrue($disk->exists('new/path/librarian.json'));
        $this->assertTrue($disk->exists('new/path/cover.jpg'));
        $this->assertTrue($disk->exists('new/path/cover.png'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsTheMoveForNonMetadataFilesInsteadOfSuffixing(): void
    {
        $disk = Storage::disk('books');

        // Create source directory with audio files
        $disk->makeDirectory('old/path');
        $disk->put('old/path/track01.mp3', 'audio content');

        // Create target directory with non-metadata file
        $disk->makeDirectory('new/path');
        $disk->put('new/path/readme.txt', 'not metadata');

        $result = $this->service->moveBookDirectoryContents('old/path', 'new/path');

        $this->assertFalse($result['moved']);
        $this->assertStringContainsString('new/path', $result['error']);

        // Nothing moved — source stays put, no suffixed directory created.
        $this->assertTrue($disk->exists('old/path/track01.mp3'));
        $this->assertTrue($disk->exists('new/path/readme.txt'));
        $this->assertFalse($disk->exists('new/path_01/track01.mp3'));
    }
}
