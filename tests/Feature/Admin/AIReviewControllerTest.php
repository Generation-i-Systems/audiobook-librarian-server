<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\AIReviewController;
use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AIReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected AIReviewController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new AIReviewController();
    }

    private function createBookWithSuggestions(array $suggestions, array $bookOverrides = []): Book
    {
        $book = Book::create(array_merge([
            'title' => 'Original Title',
            'directory_path' => 'Fiction/Test Author/Original Title',
            'language' => 'en',
        ], $bookOverrides));

        // ai_suggestions, ai_processed, ai_confidence are not in $fillable
        $book->ai_suggestions = json_encode($suggestions);
        $book->ai_processed = false;
        $book->ai_confidence = $suggestions['confidence'] ?? 80;
        $book->save();

        return $book;
    }

    private function defaultSuggestions(array $overrides = []): array
    {
        return array_merge([
            'title' => 'AI Title',
            'author' => ['J R R Tolkien'],
            'narrator' => ['Rob Inglis'],
            'genre' => ['Epic Fantasy'],
            'series' => 'The Lord of the Rings',
            'series_number' => 1,
            'year' => '1954',
            'description' => 'A hobbit tale',
            'publisher' => null,
            'isbn' => '978-0-618-64015-7',
            'language' => 'en',
            'confidence' => 85,
        ], $overrides);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyCreatesAuthorWithTrimOnly(): void
    {
        $suggestions = $this->defaultSuggestions([
            'author' => [' J R R Tolkien '],
        ]);
        $book = $this->createBookWithSuggestions($suggestions);

        $request = Request::create('/test', 'POST', [
            'fields' => ['authors'],
        ]);

        $this->controller->apply($request, $book);

        $book->refresh();
        $authorNames = $book->authors->pluck('name')->toArray();
        // Current behavior: only trims, no normalization (e.g. no period insertion)
        $this->assertEquals(['J R R Tolkien'], $authorNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyCreatesGenreWithoutMapping(): void
    {
        $suggestions = $this->defaultSuggestions([
            'genre' => ['Epic Fantasy'],
        ]);
        $book = $this->createBookWithSuggestions($suggestions);

        $request = Request::create('/test', 'POST', [
            'fields' => ['genres'],
        ]);

        $this->controller->apply($request, $book);

        $book->refresh();
        $genreNames = $book->genres->pluck('name')->toArray();
        // Current behavior: genre is stored as-is with no mapping to valid genre
        $this->assertEquals(['Epic Fantasy'], $genreNames);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function applyDefaultsSeriesNumberToOne(): void
    {
        $suggestions = $this->defaultSuggestions([
            'series' => 'Mistborn',
        ]);
        unset($suggestions['series_number']);
        $book = $this->createBookWithSuggestions($suggestions);

        $request = Request::create('/test', 'POST', [
            'fields' => ['series'],
        ]);

        $this->controller->apply($request, $book);

        $book->refresh();
        $this->assertCount(1, $book->series);
        $this->assertEquals('Mistborn', $book->series->first()->name);

        // Current behavior: defaults to 1 when series_number is missing
        $pivot = \Illuminate\Support\Facades\DB::table('book_series')
            ->where('book_id', $book->id)
            ->first();
        $this->assertEquals(1, $pivot->series_number);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function bulkApplyProducesSameAuthorResult(): void
    {
        // Use suggestions without publisher (publisher column doesn't exist,
        // applyAllSuggestions has a bug setting $book->publisher = string)
        $suggestions = $this->defaultSuggestions([
            'author' => [' J R R Tolkien '],
            'publisher' => null,
        ]);
        $book = $this->createBookWithSuggestions($suggestions);

        $method = new \ReflectionMethod(AIReviewController::class, 'applyAllSuggestions');
        $method->invoke($this->controller, $book, $suggestions);

        $book->refresh();
        $authorNames = $book->authors->pluck('name')->toArray();
        $this->assertEquals(['J R R Tolkien'], $authorNames);
    }
}
