<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Auth\DocumentstoreUser;
use App\Models\Author;
use App\Models\Book;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookControllerRelatedBooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = new DocumentstoreUser([
            'id' => 'test-admin-user',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin);
    }

    #[Test]
    public function editPageShowsAuthorAndSeriesLinkIcons(): void
    {
        $author = Author::factory()->create(['name' => 'Brandon Sanderson']);
        $series = Series::factory()->create(['name' => 'Mistborn']);

        $book = Book::factory()->create(['title' => 'The Final Empire']);
        $book->authors()->attach($author->id);
        $book->series()->attach($series->id, ['series_number' => '1']);

        $response = $this->get(route('admin.books.edit', $book->id));

        $response->assertStatus(200);
        $response->assertSee(route('admin.books.index', ['search' => 'authorId:' . $author->id]), false);
        $response->assertSee(route('admin.books.index', ['search' => 'seriesId:' . $series->id]), false);
        $response->assertSee('data-type="author" data-id="' . $author->id . '"', false);
        $response->assertSee('data-type="series" data-id="' . $series->id . '"', false);
    }

    #[Test]
    public function relatedBooksAjaxReturnsOtherBooksInSeriesExcludingCurrent(): void
    {
        $series = Series::factory()->create(['name' => 'Mistborn']);

        $book1 = Book::factory()->create(['title' => 'The Final Empire']);
        $book1->series()->attach($series->id, ['series_number' => '1']);

        $book2 = Book::factory()->create(['title' => 'The Well of Ascension']);
        $book2->series()->attach($series->id, ['series_number' => '2']);

        $response = $this->getJson(route('admin.books.relatedAjax', [
            'type' => 'series',
            'id' => $series->id,
            'exclude' => $book1->id,
        ]));

        $response->assertStatus(200);
        $titles = array_column($response->json('books'), 'title');

        $this->assertSame(['The Well of Ascension'], $titles);
    }

    #[Test]
    public function relatedBooksAjaxReturnsOtherBooksByAuthorExcludingCurrent(): void
    {
        $author = Author::factory()->create(['name' => 'Brandon Sanderson']);

        $book1 = Book::factory()->create(['title' => 'Elantris']);
        $book1->authors()->attach($author->id);

        $book2 = Book::factory()->create(['title' => 'Warbreaker']);
        $book2->authors()->attach($author->id);

        $response = $this->getJson(route('admin.books.relatedAjax', [
            'type' => 'author',
            'id' => $author->id,
            'exclude' => $book1->id,
        ]));

        $response->assertStatus(200);
        $titles = array_column($response->json('books'), 'title');

        $this->assertSame(['Warbreaker'], $titles);
    }

    #[Test]
    public function relatedBooksAjaxRejectsInvalidType(): void
    {
        $response = $this->getJson(route('admin.books.relatedAjax', [
            'type' => 'bogus',
            'id' => 1,
        ]));

        $response->assertStatus(422);
    }
}
