<?php

namespace Tests\Feature\Api;

use App\Traits\HardcoverApiTrait;

class HardcoverApiTest extends BaseApiTest
{
    use HardcoverApiTrait;

    protected string $apiBaseUrl = 'https://hardcover.app/api';

    protected function getServiceName(): string
    {
        return 'hardcover';
    }

    protected function getMockSearchResponse(): array
    {
        return [
            'books' => [
                [
                    'id' => 'test_id',
                    'title' => 'Test Book',
                    'author' => 'Test Author',
                    'description' => 'Test Description',
                    'cover_image_url' => 'http://example.com/cover.jpg',
                    'published_date' => '2023-01-01',
                    'publisher' => 'Test Publisher',
                    'categories' => ['Fiction']
                ]
            ]
        ];
    }

    protected function getMockDetailsResponse(): array
    {
        return [
            'id' => 'test_id',
            'title' => 'Test Book',
            'author' => 'Test Author',
            'description' => 'Test Description',
            'cover_image_url' => 'http://example.com/cover.jpg',
            'published_date' => '2023-01-01',
            'publisher' => 'Test Publisher',
            'categories' => ['Fiction']
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
