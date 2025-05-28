<?php

namespace Tests\Feature;

use App\Traits\HardcoverApiTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HardcoverApiTest extends TestCase
{
    use RefreshDatabase;
    use HardcoverApiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the HTTP client for all tests
        Http::fake();
        
        // Set a test API key
        $this->setHardcoverApiKey('test-api-key');
    }
    
    /** @test */
    public function testSearchBooksByTitle()
    {
        // Mock successful search response
        Http::fake([
            'api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'books' => [
                        [
                            'id' => '1',
                            'title' => 'Test Book',
                            'pages' => 300,
                            'release_date' => '2024-01-01',
                            'description' => 'A test book',
                            'cover_image_url' => 'https://example.com/cover.jpg',
                            'authors' => [
                                ['author' => ['name' => 'Test Author']]
                            ]
                        ]
                    ]
                ]
            ])
        ]);
        
        $results = $this->searchBooksByTitle('Test');
        
        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('Test Book', $results[0]['title']);
        $this->assertEquals('Test Author', $results[0]['authors'][0]['author']['name']);
    }
    
    /** @test */
    public function testGetBookDetails()
    {
        // Mock successful book details response
        Http::fake([
            'api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'books_by_pk' => [
                        'id' => '1',
                        'title' => 'Test Book',
                        'subtitle' => 'Subtitle',
                        'description' => 'A test book',
                        'pages' => 300,
                        'release_date' => '2024-01-01',
                        'isbn_10' => '1234567890',
                        'isbn_13' => '1234567890123',
                        'cover_image_url' => 'https://example.com/cover.jpg',
                        'publisher' => ['name' => 'Test Publisher'],
                        'authors' => [
                            ['author' => ['id' => '1', 'name' => 'Test Author']]
                        ],
                        'narrators' => [
                            ['author' => ['id' => '2', 'name' => 'Test Narrator']]
                        ]
                    ]
                ]
            ])
        ]);
        
        $book = $this->getBookDetails('1');
        
        $this->assertIsArray($book);
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals('Test Author', $book['authors'][0]['author']['name']);
        $this->assertEquals('Test Narrator', $book['narrators'][0]['author']['name']);
    }
    
    /** @test */
    public function testGetBooksByAuthor()
    {
        // Mock successful author books response
        Http::fake([
            'api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'books' => [
                        [
                            'id' => '1',
                            'title' => 'Test Book 1',
                            'release_date' => '2024-01-01',
                            'cover_image_url' => 'https://example.com/cover1.jpg'
                        ],
                        [
                            'id' => '2',
                            'title' => 'Test Book 2',
                            'release_date' => '2023-01-01',
                            'cover_image_url' => 'https://example.com/cover2.jpg'
                        ]
                    ]
                ]
            ])
        ]);
        
        $books = $this->getBooksByAuthor('Test Author');
        
        $this->assertIsArray($books);
        $this->assertCount(2, $books);
        $this->assertEquals('Test Book 1', $books[0]['title']);
        $this->assertEquals('Test Book 2', $books[1]['title']);
    }
    
    /** @test */
    public function testApiKeyRequired()
    {
        // Clear the API key
        $this->setHardcoverApiKey('');
        
        $result = $this->searchBooksByTitle('Test');
        
        $this->assertNull($result);
    }
}
