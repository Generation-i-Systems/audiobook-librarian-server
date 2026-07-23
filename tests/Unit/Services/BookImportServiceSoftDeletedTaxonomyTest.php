<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceSoftDeletedTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBookFromMetadataRestoresSoftDeletedAuthorInsteadOfFailingUniqueConstraint(): void
    {
        $author = Author::create(['name' => 'Soft Deleted Author']);
        $author->delete();
        $this->assertTrue($author->fresh()->trashed());

        $book = $this->service->createBookFromMetadata(
            ['title' => 'Some Book', 'author' => ['Soft Deleted Author'], 'genre' => 'Other'],
            ['path' => sys_get_temp_dir() . '/nonexistent-source']
        );

        $this->assertNotNull($book);
        $this->assertSame('Soft Deleted Author', $book->authors->first()->name);
        $this->assertFalse($author->fresh()->trashed());
        $this->assertSame(1, Author::withTrashed()->where('name', 'Soft Deleted Author')->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBookFromMetadataRestoresSoftDeletedNarratorInsteadOfFailingUniqueConstraint(): void
    {
        $narrator = Narrator::create(['name' => 'Soft Deleted Narrator']);
        $narrator->delete();

        $book = $this->service->createBookFromMetadata(
            ['title' => 'Some Book', 'author' => ['Some Author'], 'narrator' => ['Soft Deleted Narrator'], 'genre' => 'Other'],
            ['path' => sys_get_temp_dir() . '/nonexistent-source']
        );

        $this->assertNotNull($book);
        $this->assertSame('Soft Deleted Narrator', $book->narrators->first()->name);
        $this->assertSame(1, Narrator::withTrashed()->where('name', 'Soft Deleted Narrator')->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBookFromMetadataRestoresSoftDeletedSeriesInsteadOfFailingUniqueConstraint(): void
    {
        $series = Series::create(['name' => 'Soft Deleted Series']);
        $series->delete();

        $book = $this->service->createBookFromMetadata(
            ['title' => 'Some Book', 'author' => ['Some Author'], 'series' => 'Soft Deleted Series', 'series_number' => 1, 'genre' => 'Other'],
            ['path' => sys_get_temp_dir() . '/nonexistent-source']
        );

        $this->assertNotNull($book);
        $this->assertSame('Soft Deleted Series', $book->series->first()->name);
        $this->assertSame(1, Series::withTrashed()->where('name', 'Soft Deleted Series')->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBookFromMetadataRestoresSoftDeletedGenreInsteadOfFailingUniqueConstraint(): void
    {
        $genre = Genre::create(['name' => 'LitRPG']);
        $genre->delete();

        $book = $this->service->createBookFromMetadata(
            ['title' => 'Some Book', 'author' => ['Some Author'], 'genre' => 'LitRPG'],
            ['path' => sys_get_temp_dir() . '/nonexistent-source']
        );

        $this->assertNotNull($book);
        $this->assertSame(1, Genre::withTrashed()->where('name', 'LitRPG')->count());
    }
}
