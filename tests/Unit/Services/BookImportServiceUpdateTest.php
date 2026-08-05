<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceUpdateTest extends TestCase
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

    private function createTestBook(array $overrides = []): Book
    {
        return Book::create(array_merge([
            'title' => 'Test Book',
            'directory_path' => 'Fiction/Test Author/Test Book',
            'language' => 'en',
        ], $overrides));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataPreservesExistingTitle(): void
    {
        $book = $this->createTestBook(['title' => 'Original Title']);

        $metadata = [
            'description' => 'New description',
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);

        $this->assertEquals('Original Title', $updated->title);
        $this->assertEquals('New description', $updated->description);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataUpdatesAuthors(): void
    {
        $book = $this->createTestBook();
        $oldAuthor = Author::create(['name' => 'Old Author']);
        $book->authors()->attach($oldAuthor);

        $metadata = [
            'author' => ['New Author', 'Second Author'],
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);
        $updated->refresh();

        $authorNames = $updated->authors->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['New Author', 'Second Author'], $authorNames);
        $this->assertNotContains('Old Author', $authorNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataNormalizesAuthorForUnreviewedCallers(): void
    {
        // updateBookFromMetadata() is the legacy entry point for callers that bypass
        // interactive review (jobs, admin tools, seed scripts) — it cleans up raw
        // author names same as createBookFromMetadata(). The reviewed import flow
        // uses persistConfirmedBookUpdate() instead, which does no normalization at
        // all — that cleanup happens before review (postProcessAIResult).
        $book = $this->createTestBook();

        $metadata = [
            'author' => ['Graphic Audio [Alex Archer]'],
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);
        $updated->refresh();

        $authorNames = $updated->authors->pluck('name')->toArray();
        $this->assertEquals(['Alex Archer'], $authorNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataDoesNotTrimSeries(): void
    {
        $book = $this->createTestBook();

        $metadata = [
            'series' => ' Mistborn ',
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);
        $updated->refresh();

        $seriesNames = $updated->series->pluck('name')->toArray();
        $this->assertEquals([' Mistborn '], $seriesNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataMapsPrimaryGenreForUnreviewedCallers(): void
    {
        // updateBookFromMetadata() is the legacy entry point for callers that bypass
        // interactive review — it maps the primary genre to a canonical library genre,
        // same as before. The reviewed import flow uses persistConfirmedBookUpdate()
        // instead, which never maps anything (see the test below).
        $book = $this->createTestBook();

        $metadata = [
            'genre' => ['Fiction'],
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);
        $updated->refresh();

        $genreNames = $updated->genres->pluck('name')->toArray();
        $this->assertEquals(['General Fiction'], $genreNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataSkipsSecondaryGenreMappingForUnreviewedCallers(): void
    {
        $book = $this->createTestBook();

        $metadata = [
            'genre' => ['Fantasy', 'Fiction'],
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);
        $updated->refresh();

        $genreNames = $updated->genres->pluck('name')->sort()->values()->toArray();
        $this->assertContains('Fantasy', $genreNames);
        $this->assertContains('Fiction', $genreNames);
        $this->assertNotContains('General Fiction', $genreNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function persistConfirmedBookUpdateUsesGenreExactlyAsConfirmedWithNoMapping(): void
    {
        // Confirmed data is used exactly as given — canonical-genre mapping happens
        // before the review screen (postProcessAIResult) and is enforced there by
        // reviewAndApprove() refusing to accept an invalid genre, never after
        // confirmation.
        $book = $this->createTestBook();

        $confirmed = \App\Support\ConfirmedBookMetadata::fromConfirmed(['genre' => ['Fiction']]);

        $updated = $this->service->persistConfirmedBookUpdate($book, $confirmed, []);
        $updated->refresh();

        $genreNames = $updated->genres->pluck('name')->toArray();
        $this->assertEquals(['Fiction'], $genreNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function persistConfirmedBookUpdateUsesAuthorExactlyAsConfirmedWithNoNormalization(): void
    {
        $book = $this->createTestBook();

        $confirmed = \App\Support\ConfirmedBookMetadata::fromConfirmed(['author' => ['Graphic Audio [Alex Archer]']]);

        $updated = $this->service->persistConfirmedBookUpdate($book, $confirmed, []);
        $updated->refresh();

        $authorNames = $updated->authors->pluck('name')->toArray();
        $this->assertEquals(['Graphic Audio [Alex Archer]'], $authorNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataUpdatesNarrators(): void
    {
        $book = $this->createTestBook();
        $oldNarrator = Narrator::create(['name' => 'Old Narrator']);
        $book->narrators()->attach($oldNarrator);

        $metadata = [
            'narrator' => ['Michael Kramer', 'Kate Reading'],
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);
        $updated->refresh();

        $narratorNames = $updated->narrators->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['Kate Reading', 'Michael Kramer'], $narratorNames);
        $this->assertNotContains('Old Narrator', $narratorNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataUpdatesSeriesWithNumber(): void
    {
        $book = $this->createTestBook();

        $metadata = [
            'series' => 'Mistborn',
            'series_number' => '2',
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);
        $updated->refresh();

        $this->assertCount(1, $updated->series);
        $this->assertEquals('Mistborn', $updated->series->first()->name);

        $pivot = \Illuminate\Support\Facades\DB::table('book_series')
            ->where('book_id', $updated->id)
            ->first();
        $this->assertEquals('2', $pivot->series_number);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookFromMetadataSetsPrimaryGenreFlag(): void
    {
        $book = $this->createTestBook();

        $metadata = [
            'genre' => ['Fantasy', 'Action'],
        ];

        $updated = $this->service->updateBookFromMetadata($book, $metadata, []);

        $genres = \Illuminate\Support\Facades\DB::table('book_genre')
            ->where('book_id', $updated->id)
            ->get();

        $fantasyGenre = Genre::where('name', 'Fantasy')->first();
        $actionGenre = Genre::where('name', 'Action')->first();

        $fantasyPivot = $genres->firstWhere('genre_id', $fantasyGenre->id);
        $actionPivot = $genres->firstWhere('genre_id', $actionGenre->id);

        $this->assertTrue((bool) $fantasyPivot->is_primary);
        $this->assertFalse((bool) $actionPivot->is_primary);
    }
}
