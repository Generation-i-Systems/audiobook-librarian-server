<?php

declare(strict_types=1);

namespace Tests\Core\Unit\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\BookFilesystemService;
use App\Services\BookPathService;
use Mockery;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookFilesystemServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function renameItemRenamesDirectoryAndUpdatesBookDirectoryPaths(): void
    {
        $root = vfsStream::setup('testRoot');
        vfsStream::create([
            'books' => [
                'Fiction' => [
                    'OldName' => [
                        'file.mp3' => 'audio',
                    ],
                ],
            ],
        ], $root);

        config([
            'app.book_root' => vfsStream::url('testRoot/books'),
        ]);

        $documentStore = Mockery::mock(DocumentStoreServiceInterface::class);

        $documentStore->shouldReceive('listBooks')
            ->once()
            ->with(
                1,
                100,
                ['include_needs_review' => true],
                false,
                'title',
                'asc',
                true
            )
            ->andReturn([
                'data' => [
                    [
                        'id' => 'book-1',
                        'directoryPath' => 'Fiction/OldName',
                    ],
                    [
                        'id' => 'book-2',
                        'directoryPath' => 'Fiction/Other',
                    ],
                ],
                'total' => 2,
            ]);

        $documentStore->shouldReceive('updateBook')
            ->once()
            ->with('book-1', ['directoryPath' => 'Fiction/NewName']);

        $bookPathService = Mockery::mock(BookPathService::class);
        $bookPathService->shouldReceive('getBookRoot')
            ->andReturn(vfsStream::url('testRoot/books'));

        $service = new BookFilesystemService($documentStore, $bookPathService);

        $result = $service->renameItem('Fiction/OldName', 'NewName');

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame(200, $result['status'] ?? null);
        $this->assertSame('Fiction/NewName', $result['newPath'] ?? null);
        $this->assertSame('NewName', $result['newName'] ?? null);

        $this->assertTrue(is_dir(vfsStream::url('testRoot/books/Fiction/NewName')));
        $this->assertFalse(is_dir(vfsStream::url('testRoot/books/Fiction/OldName')));
    }
}
