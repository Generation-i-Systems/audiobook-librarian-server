<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Traits\BookImportTrait;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookImportTest extends TestCase
{
    use BookImportTrait;

    protected $documentStore;

    protected $booksCollection;

    protected $genresCollection;

    protected $seriesCollection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentStore = $this->createMock(DocumentStoreServiceInterface::class);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessDirPathWithSeries(): void
    {
        $this->documentStore->method('listGenres')->willReturn([['name' => 'Test Genre']]);
        $this->documentStore->method('listSeries')->willReturn([['name' => 'Test Series']]);

        $dirPath = '/Test Genre/Test Author/Test Series/1 Test Book';
        $result = $this->processDirPath($dirPath);

        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals('Test Author', $result['authors'][0]);
        $this->assertEquals(['Test Genre'], $result['genre']);
        // @phpstan-ignore-next-line
        $this->assertEquals(['Test Series' => '1'], $result['series']);
        // @phpstan-ignore-next-line
        $this->assertEquals(1, $result['series_number']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessDirPathWithoutSeries(): void
    {
        $this->documentStore->method('listGenres')->willReturn([['name' => 'Test Genre']]);

        $dirPath = '/Test Genre/Test Author/Test Book';
        $result = $this->processDirPath($dirPath);

        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals('Test Author', $result['authors'][0]);
        $this->assertEquals(['Test Genre'], $result['genre']);
        $this->assertArrayNotHasKey('series', $result);
        $this->assertArrayNotHasKey('series_number', $result);
    }

    #[Test]
    public function testImportBookFromPath(): void
    {
        Storage::fake('local');

        // Create test file
        $file = UploadedFile::fake()->create('test.mp3', 1024);
        $path = 'test/path/' . $file->getClientOriginalName();
        Storage::put($path, file_get_contents($file->getPathname()));

        // Create test data
        $bookData = [
            'title' => 'Test Book',
            'authors' => ['Test Author'],
            'genre' => 'Test Genre',
            'files' => [
                [
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                ],
            ],
        ];

        $this->documentStore->expects($this->once())
            ->method('createBook')
            ->willReturn('some-book-id');

        // Import the book
        $bookId = $this->importBookFromPath($bookData);

        $this->assertEquals('some-book-id', $bookId);
    }
}
