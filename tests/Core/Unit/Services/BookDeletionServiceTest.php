<?php

declare(strict_types=1);

namespace Tests\Core\Unit\Services;

use App\Services\BookDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Mocks\MockDocumentStoreService;
use App\Services\PermissionsQueueService;

class BookDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookDeletionService $service;
    private MockDocumentStoreService $documentStore;
    private PermissionsQueueService $permissionsQueue;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup fake storage disks for testing
        config([
            'filesystems.disks.trash' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/trash'),
            ],
        ]);
        config([
            'filesystems.disks.books' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/books'),
            ],
        ]);

        Storage::fake('trash');
        Storage::fake('books');

        $this->documentStore = new MockDocumentStoreService();
        $queueFile = storage_path('app/permissions-queue.txt');
        if (!is_dir(dirname($queueFile))) {
            mkdir(dirname($queueFile), 0775, true);
        }
        file_put_contents($queueFile, "# test\n");

        $this->permissionsQueue = new PermissionsQueueService();
        $this->service = new BookDeletionService($this->documentStore, $this->permissionsQueue);
    }

    public function testMoveToTrashCreatesProperStructure(): void
    {
        $book = $this->createTestBook();
        $this->createTestBookFiles($book['directoryPath']);

        $result = $this->service->moveToTrash($book['id'], true);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['trash_item_id']);

        $trashDisk = Storage::disk('trash');
        $this->assertTrue($trashDisk->exists($result['trash_item_id'] . '/metadata.json'));
        $this->assertTrue($trashDisk->exists($result['trash_item_id'] . '/book.json'));
        $this->assertTrue($trashDisk->exists($result['trash_item_id'] . '/files'));

        $metadata = json_decode($trashDisk->get($result['trash_item_id'] . '/metadata.json'), true);
        $this->assertEquals($book['title'], $metadata['book_title']);
        $this->assertArrayHasKey('deleted_at', $metadata);
        $this->assertArrayHasKey('deleted_by', $metadata);
    }

    public function testMoveToTrashWithoutFilesOnlyStoresMetadata(): void
    {
        $book = $this->createTestBook();
        $this->createTestBookFiles($book['directoryPath']);

        $result = $this->service->moveToTrash($book['id'], false);

        $this->assertTrue($result['success']);

        $trashDisk = Storage::disk('trash');
        $this->assertTrue($trashDisk->exists($result['trash_item_id'] . '/metadata.json'));
        $this->assertTrue($trashDisk->exists($result['trash_item_id'] . '/book.json'));
        $this->assertFalse($trashDisk->exists($result['trash_item_id'] . '/files'));

        $booksDisk = Storage::disk('books');
        $this->assertTrue($booksDisk->exists($book['directoryPath']));
    }

    public function testMoveToTrashHandlesMissingDirectory(): void
    {
        $book = $this->createTestBook();

        $result = $this->service->moveToTrash($book['id'], true);

        $this->assertTrue($result['success']);

        $trashDisk = Storage::disk('trash');
        $this->assertTrue($trashDisk->exists($result['trash_item_id'] . '/metadata.json'));
        $this->assertTrue($trashDisk->exists($result['trash_item_id'] . '/book.json'));

        $metadata = json_decode($trashDisk->get($result['trash_item_id'] . '/metadata.json'), true);
        $this->assertEquals(0, $metadata['file_count']);
    }

    public function testRestoreRecreatesBookCorrectly(): void
    {
        $book = $this->createTestBook();
        $this->createTestBookFiles($book['directoryPath']);

        $trashResult = $this->service->moveToTrash($book['id'], true);
        $this->assertTrue($trashResult['success']);

        $bookExists = $this->documentStore->getBook($book['id']);
        $this->assertNull($bookExists);

        $restoreResult = $this->service->restore($trashResult['trash_item_id']);
        $this->assertTrue($restoreResult);

        $restoredBook = $this->documentStore->getBook($book['id']);
        $this->assertNotNull($restoredBook);
        $this->assertEquals($book['title'], $restoredBook['title']);
        $this->assertEquals($book['directoryPath'], $restoredBook['directoryPath']);

        $booksDisk = Storage::disk('books');
        $this->assertTrue($booksDisk->exists($book['directoryPath']));

        $trashDisk = Storage::disk('trash');
        $this->assertFalse($trashDisk->exists($trashResult['trash_item_id']));
    }

    public function testRestoreHandlesDirectoryConflicts(): void
    {
        $book = $this->createTestBook();
        $this->createTestBookFiles($book['directoryPath']);

        $trashResult = $this->service->moveToTrash($book['id'], true);

        $conflictBook = $this->createTestBookWithPath($book['directoryPath']);
        $booksDisk = Storage::disk('books');
        $booksDisk->makeDirectory($book['directoryPath']);
        $booksDisk->put($book['directoryPath'] . '/conflict.txt', 'occupied');

        $restoreResult = $this->service->restore($trashResult['trash_item_id']);
        $this->assertTrue($restoreResult);

        $restoredBook = $this->documentStore->getBook($book['id']);
        $this->assertNotNull($restoredBook);
        $this->assertNotEquals($book['directoryPath'], $restoredBook['directoryPath']);
        $this->assertStringStartsWith($book['directoryPath'], $restoredBook['directoryPath']);
    }

    public function testPermanentDeleteRemovesEverything(): void
    {
        $book = $this->createTestBook();
        $this->createTestBookFiles($book['directoryPath']);

        $trashResult = $this->service->moveToTrash($book['id'], true);

        $deleteResult = $this->service->permanentDelete($trashResult['trash_item_id']);
        $this->assertTrue($deleteResult);

        $trashDisk = Storage::disk('trash');
        $this->assertFalse($trashDisk->exists($trashResult['trash_item_id']));
    }

    public function testListTrashItemsReturnsCorrectPagination(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $book = $this->createTestBook(['title' => "Book $i"]);
            $this->service->moveToTrash($book['id'], false);
        }

        $page1 = $this->service->listTrashItems(1, 10);
        $this->assertCount(10, $page1['items']);
        $this->assertEquals(15, $page1['total']);
        $this->assertEquals(2, $page1['total_pages']);

        $page2 = $this->service->listTrashItems(2, 10);
        $this->assertCount(5, $page2['items']);
    }

    public function testApplyAutoCleanupRemovesOldItems(): void
    {
        $oldBook = $this->createTestBook(['title' => 'Old Book']);
        $oldTrashResult = $this->service->moveToTrash($oldBook['id'], false);

        $trashDisk = Storage::disk('trash');
        $metadataPath = $oldTrashResult['trash_item_id'] . '/metadata.json';
        $metadata = json_decode($trashDisk->get($metadataPath), true);
        $metadata['deleted_at'] = now()->subDays(100)->toIso8601String();
        $trashDisk->put($metadataPath, json_encode($metadata));

        $newBook = $this->createTestBook(['title' => 'New Book']);
        $newTrashResult = $this->service->moveToTrash($newBook['id'], false);

        $deletedCount = $this->service->applyAutoCleanup(90);

        $this->assertEquals(1, $deletedCount);
        $this->assertFalse($trashDisk->exists($oldTrashResult['trash_item_id']));
        $this->assertTrue($trashDisk->exists($newTrashResult['trash_item_id']));
    }

    public function testGetTotalTrashSizeReturnsCorrectSize(): void
    {
        $book = $this->createTestBook();
        $this->createTestBookFiles($book['directoryPath']);

        $this->service->moveToTrash($book['id'], true);

        $size = $this->service->getTotalTrashSize();
        $this->assertGreaterThan(0, $size);
    }

    public function testGetTotalTrashCountReturnsCorrectCount(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $book = $this->createTestBook(['title' => "Book $i"]);
            $this->service->moveToTrash($book['id'], false);
        }

        $count = $this->service->getTotalTrashCount();
        $this->assertEquals(5, $count);
    }

    public function testMoveToTrashPreservesAllRelationships(): void
    {
        $book = $this->createTestBookWithRelationships();

        $result = $this->service->moveToTrash($book['id'], false);

        $trashDisk = Storage::disk('trash');
        $bookJson = json_decode($trashDisk->get($result['trash_item_id'] . '/book.json'), true);

        $this->assertNotEmpty($bookJson['authors']);
        $this->assertNotEmpty($bookJson['genres']);
        $this->assertNotEmpty($bookJson['narrators']);
        $this->assertEquals($book['series'], $bookJson['series']);
    }

    private function createTestBook(array $overrides = []): array
    {
        $bookData = array_merge([
            'title' => 'Test Book',
            'directory_path' => 'test/book/path',
            'needs_review' => false,
            'needs_review_reasons' => [],
        ], $overrides);

        $bookData['directoryPath'] = $bookData['directory_path'];

        $bookId = $this->documentStore->createBook($bookData);

        return $this->documentStore->getBook((string) $bookId);
    }

    private function createTestBookWithPath(string $directoryPath): array
    {
        return $this->createTestBook(['directory_path' => $directoryPath]);
    }

    private function createTestBookWithRelationships(): array
    {
        $bookData = [
            'title' => 'Test Book with Relationships',
            'directory_path' => 'test/book/relationships',
            'authors' => [
                ['name' => 'Test Author'],
            ],
            'genres' => [
                ['name' => 'Test Genre'],
            ],
            'narrators' => [
                ['name' => 'Test Narrator'],
            ],
            'series' => [
                [
                    'seriesName' => 'Test Series',
                    'position' => 1,
                ],
            ],
        ];

        $bookData['directoryPath'] = $bookData['directory_path'];

        $bookId = $this->documentStore->createBook($bookData);

        return $this->documentStore->getBook((string) $bookId);
    }

    private function createTestBookFiles(string $directoryPath): void
    {
        $booksDisk = Storage::disk('books');
        $booksDisk->put($directoryPath . '/audio.m4b', 'fake audio content');
        $booksDisk->put($directoryPath . '/cover.jpg', 'fake cover content');
        $booksDisk->put($directoryPath . '/info.txt', 'fake info content');
    }
}
