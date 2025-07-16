<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Google\Cloud\Firestore\FirestoreClient;

class FirestoreServiceBookTest extends TestCase
{
    protected DocumentStoreServiceInterface $service;
    protected $mockDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockDb = $this->createMock(FirestoreClient::class);
        $this->service = $this->getMockBuilder(DocumentStoreServiceInterface::class)
            ->getMock();
        $this->service->db = $this->mockDb;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBook_returns_id_on_success(): void
    {
        $data = ['title' => 'Test Book'];
        $mockDocRef = $this->createMock(\stdClass::class);
        $mockDocRef->method('id')->willReturn('book123');
        $this->mockDb->expects($this->once())
            ->method('collection')->with('books')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('add')->with($data)->willReturn($mockDocRef);

        $this->service->method('getServerTimestamp')->willReturn('now');
        $data['dateAdded'] = 'now';
        $result = $this->service->createBook(['title' => 'Test Book']);
        $this->assertSame('book123', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBook_returns_null_on_failure(): void
    {
        $this->mockDb->expects($this->once())
            ->method('collection')->with('books')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('add')->willThrowException(new \Exception('fail'));
        $this->service->method('getServerTimestamp')->willReturn('now');
        Log::shouldReceive('error')->once();
        $result = $this->service->createBook(['title' => 'fail']);
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getBook_returns_data_on_success(): void
    {
        $mockSnapshot = $this->createMock(\stdClass::class);
        $mockSnapshot->method('exists')->willReturn(true);
        $mockSnapshot->method('data')->willReturn(['title' => 'Book']);
        $mockSnapshot->method('id')->willReturn('book123');
        $mockDoc = $this->createMock(\stdClass::class);
        $this->mockDb->expects($this->once())
            ->method('collection')->with('books')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('document')->with('book123')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('snapshot')->willReturn($mockSnapshot);

        $result = $this->service->getBook('book123');
        $this->assertIsArray($result);
        $this->assertSame('book123', $result['id']);
        $this->assertSame('Book', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getBook_returns_null_if_not_found(): void
    {
        $mockSnapshot = $this->createMock(\stdClass::class);
        $mockSnapshot->method('exists')->willReturn(false);
        $this->mockDb->expects($this->once())
            ->method('collection')->with('books')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('document')->with('book404')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('snapshot')->willReturn($mockSnapshot);

        $result = $this->service->getBook('book404');
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBook_sets_data(): void
    {
        $this->mockDb->expects($this->once())
            ->method('collection')->with('books')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('document')->with('book123')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('set')->with(['title' => 'Updated']);
        $this->service->updateBook('book123', ['title' => 'Updated']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function deleteBook_deletes_document(): void
    {
        $this->mockDb->expects($this->once())
            ->method('collection')->with('books')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('document')->with('book123')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('delete');
        $this->service->deleteBook('book123');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function listBooks_returns_all_books(): void
    {
        $mockDoc1 = $this->createMock(\stdClass::class);
        $mockDoc1->method('data')->willReturn(['title' => 'Book1']);
        $mockDoc1->method('id')->willReturn('id1');
        $mockDoc2 = $this->createMock(\stdClass::class);
        $mockDoc2->method('data')->willReturn(['title' => 'Book2']);
        $mockDoc2->method('id')->willReturn('id2');
        $mockDocuments = [$mockDoc1, $mockDoc2];
        $mockDocIter = new class ($mockDocuments) implements \IteratorAggregate {
            private $docs;
            public function __construct($docs)
            {
                $this->docs = $docs;
            }
            public function getIterator()
            {
                return new \ArrayIterator($this->docs);
            }
        };
        $this->mockDb->expects($this->once())
            ->method('collection')->with('books')->willReturnSelf();
        $this->mockDb->expects($this->once())
            ->method('documents')->willReturn($mockDocIter);
        $result = $this->service->listBooks();
        $this->assertCount(2, $result);
        $this->assertSame('id1', $result[0]['id']);
        $this->assertSame('Book1', $result[0]['title']);
        $this->assertSame('id2', $result[1]['id']);
        $this->assertSame('Book2', $result[1]['title']);
    }
}
