<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(\App\Services\SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWithOnlyLibrarianJson(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with only librarian.json
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/librarian.json', '{"title": "Test Book"}');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWithOnlyCoverImage(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with only cover image
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/cover.jpg', 'cover content');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWhenTargetHasAudioFilesAllowingMerge(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with audio files
            // With the new merge/overwrite behavior, we reuse the directory
            // and let file-level operations handle conflicts
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/track01.mp3', 'existing audio');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryWithMixedMetadataFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with multiple metadata files
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/librarian.json', '{"title": "Test"}');
            file_put_contents($targetDir . '/cover.jpg', 'cover content');
            file_put_contents($targetDir . '/cover.png', 'png cover');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReusesDirectoryForNonMetadataFilesAllowingMerge(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Create target directory with non-metadata file
            // With the new merge/overwrite behavior, we reuse the directory
            // instead of creating suffixed copies that caused "Wrong Directory" issues
            $targetDir = $tempDir . '/target';
            mkdir($targetDir, 0775, true);
            file_put_contents($targetDir . '/readme.txt', 'not metadata');

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('unsortedPathProvider')]
    public function postProcessAiResultDoesNotUseUnsortedAsSeriesName(string $path): void
    {
        $aiResult = [
            'title' => 'The Great Book',
            'author' => ['John Smith'],
            'genre' => ['Fiction'],
            'series' => '',
        ];

        $audiobook = ['path' => $path];

        $result = $this->service->postProcessAIResult($aiResult, $audiobook);

        $this->assertNotEquals('unsorted', $result['series'] ?? '');
    }

    public static function unsortedPathProvider(): array
    {
        return [
            'media audiobooks unsorted' => ['/media/audiobooks/unsorted/The Great Book'],
            'media lyra_data1 unsorted' => ['/media/lyra_data1/audiobooks/unsorted/The Great Book'],
            'downloads path' => ['/media/downloads/The Great Book'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAiResultUsesValidParentAsSeriesName(): void
    {
        $aiResult = [
            'title' => 'Book One',
            'author' => ['Jane Doe'],
            'genre' => ['Fantasy'],
            'series' => '',
        ];

        $audiobook = ['path' => '/media/audiobooks/Jane Doe/Awesome Series/Book One'];

        $result = $this->service->postProcessAIResult($aiResult, $audiobook);

        $this->assertEquals('Awesome Series', $result['series']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAiResultDiscardsNumericSeriesName(): void
    {
        $aiResult = [
            'title' => 'Some Book',
            'author' => ['Author Name'],
            'genre' => ['Fiction'],
            'series' => '11',
            'confidence' => 80,
        ];

        $audiobook = ['path' => '/media/audiobooks/Author Name/11/Some Book'];

        $result = $this->service->postProcessAIResult($aiResult, $audiobook);

        $this->assertArrayNotHasKey('series', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function postProcessAiResultDoesNotUseNumericParentAsSeries(): void
    {
        $aiResult = [
            'title' => 'Some Book',
            'author' => ['Author Name'],
            'genre' => ['Fiction'],
            'series' => '',
            'confidence' => 80,
        ];

        $audiobook = ['path' => '/media/audiobooks/Author Name/42/Some Book'];

        $result = $this->service->postProcessAIResult($aiResult, $audiobook);

        $this->assertNotEquals('42', $result['series'] ?? '');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adjustConfidenceBoostsForSeriesMatch(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);
        $series = Series::create(['name' => 'Mistborn']);
        $genre = Genre::create(['name' => 'Fantasy']);

        $book = Book::create([
            'title' => 'The Final Empire',
            'directory_path' => 'Fantasy/Brandon Sanderson/Mistborn/The Final Empire',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => 1]);
        $book->genres()->attach($genre);

        $metadata = [
            'title' => 'The Well of Ascension',
            'author' => ['Brandon Sanderson'],
            'series' => 'Mistborn',
            'genre' => 'Fantasy',
            'confidence' => 80,
        ];

        $this->service->adjustConfidence($metadata);

        $this->assertEquals(90, $metadata['confidence']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adjustConfidenceReducesForOtherGenre(): void
    {
        $metadata = [
            'title' => 'Some Book',
            'author' => ['Unknown Author'],
            'genre' => 'Other',
            'confidence' => 80,
        ];

        $this->service->adjustConfidence($metadata);

        $this->assertEquals(50, $metadata['confidence']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adjustConfidenceReducesForUnknownGenre(): void
    {
        $metadata = [
            'title' => 'Some Book',
            'author' => ['Unknown Author'],
            'genre' => 'Unknown',
            'confidence' => 80,
        ];

        $this->service->adjustConfidence($metadata);

        $this->assertEquals(50, $metadata['confidence']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adjustConfidenceReducesForActionWithNoAuthorHistory(): void
    {
        $metadata = [
            'title' => 'Action Book',
            'author' => ['New Author'],
            'genre' => 'Action',
            'confidence' => 80,
        ];

        $this->service->adjustConfidence($metadata);

        $this->assertEquals(60, $metadata['confidence']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adjustConfidenceReplacesActionWithAuthorPreferredGenre(): void
    {
        $author = Author::create(['name' => 'SciFi Author']);
        $sciFi = Genre::create(['name' => 'Science Fiction']);

        for ($i = 1; $i <= 2; $i++) {
            $book = Book::create([
                'title' => "SciFi Book {$i}",
                'directory_path' => "Science Fiction/SciFi Author/SciFi Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author);
            $book->genres()->attach($sciFi);
        }

        $metadata = [
            'title' => 'New Book',
            'author' => ['SciFi Author'],
            'genre' => 'Action',
            'confidence' => 80,
        ];

        $this->service->adjustConfidence($metadata);

        $this->assertEquals(80, $metadata['confidence']);
        $this->assertEquals('Science Fiction', $metadata['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adjustConfidenceInheritsGenreFromSeriesBooks(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);
        $series = Series::create(['name' => 'Mistborn']);
        $genre = Genre::create(['name' => 'Fantasy']);

        $book = Book::create([
            'title' => 'The Final Empire',
            'directory_path' => 'Fantasy/Brandon Sanderson/Mistborn/The Final Empire',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => 1]);
        $book->genres()->attach($genre, ['is_primary' => true]);

        $metadata = [
            'title' => 'The Well of Ascension',
            'author' => ['Brandon Sanderson'],
            'series' => 'Mistborn',
            'genre' => 'Other',
            'confidence' => 80,
        ];

        $this->service->adjustConfidence($metadata);

        $this->assertEquals('Fantasy', $metadata['genre']);
        $this->assertEquals(90, $metadata['confidence']); // +10 series boost, no -30 penalty
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adjustConfidenceAppliesPenaltyWhenNoSeriesGenreMatch(): void
    {
        $metadata = [
            'title' => 'Some Book',
            'author' => ['Unknown Author'],
            'series' => 'Nonexistent Series',
            'genre' => 'Other',
            'confidence' => 80,
        ];

        $this->service->adjustConfidence($metadata);

        $this->assertEquals(50, $metadata['confidence']); // -30 penalty
        $this->assertEquals('Other', $metadata['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function adjustConfidenceIgnoresOtherUnknownSeriesGenre(): void
    {
        $author = Author::create(['name' => 'Test Author']);
        $series = Series::create(['name' => 'Test Series']);
        $otherGenre = Genre::create(['name' => 'Other']);

        $book = Book::create([
            'title' => 'Existing Book',
            'directory_path' => 'Other/Test Author/Test Series/Existing Book',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => 1]);
        $book->genres()->attach($otherGenre, ['is_primary' => true]);

        $metadata = [
            'title' => 'New Book',
            'author' => ['Test Author'],
            'series' => 'Test Series',
            'genre' => 'Unknown',
            'confidence' => 80,
        ];

        $this->service->adjustConfidence($metadata);

        $this->assertEquals(60, $metadata['confidence']); // +10 series boost, -30 penalty
        $this->assertEquals('Unknown', $metadata['genre']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildMergeMetadataAutoAcceptsMatchingFields(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);
        $genre = Genre::create(['name' => 'Fantasy']);
        $narrator = Narrator::create(['name' => 'Michael Kramer']);
        $series = Series::create(['name' => 'Mistborn']);

        $book = Book::create([
            'title' => 'The Final Empire',
            'directory_path' => 'Fantasy/Brandon Sanderson/Mistborn/The Final Empire',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->genres()->attach($genre);
        $book->narrators()->attach($narrator);
        $book->series()->attach($series, ['series_number' => 1]);

        $newMetadata = [
            'title' => 'The Final Empire',
            'author' => ['Brandon Sanderson'],
            'narrator' => ['Michael Kramer'],
            'series' => 'Mistborn',
            'series_number' => '1',
            'genre' => ['Fantasy'],
            'language' => 'en',
            'description' => '',
            'isbn' => '',
            'year' => '',
        ];

        $selectCalled = false;
        $result = $this->service->buildMergeMetadata(
            $book,
            $newMetadata,
            function () use (&$selectCalled) {
                $selectCalled = true;
                return '1';
            },
            fn ($q, $d) => $d,
            fn ($msg) => null
        );

        $this->assertNotNull($result);
        $this->assertFalse($selectCalled);
        $this->assertEquals('The Final Empire', $result['title']);
        $this->assertEquals(['Brandon Sanderson'], $result['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildMergeMetadataFillsMissingExistingFields(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);

        $book = Book::create([
            'title' => 'The Final Empire',
            'directory_path' => 'Fantasy/Brandon Sanderson/The Final Empire',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);

        $newMetadata = [
            'title' => 'The Final Empire',
            'author' => ['Brandon Sanderson'],
            'narrator' => ['Michael Kramer'],
            'series' => 'Mistborn',
            'series_number' => '1',
            'genre' => ['Fantasy'],
            'language' => 'en',
            'description' => 'A great book',
            'isbn' => '1234567890',
            'year' => '2006',
        ];

        $result = $this->service->buildMergeMetadata(
            $book,
            $newMetadata,
            fn ($q, $o, $d) => $d,
            fn ($q, $d) => $d,
            fn ($msg) => null
        );

        $this->assertNotNull($result);
        $this->assertEquals('Mistborn', $result['series']);
        $this->assertEquals(['Michael Kramer'], $result['narrator']);
        $this->assertEquals('A great book', $result['description']);
        $this->assertEquals('1234567890', $result['isbn']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildMergeMetadataReturnsNullWhenUserCancels(): void
    {
        $author = Author::create(['name' => 'Author A']);
        $genre = Genre::create(['name' => 'Fantasy']);

        $book = Book::create([
            'title' => 'Book Title A',
            'directory_path' => 'Fantasy/Author A/Book Title A',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->genres()->attach($genre);

        $newMetadata = [
            'title' => 'Book Title B',
            'author' => ['Author A'],
            'narrator' => [],
            'series' => '',
            'series_number' => '',
            'genre' => ['Fantasy'],
            'language' => 'en',
            'description' => '',
            'isbn' => '',
            'year' => '',
        ];

        $result = $this->service->buildMergeMetadata(
            $book,
            $newMetadata,
            fn ($q, $o, $d) => '4',
            fn ($q, $d) => $d,
            fn ($msg) => null
        );

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsOriginalWhenDirectoryDoesNotExist(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_import_' . uniqid();

        try {
            // Don't create the target directory
            $targetDir = $tempDir . '/nonexistent';

            $audiobook = ['path' => $tempDir . '/source'];

            $result = $this->service->handleDirectoryConflict($audiobook, $targetDir);

            $this->assertSame($targetDir, $result);
        } finally {
            if (is_dir($tempDir)) {
                exec("rm -rf {$tempDir}");
            }
        }
    }
}
