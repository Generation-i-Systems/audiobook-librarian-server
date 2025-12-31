<?php

declare(strict_types=1);

namespace Tests\Core\Unit\Services;

use App\Services\BookDirectoryMoveService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookDirectoryMoveServiceTest extends TestCase
{
    #[Test]
    public function itMovesDirectoryContentsAndDeletesOldDirectory(): void
    {
        Storage::fake('books');

        $disk = Storage::disk('books');
        $disk->makeDirectory('old/path');
        $disk->put('old/path/file.txt', 'hello');
        $disk->put('old/path/cover.jpg', 'cover');

        $service = new BookDirectoryMoveService();
        $result = $service->moveBookDirectoryContents('old/path', 'new/path', 'cover.jpg');

        $this->assertTrue($result['moved']);
        $this->assertSame('cover.jpg', $result['coverImage']);

        $this->assertFalse($disk->exists('old/path/file.txt'));
        $this->assertFalse($disk->exists('old/path/cover.jpg'));

        $this->assertTrue($disk->exists('new/path/file.txt'));
        $this->assertTrue($disk->exists('new/path/cover.jpg'));

        $this->assertEmpty($disk->allFiles('old/path'));
    }

    #[Test]
    public function itAvoidsOverwritingExistingFilesInDestination(): void
    {
        Storage::fake('books');

        $disk = Storage::disk('books');
        $disk->makeDirectory('old/path');
        $disk->makeDirectory('new/path');

        $disk->put('old/path/file.txt', 'from-old');
        $disk->put('new/path/file.txt', 'existing');

        $service = new BookDirectoryMoveService();
        $result = $service->moveBookDirectoryContents('old/path', 'new/path');

        $this->assertTrue($result['moved']);

        $this->assertSame('new/path_01', $result['directoryPath']);

        $this->assertTrue($disk->exists('new/path/file.txt'));
        $this->assertSame('existing', $disk->get('new/path/file.txt'));

        $this->assertTrue($disk->exists('new/path_01/file.txt'));
        $this->assertSame('from-old', $disk->get('new/path_01/file.txt'));
    }
}
