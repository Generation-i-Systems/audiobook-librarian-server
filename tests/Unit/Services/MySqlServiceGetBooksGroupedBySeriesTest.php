<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Series;
use App\Services\MySqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MySqlServiceGetBooksGroupedBySeriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function getBooksGroupedBySeriesReturnsEmptyResultWhenNoSeriesAssigned(): void
    {
        Book::factory()->create(['title' => 'Standalone']);

        $service = new MySqlService();

        $this->assertSame(['data' => [], 'total' => 0], $service->getBooksGroupedBySeries());
    }

    #[Test]
    public function getBooksGroupedBySeriesGroupsBooksByNameAndSortsAlphabetically(): void
    {
        $zSeries = Series::factory()->create(['name' => 'Zeta Series']);
        $aSeries = Series::factory()->create(['name' => 'Alpha Series']);
        $author = Author::factory()->create(['name' => 'Jane Doe']);

        $bookOne = Book::factory()->create([
            'title' => 'Zeta Book One',
            'directory_path' => 'Zeta/Book One',
            'audio_file_count' => 3,
        ]);
        $bookOne->series()->attach($zSeries->id, ['series_number' => 1]);
        $bookOne->authors()->attach($author->id);

        $bookTwo = Book::factory()->create([
            'title' => 'Alpha Book One',
            'directory_path' => 'Alpha/Book One',
            'audio_file_count' => 2,
        ]);
        $bookTwo->series()->attach($aSeries->id, ['series_number' => 1]);

        $service = new MySqlService();

        $result = $service->getBooksGroupedBySeries();

        $this->assertSame(2, $result['total']);
        $this->assertSame(['Alpha Series', 'Zeta Series'], array_keys($result['data']));
        $this->assertCount(1, $result['data']['Zeta Series']);

        $zetaBook = $result['data']['Zeta Series'][0];
        $this->assertSame((string) $bookOne->id, $zetaBook['_id']);
        $this->assertSame('Zeta Book One', $zetaBook['title']);
        $this->assertSame(['Jane Doe'], $zetaBook['author']);
        $this->assertSame('Zeta/Book One', $zetaBook['directoryPath']);
        $this->assertSame(3, $zetaBook['audioFileCount']);
    }

    #[Test]
    public function getBooksGroupedBySeriesFiltersBySearchSubstringCaseInsensitively(): void
    {
        $wanted = Series::factory()->create(['name' => 'Wanted Series']);
        $other = Series::factory()->create(['name' => 'Other Series']);

        $bookWanted = Book::factory()->create(['title' => 'Book A']);
        $bookWanted->series()->attach($wanted->id, ['series_number' => 1]);

        $bookOther = Book::factory()->create(['title' => 'Book B']);
        $bookOther->series()->attach($other->id, ['series_number' => 1]);

        $service = new MySqlService();

        $result = $service->getBooksGroupedBySeries('wanted');

        $this->assertSame(1, $result['total']);
        $this->assertSame(['Wanted Series'], array_keys($result['data']));
    }

    #[Test]
    public function getBooksGroupedBySeriesReturnsBookUnderEachSeriesItBelongsTo(): void
    {
        $seriesOne = Series::factory()->create(['name' => 'First Series']);
        $seriesTwo = Series::factory()->create(['name' => 'Second Series']);

        $book = Book::factory()->create(['title' => 'Crossover Book']);
        $book->series()->attach($seriesOne->id, ['series_number' => 1]);
        $book->series()->attach($seriesTwo->id, ['series_number' => 2]);

        $service = new MySqlService();

        $result = $service->getBooksGroupedBySeries();

        $this->assertCount(1, $result['data']['First Series']);
        $this->assertCount(1, $result['data']['Second Series']);
        $this->assertSame((string) $book->id, $result['data']['First Series'][0]['_id']);
        $this->assertSame((string) $book->id, $result['data']['Second Series'][0]['_id']);
    }

    #[Test]
    public function getBooksGroupedBySeriesPaginatesAtTheSeriesLevel(): void
    {
        foreach (['Alpha', 'Bravo', 'Charlie'] as $name) {
            $series = Series::factory()->create(['name' => $name]);
            $book = Book::factory()->create(['title' => $name . ' Book']);
            $book->series()->attach($series->id, ['series_number' => 1]);
        }

        $service = new MySqlService();

        $pageOne = $service->getBooksGroupedBySeries(null, 1, 2);
        $pageTwo = $service->getBooksGroupedBySeries(null, 2, 2);

        $this->assertSame(3, $pageOne['total']);
        $this->assertSame(['Alpha', 'Bravo'], array_keys($pageOne['data']));

        $this->assertSame(3, $pageTwo['total']);
        $this->assertSame(['Charlie'], array_keys($pageTwo['data']));
    }
}
