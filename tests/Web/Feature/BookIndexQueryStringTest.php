<?php

declare(strict_types=1);

namespace Tests\Web\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * /books is driven entirely by its query string: the server renders the results, the pagination
 * links and the per-page links, so a reload, the back button and the after-edit return URL all
 * land on the same search.
 */
class BookIndexQueryStringTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create(['role' => 'library-user']);
        $this->actingAs($this->user);
    }

    #[Test]
    public function paginationLinksKeepTheSearchQuery(): void
    {
        $series = Series::factory()->create(['name' => 'Warriors']);
        foreach (range(1, 3) as $number) {
            Book::factory()->create(['title' => "Warriors {$number}"])
                ->series()->attach($series, ['series_number' => (string) $number]);
        }

        $query = ['series' => 'Warriors', 'sort' => 'series_asc', 'per_page' => 24];
        $response = $this->get(route('books.index', $query));
        $response->assertOk();
        $this->assertSame(3, $response->viewData('books')->total());

        $paged = $this->get('/books?series=Warriors&sort=series_asc&per_page=24&page=2');
        $paged->assertOk();
        $this->assertSame(2, $paged->viewData('books')->currentPage());
        $this->assertSame([], $paged->viewData('books')->items(), 'page 2 of a 3-book result at 24 per page is empty');

        // With more results than one page, page links are server-rendered and carry the query.
        foreach (range(4, 26) as $number) {
            Book::factory()->create(['title' => "Warriors {$number}"])
                ->series()->attach($series, ['series_number' => (string) $number]);
        }
        $html = $this->get(route('books.index', $query))->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString(
            e(route('books.index', $query + ['page' => 2])),
            $html
        );
    }

    #[Test]
    public function perPageInTheQueryStringIsUsedAndRemembered(): void
    {
        Book::factory()->count(3)->create();

        $this->get(route('books.index', ['per_page' => 48]))->assertOk()
            ->assertViewHas('mainPerPage', 48);

        $this->get(route('books.index'))->assertOk()
            ->assertViewHas('mainPerPage', 48);
    }

    #[Test]
    public function perPageLinksKeepTheSearchQueryAndResetToTheFirstPage(): void
    {
        Book::factory()->count(3)->create();

        $html = $this->get(route('books.index', ['search' => 'dragon', 'page' => 3]))->getContent();
        $this->assertIsString($html);

        $this->assertStringContainsString(e(route('books.index', ['search' => 'dragon', 'per_page' => 48])), $html);
    }

    #[Test]
    public function serverRenderedListShowsSeriesNumberAndDuration(): void
    {
        $series = Series::factory()->create(['name' => 'Moran Sisters']);
        $book = Book::factory()->create(['title' => 'Trying Not to Love You', 'duration' => 28390]);
        $book->series()->attach($series, ['series_number' => '1']);
        $book->authors()->attach(Author::factory()->create(['name' => 'Kaylee Ryan']));
        $book->genres()->attach(Genre::factory()->create(['name' => 'Romance']));

        $books = $this->get(route('books.index'))->assertOk()->viewData('books');
        $row = collect($books->items())->firstWhere('id', $book->id);

        $this->assertSame(
            [
                'authors' => ['Kaylee Ryan'],
                'genres' => ['Romance'],
                'series' => [['seriesName' => 'Moran Sisters', 'number' => '1']],
                'duration' => '07:53:10',
            ],
            ['authors' => $row['authors'], 'genres' => $row['genres'], 'series' => $row['series'], 'duration' => $row['duration']]
        );
    }

    #[Test]
    public function listViewRowRendersSeriesNumberAndDurationColumns(): void
    {
        $series = Series::factory()->create(['name' => 'Moran Sisters']);
        $book = Book::factory()->create(['title' => 'Trying Not to Love You', 'duration' => 28390]);
        $book->series()->attach($series, ['series_number' => '1']);

        $html = $this->get(route('books.index'))->assertOk()->getContent();
        $this->assertIsString($html);
        // Collapse Blade's indentation so fragments can be matched without depending on it.
        $html = (string) preg_replace('/\s+/', ' ', $html);

        $seriesUrl = e(route('books.index', ['series' => 'Moran Sisters']));
        $expectedFragments = [
            'series header' => '<th>Series</th>',
            'series and number cells' => '<td> <a href="' . $seriesUrl . '" class="text-decoration-none">Moran Sisters</a> </td>'
                . ' <td class="text-center"> 1 </td>',
            'duration cell' => '<td class="text-muted small">07:53:10</td>',
        ];
        foreach ($expectedFragments as $label => $fragment) {
            $this->assertStringContainsString($fragment, $html, $label);
        }
    }
}
