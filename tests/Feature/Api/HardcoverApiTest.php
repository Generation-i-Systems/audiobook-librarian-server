<?php

namespace Tests\Feature\Api;

use App\Services\HardcoverApiService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\BaseApiTest;
use PHPUnit\Framework\Attributes\Test;

class HardcoverApiTest extends BaseApiTest
{
    protected HardcoverApiService $hardcoverApiService;
    protected string $testQuery = 'Test Book';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hardcoverApiService = new HardcoverApiService(
            config('services.hardcover.api_key'),
            config('services.hardcover.base_url', 'https://hardcover.app/api')
        );
    }

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
                    'categories' => ['Fiction'],
                ],
            ],
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
            'categories' => ['Fiction'],
        ];
    }

    /** @test */
    public function testCanSearchBooks()
    {
        Http::fake([
            'https://hardcover.app/api/graphql' => Http::response([
                'data' => [
                    'searchBooks' => $this->getMockSearchResponse()['books'],
                ],
            ], 200)
        ]);

        $results = $this->hardcoverApiService->searchBooks($this->testQuery);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertCommonBookStructure($results[0]);
    }

    /** @test */
    public function testCanGetBookDetails()
    {
        Http::fake([
            'https://hardcover.app/api/graphql' => Http::response([
                'data' => [
                    'book' => $this->getMockDetailsResponse(),
                ],
            ], 200)
        ]);

        $book = $this->hardcoverApiService->getBookDetails('test_id');

        $this->assertIsArray($book);
        $this->assertCommonBookStructure($book);
    }
}
