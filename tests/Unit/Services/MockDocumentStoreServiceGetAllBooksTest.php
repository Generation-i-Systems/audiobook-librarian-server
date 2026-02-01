<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use Tests\Mocks\MockDocumentStoreService;
use Tests\TestCase;

class MockDocumentStoreServiceGetAllBooksTest extends TestCase
{
    #[Test]
    public function getAllBooksReturnsAllBooksByDefault(): void
    {
        $service = new MockDocumentStoreService();

        $service->createBook(['id' => 'b1', 'title' => 'Book 1']);
        $service->createBook(['id' => 'b2', 'title' => 'Book 2']);
        $service->createBook(['id' => 'b3', 'title' => 'Book 3']);

        $books = $service->getAllBooks();

        $this->assertCount(3, $books);
    }

    #[Test]
    public function getAllBooksSupportsOffset(): void
    {
        $service = new MockDocumentStoreService();

        $service->createBook(['id' => 'b1', 'title' => 'Book 1']);
        $service->createBook(['id' => 'b2', 'title' => 'Book 2']);
        $service->createBook(['id' => 'b3', 'title' => 'Book 3']);

        $books = $service->getAllBooks(null, 1);

        $this->assertCount(2, $books);
        $this->assertSame('b2', $books[0]['id']);
        $this->assertSame('b3', $books[1]['id']);
    }

    #[Test]
    public function getAllBooksSupportsLimitAndOffset(): void
    {
        $service = new MockDocumentStoreService();

        $service->createBook(['id' => 'b1', 'title' => 'Book 1']);
        $service->createBook(['id' => 'b2', 'title' => 'Book 2']);
        $service->createBook(['id' => 'b3', 'title' => 'Book 3']);
        $service->createBook(['id' => 'b4', 'title' => 'Book 4']);

        $books = $service->getAllBooks(2, 1);

        $this->assertCount(2, $books);
        $this->assertSame('b2', $books[0]['id']);
        $this->assertSame('b3', $books[1]['id']);
    }
}
