<?php

namespace Tests\Unit\Services;

use App\Models\Genre;
use App\Services\BookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceGenreValidationTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BookImportService::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itMapsInvalidGenresToValidPrimaryGenres(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Fiction', // Should map to "General Fiction"
        ];

        $audiobook = [
            'path' => '/test/path',
            'files' => [],
        ];

        $book = $this->service->createBookFromMetadata($metadata, $audiobook);

        $this->assertNotNull($book);
        $this->assertCount(1, $book->genres);
        $this->assertEquals('General Fiction', $book->genres->first()->name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itKeepsValidGenresUnchanged(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => 'Science Fiction', // Valid - should stay as-is
        ];

        $audiobook = [
            'path' => '/test/path',
            'files' => [],
        ];

        $book = $this->service->createBookFromMetadata($metadata, $audiobook);

        $this->assertNotNull($book);
        $this->assertCount(1, $book->genres);
        $this->assertEquals('Science Fiction', $book->genres->first()->name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itMapsMultipleGenresCorrectly(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => ['Fiction', 'Science Fiction & Fantasy', 'Adventure'], // Mix of valid and invalid
        ];

        $audiobook = [
            'path' => '/test/path',
            'files' => [],
        ];

        $book = $this->service->createBookFromMetadata($metadata, $audiobook);

        $this->assertNotNull($book);
        $this->assertGreaterThanOrEqual(1, $book->genres->count());

        // Check that genres were mapped to valid ones
        $genreNames = $book->genres->pluck('name')->toArray();
        $validGenres = [
            'Science Fiction',
            'Fantasy',
            'LitRPG',
            'Romance',
            'History',
            'Historical Fiction',
            'Non Fiction',
            'Religion',
            'Church',
            'Kids',
            'Action',
            'Classic',
            'Computer',
            'Western',
            'Horror',
            'Mystery',
            'Other',
            'Science',
            'General Fiction',
        ];

        foreach ($genreNames as $genreName) {
            $this->assertContains($genreName, $validGenres, "Genre '{$genreName}' is not a valid primary genre");
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSetsPrimaryGenreCorrectly(): void
    {
        $metadata = [
            'title' => 'Test Book',
            'author' => 'Test Author',
            'genre' => ['Science Fiction', 'Action'], // First should be primary
        ];

        $audiobook = [
            'path' => '/test/path',
            'files' => [],
        ];

        $book = $this->service->createBookFromMetadata($metadata, $audiobook);

        $this->assertNotNull($book);

        // Check that first genre is primary
        $primaryGenre = $book->genres()->wherePivot('is_primary', true)->first();
        $this->assertNotNull($primaryGenre);
        /** @var \App\Models\Genre $primaryGenre */
        $this->assertEquals('Science Fiction', $primaryGenre->name);

        // Check that second genre is not primary
        $secondaryGenres = $book->genres()->wherePivot('is_primary', false)->get();
        $this->assertCount(1, $secondaryGenres);
        $secondaryGenre = $secondaryGenres->first();
        /** @var \App\Models\Genre $secondaryGenre */
        $this->assertEquals('Action', $secondaryGenre->name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPreventsCreationOfInvalidGenreDirectories(): void
    {
        $invalidGenres = [
            'Biography & Autobiography',
            'Epic/Flintlock Fantasy',
            'Body, Mind & Spirit',
            'Boston (Mass.)',
        ];

        foreach ($invalidGenres as $invalidGenre) {
            $metadata = [
                'title' => 'Test Book',
                'author' => 'Test Author',
                'genre' => $invalidGenre,
            ];

            $audiobook = [
                'path' => '/test/path',
                'files' => [],
            ];

            $book = $this->service->createBookFromMetadata($metadata, $audiobook);

            $this->assertNotNull($book);

            // Verify the invalid genre was NOT created
            $createdGenre = Genre::where('name', $invalidGenre)->first();
            $this->assertNull($createdGenre, "Invalid genre '{$invalidGenre}' should not be created");

            // Verify a valid genre was created instead
            $this->assertCount(1, $book->genres);
            $validGenres = [
                'Science Fiction',
                'Fantasy',
                'LitRPG',
                'Romance',
                'History',
                'Historical Fiction',
                'Non Fiction',
                'Religion',
                'Church',
                'Kids',
                'Action',
                'Classic',
                'General Fiction',
                'Computer',
                'Western',
                'Horror',
                'Mystery',
                'Other',
                'Science',
            ];
            $this->assertContains($book->genres->first()->name, $validGenres);
        }
    }
}
