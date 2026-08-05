<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceCreateFromMetadataReuseTest extends TestCase
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
    public function reusingAnExistingBookWithNoStoredDirectoryKeepsTheConfirmedDirectoryPath(): void
    {
        $author = Author::create(['name' => 'Rosiee Thor']);
        $existingBook = Book::create([
            'title' => 'Aim To Misbehave',
            'directory_path' => '', // orphaned/stale record with no stored directory
            'language' => 'en',
        ]);
        $existingBook->authors()->attach($author->id);

        $confirmedDirectory = 'Science Fiction/VA/Firefly/09 Aim To Misbehave (Rosiee Thor)';
        $metadata = [
            'title' => 'Aim To Misbehave',
            'author' => ['Rosiee Thor'],
            'genre' => 'Science Fiction',
            'custom_directory_path' => $confirmedDirectory,
        ];
        $audiobook = ['path' => '/media/lyra_data/download/Firefly [09] Aim To Misbehave', 'files' => []];

        $result = $this->service->createBookFromMetadata($metadata, $audiobook);

        $this->assertNotNull($result);
        $this->assertSame($existingBook->id, $result->id, 'Should reuse the matched existing book, not create a duplicate');
        $this->assertSame($confirmedDirectory, $result->directory_path, 'The user-confirmed directory must win over any freshly re-derived one');
    }
}
