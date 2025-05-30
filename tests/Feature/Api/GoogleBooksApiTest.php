<?php

namespace Tests\Feature\Api;

use App\Traits\GoogleBooksApiTrait;

class GoogleBooksApiTest extends BaseApiTest
{
    private GoogleBooksApiTrait $googleBooksApi;

    protected string $apiBaseUrl = 'https://www.googleapis.com/books/v1/volumes';

    protected function setUp(): void
    {
        parent::setUp();

        // Create a new instance of a class that uses the trait
        $this->googleBooksApi = new class {
            use GoogleBooksApiTrait;
        };

        // Initialize the API client with test credentials
        $this->googleBooksApi->initGoogleBooks([
            'api_key' => 'test-api-key',
            'base_url' => $this->apiBaseUrl
        ]);
    }

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

    /**
     * @test
     */
    public function testSearchBooks(): void
    {
        $this->mockHttpResponse([$this->getMockSearchResponse()]);
        
        $results = $this->googleBooksApi->searchBooks('test query');
        
        $this->assertIsArray($results);
        $this->assertArrayHasKey('items', $results);
        $this->assertCount(1, $results['items']);
        $this->assertEquals('test_id', $results['items'][0]['id']);
    }

    /**
     * @test
     */
    public function testGetBookDetails(): void
    {
        $mockResponse = $this->getMockDetailsResponse();
        $this->mockHttpResponse([$mockResponse]);
        
        $book = $this->googleBooksApi->getBookDetails('test_id');
        
        $this->assertIsArray($book);
        $this->assertEquals('test_id', $book['id']);
        $this->assertEquals('Test Book', $book['volumeInfo']['title']);
    }
}
