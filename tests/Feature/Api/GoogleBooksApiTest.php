<?php

namespace Tests\Feature\Api;

use App\Traits\GoogleBooksApiTrait;

class GoogleBooksApiTest extends BaseApiTest
{
    use GoogleBooksApiTrait;

    protected string $apiBaseUrl = 'https://www.googleapis.com/books/v1/volumes';

    protected function getServiceName(): string
    {
        return 'google_books';
    }

    protected function getMockSearchResponse(): array
    {
        return [
            'items' => [
                [
                    'id' => 'test_id',
                    'volumeInfo' => [
                        'title' => 'Test Book',
                        'authors' => ['Test Author'],
                        'description' => 'Test Description',
                        'imageLinks' => [
                            'thumbnail' => 'http://example.com/cover.jpg'
                        ],
                        'publishedDate' => '2023-01-01',
                        'publisher' => 'Test Publisher',
                        'categories' => ['Fiction']
                    ]
                ]
            ]
        ];
    }

    protected function getMockDetailsResponse(): array
    {
        return [
            'id' => 'test_id',
            'volumeInfo' => [
                'title' => 'Test Book',
                'authors' => ['Test Author'],
                'description' => 'Test Description',
                'imageLinks' => [
                    'thumbnail' => 'http://example.com/cover.jpg'
                ],
                'publishedDate' => '2023-01-01',
                'publisher' => 'Test Publisher',
                'categories' => ['Fiction']
            ]
        ];
    }

    /** @test */
    public function it_can_search_books()
    {
        $this->mockSuccessfulSearchResponse();
        
        $results = $this->searchBooks($this->testQuery);
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertCommonBookStructure($results[0]);
    }

    /** @test */
    public function it_can_get_book_details()
    {
        $this->mockSuccessfulDetailsResponse();
        
        $book = $this->getBookDetails('test_id');
        
        $this->assertIsArray($book);
        $this->assertCommonBookStructure($book);
    }
}
