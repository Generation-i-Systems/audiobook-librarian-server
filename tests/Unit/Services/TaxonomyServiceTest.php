<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\TaxonomyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxonomyServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function searchAndLookupHelpersReturnMatchingTaxonomyRecords(): void
    {
        $service = new TaxonomyService();

        Author::query()->create(['name' => 'Arthur Author']);
        Narrator::query()->create(['name' => 'Nadia Narrator']);
        Genre::query()->create(['name' => 'Mystery']);
        $series = Series::query()->create(['name' => 'Moon Saga', 'is_collection' => false]);

        $this->assertSame('Moon Saga', $service->getSeriesByName('Moon Saga')['name']);
        $this->assertSame($series->id, $service->getSeries((string) $series->id)?->id);
        $this->assertSame('Arthur Author', $service->searchAuthorsByName('thur')[0]['name']);
        $this->assertSame('Nadia Narrator', $service->searchNarratorsByName('Nadia')[0]['name']);
        $this->assertSame(['Mystery'], $service->searchGenresByName('Myst'));
        $this->assertSame('Moon Saga', $service->searchSeriesByName('Moon')[0]['name']);
    }

    #[Test]
    public function createUpdateDeleteAndFindOrCreateHelpersManageTaxonomyRows(): void
    {
        $service = new TaxonomyService();

        $author = $service->createAuthor(['name' => 'Alpha Author']);
        $genre = $service->createGenre(['name' => 'Adventure']);
        $narrator = $service->createNarrator(['name' => 'Nora Voice']);
        $seriesId = $service->createSeries('Galaxy Trail', true);

        $this->assertTrue($service->updateAuthor((string) $author->id, ['name' => 'Beta Author']));
        $this->assertTrue($service->updateGenre((string) $genre->id, ['name' => 'Epic Adventure']));
        $this->assertTrue($service->updateSeries((int) $seriesId, ['name' => 'Galaxy Trail Updated']));

        $existingSeries = $service->findOrCreateSeriesByName('Galaxy Trail Updated');
        $newSeries = $service->findOrCreateSeriesByName('Fresh Series');

        $this->assertSame('Galaxy Trail Updated', $existingSeries['name']);
        $this->assertSame('Fresh Series', $newSeries['name']);

        $this->assertTrue($service->deleteNarrator((string) $narrator->id));
        $this->assertTrue($service->deleteSeries((string) $seriesId));
        $this->assertTrue($service->deleteGenre((string) $genre->id));

        $service->deleteAuthor((string) $author->id);

        $this->assertSoftDeleted('authors', ['id' => $author->id]);
        $this->assertSoftDeleted('genres', ['id' => $genre->id]);
        $this->assertSoftDeleted('narrators', ['id' => $narrator->id]);
        $this->assertSoftDeleted('series', ['id' => $seriesId]);
    }
}
