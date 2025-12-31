<?php

namespace Tests\Core\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Services\MetadataProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetadataProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MetadataProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MetadataProcessingService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreReturnsNullForNonExistentAuthor(): void
    {
        $result = $this->service->getAuthorPreferredGenre('Non Existent Author');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreReturnsNullWhenAuthorHasNoBooks(): void
    {
        Author::create(['name' => 'New Author']);

        $result = $this->service->getAuthorPreferredGenre('New Author');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreReturnsNullWhenAuthorHasLessThanTwoInSameGenre(): void
    {
        $author = Author::create(['name' => 'Test Author']);
        $genre1 = Genre::create(['name' => 'Science Fiction']);
        $genre2 = Genre::create(['name' => 'Fantasy']);

        $book1 = Book::create([
            'title' => 'Book 1',
            'directory_path' => 'Science Fiction/Test Author/Book 1',
            'language' => 'en',
        ]);
        $book1->authors()->attach($author);
        $book1->genres()->attach($genre1);

        $book2 = Book::create([
            'title' => 'Book 2',
            'directory_path' => 'Fantasy/Test Author/Book 2',
            'language' => 'en',
        ]);
        $book2->authors()->attach($author);
        $book2->genres()->attach($genre2);

        $result = $this->service->getAuthorPreferredGenre('Test Author');

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreReturnsMostCommonGenre(): void
    {
        $author = Author::create(['name' => 'David Weber']);
        $sciFi = Genre::create(['name' => 'Science Fiction']);
        $fantasy = Genre::create(['name' => 'Fantasy']);

        // Create 3 Science Fiction books
        for ($i = 1; $i <= 3; $i++) {
            $book = Book::create([
                'title' => "Sci-Fi Book {$i}",
                'directory_path' => "Science Fiction/David Weber/Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author);
            $book->genres()->attach($sciFi);
        }

        // Create 1 Fantasy book
        $book = Book::create([
            'title' => 'Fantasy Book',
            'directory_path' => 'Fantasy/David Weber/Fantasy Book',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->genres()->attach($fantasy);

        $result = $this->service->getAuthorPreferredGenre('David Weber');

        $this->assertEquals('Science Fiction', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreHandlesCommaSeparatedAuthors(): void
    {
        $author1 = Author::create(['name' => 'David Weber']);
        $author2 = Author::create(['name' => 'Chris Kennedy']);
        $sciFi = Genre::create(['name' => 'Science Fiction']);

        // Create 2 Science Fiction books for David Weber
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::create([
                'title' => "Book {$i}",
                'directory_path' => "Science Fiction/David Weber/Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author1);
            $book->genres()->attach($sciFi);
        }

        // Test with comma-separated author string
        $result = $this->service->getAuthorPreferredGenre('David Weber, Chris Kennedy');

        $this->assertEquals('Science Fiction', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreHandlesAuthorArray(): void
    {
        $author = Author::create(['name' => 'Jane Smith']);
        $fantasy = Genre::create(['name' => 'Fantasy']);

        // Create 2 Fantasy books
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::create([
                'title' => "Fantasy Book {$i}",
                'directory_path' => "Fantasy/Jane Smith/Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author);
            $book->genres()->attach($fantasy);
        }

        $result = $this->service->getAuthorPreferredGenre(['Jane Smith']);

        $this->assertEquals('Fantasy', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAiResultAppliesAuthorPreferredGenreWhenGenreIsOther(): void
    {
        $author = Author::create(['name' => 'Test Author']);
        $sciFi = Genre::create(['name' => 'Science Fiction']);

        // Create 2 Science Fiction books
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::create([
                'title' => "Book {$i}",
                'directory_path' => "Science Fiction/Test Author/Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author);
            $book->genres()->attach($sciFi);
        }

        $aiResult = [
            'title' => 'New Book',
            'author' => ['Test Author'],
            'genre' => ['Other'],
        ];

        $audiobook = ['path' => '/test/path'];

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('postProcessAIResult');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $aiResult, $audiobook);

        $this->assertEquals(['Science Fiction'], $result['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAiResultAppliesAuthorPreferredGenreWhenGenreIsAudiobook(): void
    {
        $author = Author::create(['name' => 'Mystery Author']);
        $mystery = Genre::create(['name' => 'Mystery']);

        // Create 2 Mystery books
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::create([
                'title' => "Mystery Book {$i}",
                'directory_path' => "Mystery/Mystery Author/Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author);
            $book->genres()->attach($mystery);
        }

        $aiResult = [
            'title' => 'New Mystery',
            'author' => ['Mystery Author'],
            'genre' => ['Audiobook'],
        ];

        $audiobook = ['path' => '/test/path'];

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('postProcessAIResult');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $aiResult, $audiobook);

        $this->assertEquals(['Mystery'], $result['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAiResultDoesNotOverrideValidGenre(): void
    {
        $author = Author::create(['name' => 'Test Author']);
        $sciFi = Genre::create(['name' => 'Science Fiction']);

        // Create 2 Science Fiction books
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::create([
                'title' => "Book {$i}",
                'directory_path' => "Science Fiction/Test Author/Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author);
            $book->genres()->attach($sciFi);
        }

        $aiResult = [
            'title' => 'Fantasy Book',
            'author' => ['Test Author'],
            'genre' => ['Fantasy'],
        ];

        $audiobook = ['path' => '/test/path'];

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('postProcessAIResult');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $aiResult, $audiobook);

        // Should keep Fantasy, not replace with Science Fiction
        $this->assertEquals(['Fantasy'], $result['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleCleansBookNumberSuffix(): void
    {
        $metadata = [
            'title' => 'The Great Adventure, Book 5',
        ];

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractSeriesNumberFromTitle');
        $method->setAccessible(true);

        $method->invokeArgs($this->service, [&$metadata]);

        $this->assertEquals('The Great Adventure', $metadata['title']);
        $this->assertEquals(5, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleHandlesVolumePattern(): void
    {
        $metadata = [
            'title' => 'Epic Series Volume 12',
        ];

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractSeriesNumberFromTitle');
        $method->setAccessible(true);

        $method->invokeArgs($this->service, [&$metadata]);

        $this->assertEquals('Epic Series', $metadata['title']);
        $this->assertEquals(12, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleStripsBracketedSeriesSuffixAndQualifiers(): void
    {
        $metadata = [
            'title' => 'Lover at Last [The Black Dagger Brotherhood #11] (Unabridged)',
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractSeriesNumberFromTitle');
        $method->setAccessible(true);

        $method->invokeArgs($this->service, [&$metadata]);

        $this->assertEquals('Lover at Last', $metadata['title']);
        $this->assertEquals('The Black Dagger Brotherhood', $metadata['series']);
        $this->assertEquals(11, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleSupportsDecimalSeriesNumbers(): void
    {
        $metadata = [
            'title' => 'Some Novella [Example Series #16.5] (Unabridged)',
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractSeriesNumberFromTitle');
        $method->setAccessible(true);

        $method->invokeArgs($this->service, [&$metadata]);

        $this->assertEquals('Some Novella', $metadata['title']);
        $this->assertEquals('Example Series', $metadata['series']);
        $this->assertEquals(16.5, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extractSeriesNumberFromTitleSanitizesTitleWhenSeriesNumberAlreadySet(): void
    {
        $metadata = [
            'title' => 'Lover at Last [The Black Dagger Brotherhood #11] (Unabridged)',
            'series_number' => 11,
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractSeriesNumberFromTitle');
        $method->setAccessible(true);

        $method->invokeArgs($this->service, [&$metadata]);

        $this->assertEquals('Lover at Last', $metadata['title']);
        $this->assertEquals('The Black Dagger Brotherhood', $metadata['series']);
        $this->assertEquals(11, $metadata['series_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function detectMultiBookPatternRecognizesBooksRange(): void
    {
        $title = 'Honor Harrington Books 1-3';

        $result = $this->service->detectMultiBookPattern($title);

        $this->assertIsArray($result);
        $this->assertEquals('books', $result['type']);
        $this->assertEquals('Honor Harrington', $result['series_name']);
        $this->assertEquals(1, $result['start_number']);
        $this->assertEquals(3, $result['end_number']);
        $this->assertEquals(3, $result['count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function detectMultiBookPatternRecognizesVolumesRange(): void
    {
        $title = 'The Expanse Volumes 4-6';

        $result = $this->service->detectMultiBookPattern($title);

        $this->assertIsArray($result);
        $this->assertEquals('volumes', $result['type']);
        $this->assertEquals('The Expanse', $result['series_name']);
        $this->assertEquals(4, $result['start_number']);
        $this->assertEquals(6, $result['end_number']);
        $this->assertEquals(3, $result['count']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function detectMultiBookPatternReturnsNullForNonMultiBookTitle(): void
    {
        $title = 'Single Book Title';

        $result = $this->service->detectMultiBookPattern($title);

        $this->assertNull($result);
    }
}
