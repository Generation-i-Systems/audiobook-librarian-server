<?php

namespace Tests\Core\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceTest extends TestCase
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

    private function createTempDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '_' . uniqid('', true);
        File::makeDirectory($path, 0775, true);

        return $path;
    }

    private function createTempBook(): Book
    {
        return Book::create([
            'title' => 'Test Book',
            'directory_path' => 'Test/Path',
            'language' => 'en',
        ]);
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
    public function generateDirectoryPathFormatsDecimalSeriesNumberWithPadding(): void
    {
        $metadata = [
            'title' => 'Side Story',
            'author' => ['Jane Smith'],
            'genre' => 'Fantasy',
            'series' => 'Epic Series',
            'series_number' => 16.5,
        ];

        $path = $this->service->generateDirectoryPath($metadata, ['include_title' => true]);

        $this->assertStringContainsString('16.5 Side Story', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathUsesCustomDirectoryPathWhenRelative(): void
    {
        $metadata = [
            'custom_directory_path' => 'Fantasy/Mark Waid, Alex Ross/Kingdom Come',
            'title' => 'Ignored Title',
            'author' => ['Ignored Author'],
            'genre' => 'Ignored Genre',
        ];

        $path = $this->service->generateDirectoryPath($metadata, ['include_title' => true]);

        $this->assertSame('Fantasy/Mark Waid, Alex Ross/Kingdom Come', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathStripsBookRootFromCustomDirectoryPathWhenAbsolute(): void
    {
        config(['app.book_root' => '/media/lyra_data1/audiobooks/books']);

        $metadata = [
            'custom_directory_path' => '/media/lyra_data1/audiobooks/books/Fantasy/Mark Waid, Alex Ross/Kingdom Come',
            'title' => 'Ignored Title',
            'author' => ['Ignored Author'],
            'genre' => 'Ignored Genre',
        ];

        $path = $this->service->generateDirectoryPath($metadata, ['include_title' => true]);

        $this->assertSame('Fantasy/Mark Waid, Alex Ross/Kingdom Come', $path);
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
            'directory_path' => null, // Ensure it's generated from metadata
            'language' => 'en',
        ]);

        $book->authors()->attach($author);
        $book->genres()->attach($genre);
        // Explicitly attach with the pivot data that the generator expects
        $book->series()->attach($series, ['series_number' => '1']);

        $book->refresh(); // Load relationships

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
            'directory_path' => null, // Ensure it's generated from metadata
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreHandlesCommaSeparatedAuthorString(): void
    {
        $author1 = Author::create(['name' => 'First Author']);
        $author2 = Author::create(['name' => 'Second Author']);
        $genre = Genre::create(['name' => 'Fantasy']);

        // Create 2 Fantasy books for First Author
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::create([
                'title' => "Fantasy Book {$i}",
                'directory_path' => "Fantasy/First Author/Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author1);
            $book->genres()->attach($genre);
        }

        // Test with comma-separated string (as it comes from audio file metadata)
        $result = $this->service->getAuthorPreferredGenre(['First Author, Second Author']);

        $this->assertEquals('Fantasy', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreReturnsNullForAuthorWithOnlyOneBook(): void
    {
        $author = Author::create(['name' => 'New Author']);
        $genre = Genre::create(['name' => 'Mystery']);

        $book = Book::create([
            'title' => 'Single Book',
            'directory_path' => 'Mystery/New Author/Single Book',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->genres()->attach($genre);

        $result = $this->service->getAuthorPreferredGenre('New Author');

        // Should return null because author needs at least 2 books in same genre
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAuthorPreferredGenreFindsSecondAuthorInCommaSeparatedList(): void
    {
        $author1 = Author::create(['name' => 'Unknown Author']);
        $author2 = Author::create(['name' => 'Famous Author']);
        $genre = Genre::create(['name' => 'Thriller']);

        // Create 2 Thriller books for Famous Author (second in list)
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::create([
                'title' => "Thriller Book {$i}",
                'directory_path' => "Thriller/Famous Author/Book {$i}",
                'language' => 'en',
            ]);
            $book->authors()->attach($author2);
            $book->genres()->attach($genre);
        }

        // Test with comma-separated string where second author has the history
        $result = $this->service->getAuthorPreferredGenre(['Unknown Author, Famous Author']);

        $this->assertEquals('Thriller', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findMatchingPdfFileReturnsNullWhenNoPdfExists(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_' . uniqid();
        mkdir($tempDir);

        $audioFile = $tempDir . '/audiobook.m4b';
        touch($audioFile);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('findMatchingPdfFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $audioFile);

        $this->assertNull($result);

        unlink($audioFile);
        rmdir($tempDir);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findMatchingPdfFileReturnsPdfPathWhenExists(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_' . uniqid();
        mkdir($tempDir);

        $audioFile = $tempDir . '/audiobook.m4b';
        $pdfFile = $tempDir . '/audiobook.pdf';
        touch($audioFile);
        touch($pdfFile);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('findMatchingPdfFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $audioFile);

        $this->assertEquals($pdfFile, $result);

        unlink($audioFile);
        unlink($pdfFile);
        rmdir($tempDir);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findMatchingPdfFileHandlesDifferentAudioExtensions(): void
    {
        $tempDir = sys_get_temp_dir() . '/test_' . uniqid();
        mkdir($tempDir);

        $audioFile = $tempDir . '/book.mp3';
        $pdfFile = $tempDir . '/book.pdf';
        touch($audioFile);
        touch($pdfFile);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('findMatchingPdfFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $audioFile);

        $this->assertEquals($pdfFile, $result);

        unlink($audioFile);
        unlink($pdfFile);
        rmdir($tempDir);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function moveFilesToLibraryReturnsFalseWhenDestinationHasNoAudioFiles(): void
    {
        $sourceDir = $this->createTempDirectory('book_import_source');
        $targetDir = $this->createTempDirectory('book_import_target');

        try {
            file_put_contents($sourceDir . '/notes.txt', 'not audio');

            $book = $this->createTempBook();

            $audiobook = [
                'path' => $sourceDir,
                'files' => [$sourceDir . '/notes.txt'],
            ];

            $result = $this->service->moveFilesToLibrary($audiobook, $book, [
                'operation' => 'copy',
                'target_directory' => $targetDir,
            ]);

            $this->assertFalse($result);
        } finally {
            File::deleteDirectory($sourceDir);
            File::deleteDirectory($targetDir);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function moveFilesToLibraryReusesExistingDirectoryWhenOnlyCoverExists(): void
    {
        $sourceDir = $this->createTempDirectory('book_import_source');
        $targetDir = $this->createTempDirectory('book_import_target');

        try {
            file_put_contents($sourceDir . '/track01.mp3', 'audio');
            file_put_contents($targetDir . '/cover.jpg', 'cover');

            $book = $this->createTempBook();

            $audiobook = [
                'path' => $sourceDir,
                'files' => [$sourceDir . '/track01.mp3'],
            ];

            $result = $this->service->moveFilesToLibrary($audiobook, $book, [
                'operation' => 'copy',
                'target_directory' => $targetDir,
            ]);

            $this->assertTrue($result);
            $this->assertFileExists($targetDir . '/cover.jpg');
            $this->assertFileExists($targetDir . '/track01.mp3');
            $this->assertDirectoryDoesNotExist($targetDir . '_01');
        } finally {
            File::deleteDirectory($sourceDir);
            File::deleteDirectory($targetDir);
            File::deleteDirectory($targetDir . '_01');
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function moveFilesToLibraryReturnsTrueWhenDestinationHasAudioFiles(): void
    {
        $sourceDir = $this->createTempDirectory('book_import_source');
        $targetDir = $this->createTempDirectory('book_import_target');

        try {
            file_put_contents($sourceDir . '/track01.mp3', 'audio');

            $book = $this->createTempBook();

            $audiobook = [
                'path' => $sourceDir,
                'files' => [$sourceDir . '/track01.mp3'],
            ];

            $result = $this->service->moveFilesToLibrary($audiobook, $book, [
                'operation' => 'copy',
                'target_directory' => $targetDir,
            ]);

            $this->assertTrue($result);
        } finally {
            File::deleteDirectory($sourceDir);
            File::deleteDirectory($targetDir);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function moveFilesToLibraryInPlaceReturnsFalseWhenSourceDirectoryHasNoAudioFiles(): void
    {
        $sourceDir = $this->createTempDirectory('book_import_source');

        try {
            file_put_contents($sourceDir . '/readme.txt', 'not audio');

            $book = $this->createTempBook();

            $audiobook = [
                'path' => $sourceDir,
                'files' => [$sourceDir . '/readme.txt'],
            ];

            $result = $this->service->moveFilesToLibrary($audiobook, $book, [
                'operation' => 'move',
                'target_directory' => $sourceDir,
            ]);

            $this->assertFalse($result);
        } finally {
            File::deleteDirectory($sourceDir);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function moveFilesToLibraryInPlaceReturnsTrueWhenSourceDirectoryHasAudioFiles(): void
    {
        $sourceDir = $this->createTempDirectory('book_import_source');

        try {
            file_put_contents($sourceDir . '/book.m4b', 'audio');

            $book = $this->createTempBook();

            $audiobook = [
                'path' => $sourceDir,
                'files' => [$sourceDir . '/book.m4b'],
            ];

            $result = $this->service->moveFilesToLibrary($audiobook, $book, [
                'operation' => 'move',
                'target_directory' => $sourceDir,
            ]);

            $this->assertTrue($result);
        } finally {
            File::deleteDirectory($sourceDir);
        }
    }
}
