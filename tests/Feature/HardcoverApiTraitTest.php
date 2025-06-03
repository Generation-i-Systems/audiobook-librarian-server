<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use App\Traits\HardcoverApiTrait;

class HardcoverApiTraitTest extends TestCase
{
    use HardcoverApiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setHardcoverApiKey('fake-key');
    }

    public function testSearchAndMergeReturnsNullIfNoTitle(): void
    {
        $result = $this->searchAndMerge(['authors' => ['Test Author']]);
        $this->assertNull($result);
    }

    public function testSearchAndMergeReturnsNullIfNoResults(): void
    {
        // Mock searchBooksByTitle to return null
        $trait = $this->getMockForTrait(HardcoverApiTrait::class);
        $trait->setHardcoverApiKey('fake-key');
        $trait->expects($this->any())
            ->method('searchBooksByTitle')
            ->willReturn(null);
        $result = $trait->searchAndMerge(['title' => 'Nonexistent Book', 'authors' => ['Nobody']]);
        $this->assertNull($result);
    }

    public function testSearchAndMergeReturnsMergedData(): void
    {
        $trait = $this->getMockForTrait(HardcoverApiTrait::class, [], '', true, true, true, ['searchBooksByTitle', 'getBookDetails']);
        $trait->setHardcoverApiKey('fake-key');
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
        $trait->expects($this->any())
            ->method('searchBooksByTitle')
            ->willReturn($mockBooks);
        $trait->expects($this->any())
            ->method('getBookDetails')
            ->willReturn($mockDetails);

        $result = $trait->searchAndMerge(['title' => 'Test Book', 'authors' => ['Test Author']]);
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
