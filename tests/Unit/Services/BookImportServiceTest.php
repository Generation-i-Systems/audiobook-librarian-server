<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Services\BookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathIncludesSeriesNumberInTitle(): void
    {
        $metadata = [
            'title' => 'Willful Child',
            'author' => ['Steven Erikson'],
            'genre' => 'Science Fiction',
            'series' => 'Willful Child',
            'series_number' => 1,
        ];

        $path = $this->service->generateDirectoryPath($metadata, ['include_title' => true]);

        $this->assertStringContainsString('01 Willful Child', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathWithoutSeriesNumber(): void
    {
        $metadata = [
            'title' => 'Standalone Book',
            'author' => ['John Doe'],
            'genre' => 'Fiction',
        ];

        $path = $this->service->generateDirectoryPath($metadata, ['include_title' => true]);

        $this->assertStringContainsString('Standalone Book', $path);
        $this->assertStringNotContainsString('01', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathFormatsSeriesNumberWithPadding(): void
    {
        $metadata = [
            'title' => 'Book Nine',
            'author' => ['Jane Smith'],
            'genre' => 'Fantasy',
            'series' => 'Epic Series',
            'series_number' => 9,
        ];

        $path = $this->service->generateDirectoryPath($metadata, ['include_title' => true]);

        $this->assertStringContainsString('09 Book Nine', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateTargetDirectoryIncludesSeriesNumberFromBook(): void
    {
        // Create test data
        $author = Author::create(['name' => 'Steven Erikson']);
        $genre = Genre::create(['name' => 'Science Fiction']);
        $series = Series::create(['name' => 'Willful Child']);

        $book = Book::create([
            'title' => 'Willful Child',
            'directory_path' => 'Science Fiction/Steven Erikson/Willful Child',
            'language' => 'en',
        ]);

        $book->authors()->attach($author);
        $book->genres()->attach($genre);
        $book->series()->attach($series, ['series_number' => 1]);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateTargetDirectory');
        $method->setAccessible(true);

        $basePath = '/test/path';
        $targetPath = $method->invoke($this->service, $book, $basePath);

        $this->assertStringContainsString('01 Willful Child', $targetPath);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateTargetDirectoryWithoutSeriesNumber(): void
    {
        // Create test data
        $author = Author::create(['name' => 'John Doe']);
        $genre = Genre::create(['name' => 'Fiction']);

        $book = Book::create([
            'title' => 'Standalone Book',
            'directory_path' => 'Fiction/John Doe',
            'language' => 'en',
        ]);

        $book->authors()->attach($author);
        $book->genres()->attach($genre);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateTargetDirectory');
        $method->setAccessible(true);

        $basePath = '/test/path';
        $targetPath = $method->invoke($this->service, $book, $basePath);

        $this->assertStringContainsString('Standalone Book', $targetPath);
        $this->assertStringNotContainsString('01', $targetPath);
    }
}
