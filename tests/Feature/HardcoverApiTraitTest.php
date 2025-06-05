<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use App\Services\HardcoverApiService;

class HardcoverApiTraitTest extends TestCase
{
    protected $serviceMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serviceMock = $this->getMockBuilder(HardcoverApiService::class)
            ->setConstructorArgs(['fake-key', 'https://api.hardcover.app/v1'])
            ->onlyMethods(['searchBooksByTitle', 'getBookDetails'])
            ->getMock();
    }

    /** @test */
    public function testSearchAndMergeReturnsNullIfNoTitle(): void
    {
        $result = $this->serviceMock->searchAndMerge(['authors' => ['Test Author']]);
        $this->assertNull($result);
    }

    /** @test */
    public function testSearchAndMergeReturnsNullIfNoResults(): void
    {
        $this->serviceMock->method('searchBooksByTitle')->willReturn(null);
        $result = $this->serviceMock->searchAndMerge(['title' => 'Nonexistent Book', 'authors' => ['Nobody']]);
        $this->assertNull($result);
    }

    /** @test */
    public function testSearchAndMergeReturnsMergedData(): void
    {
        $mockBooks = [[
            'id' => 'abc123',
            'title' => 'Test Book',
            'authors' => [['author' => ['name' => 'Test Author']]],
            'cover_image_url' => 'http://img',
            'pages' => 100,
            'description' => 'Short desc',
            'release_date' => '2020-01-01',
            'isbn_10' => '1234567890',
            'isbn_13' => '1234567890123',
            'publisher' => ['name' => 'Pub'],
        ]];
        $mockDetails = [
            'description' => 'Full desc',
            'cover_image_url' => 'http://img-large',
            'pages' => 200,
            'release_date' => '2021-01-01',
            'isbn_10' => '0987654321',
            'isbn_13' => '0987654321098',
            'publisher' => ['name' => 'BigPub'],
        ];
        $this->serviceMock->method('searchBooksByTitle')->willReturn($mockBooks);
        $this->serviceMock->method('getBookDetails')->willReturn($mockDetails);

        $result = $this->serviceMock->searchAndMerge(['title' => 'Test Book', 'authors' => ['Test Author']]);
        $this->assertIsArray($result);
        $this->assertSame('abc123', $result['hardcover_id']);
        $this->assertSame('Test Book', $result['title']);
        $this->assertSame('Full desc', $result['description']);
        $this->assertSame('http://img-large', $result['cover_image']);
        $this->assertSame(200, $result['pages']);
        $this->assertSame('2021-01-01', $result['release_date']);
        $this->assertSame('0987654321', $result['isbn_10']);
        $this->assertSame('0987654321098', $result['isbn_13']);
        $this->assertSame('BigPub', $result['publisher']);
    }
}
