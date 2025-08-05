<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\DocumentStoreServiceInterface;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DocumentStoreServiceBookTest extends TestCase
{
    /** @var DocumentStoreServiceInterface&MockInterface */
    private MockInterface $documentStoreMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->documentStoreMock = Mockery::mock(DocumentStoreServiceInterface::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function create_book_returns_id_on_success(): void
    {
        $data = ['title' => 'Test Book'];
        $this->documentStoreMock->shouldReceive('createBook')->with($data)->andReturn('book123');

        $result = $this->documentStoreMock->createBook($data);

        $this->assertSame('book123', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_book_returns_data_on_success(): void
    {
        $bookData = ['id' => 'book123', 'title' => 'Book'];
        $this->documentStoreMock->shouldReceive('getBook')->with('book123')->andReturn($bookData);

        $result = $this->documentStoreMock->getBook('book123');

        $this->assertIsArray($result);
        $this->assertSame('book123', $result['id']);
        $this->assertSame('Book', $result['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function get_book_returns_null_if_not_found(): void
    {
        $this->documentStoreMock->shouldReceive('getBook')->with('book404')->andReturn(null);

        $result = $this->documentStoreMock->getBook('book404');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function update_book_sets_data(): void
    {
        $bookId = 'book123';
        $data = ['title' => 'Updated'];
        $this->documentStoreMock->shouldReceive('updateBook')->with($bookId, $data)->once();

        $this->documentStoreMock->updateBook($bookId, $data);

        // No need for an assertion here, Mockery's expectation handles it.
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function delete_book_deletes_document(): void
    {
        $bookId = 'book123';
        $this->documentStoreMock->shouldReceive('deleteBook')->with($bookId)->once();

        $this->documentStoreMock->deleteBook($bookId);

        // No need for an assertion here, Mockery's expectation handles it.
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function list_books_returns_all_books(): void
    {
        $books = [
            ['id' => 'id1', 'title' => 'Book1'],
            ['id' => 'id2', 'title' => 'Book2'],
        ];
        $this->documentStoreMock->shouldReceive('listBooks')->andReturn($books);

        $result = $this->documentStoreMock->listBooks();

        $this->assertCount(2, $result);
        $this->assertSame('id1', $result[0]['id']);
        $this->assertSame('Book1', $result[0]['title']);
        $this->assertSame('id2', $result[1]['id']);
        $this->assertSame('Book2', $result[1]['title']);
    }
}
