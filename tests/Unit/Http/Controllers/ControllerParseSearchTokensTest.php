<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Controller;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ControllerParseSearchTokensTest extends TestCase
{
    private function parse(?string $raw): array
    {
        $controller = new class () extends Controller {
            public function parse(?string $raw): array
            {
                return $this->parseSearchTokens($raw);
            }
        };

        return $controller->parse($raw);
    }

    #[Test]
    public function parsesBookIdToken(): void
    {
        $result = $this->parse('bookId:42');

        $this->assertSame(42, $result['book_id']);
        $this->assertSame('', $result['search']);
    }

    #[Test]
    public function parsesBookIdTokenCombinedWithFreeText(): void
    {
        $result = $this->parse('bookId:42 Mistborn');

        $this->assertSame(42, $result['book_id']);
        $this->assertSame('Mistborn', $result['search']);
    }

    #[Test]
    public function parsesAllIdTokensTogether(): void
    {
        $result = $this->parse('authorId:5 genreId:12 seriesId:9 bookId:42');

        $this->assertSame(5, $result['author_id']);
        $this->assertSame(12, $result['genre_id']);
        $this->assertSame(9, $result['series_id']);
        $this->assertSame(42, $result['book_id']);
        $this->assertSame('', $result['search']);
    }

    #[Test]
    public function returnsNullBookIdWhenTokenAbsent(): void
    {
        $result = $this->parse('Mistborn');

        $this->assertNull($result['book_id']);
        $this->assertSame('Mistborn', $result['search']);
    }

    #[Test]
    public function parsesGenreNameToken(): void
    {
        $result = $this->parse('genre:Fantasy Mistborn');

        $this->assertSame('Fantasy', $result['genre_name']);
        $this->assertSame('Mistborn', $result['search']);
    }

    #[Test]
    public function parsesQuotedAuthorNameToken(): void
    {
        $result = $this->parse('author:"Brandon Sanderson"');

        $this->assertSame('Brandon Sanderson', $result['author_name']);
        $this->assertSame('', $result['search']);
    }

    #[Test]
    public function parsesSeriesNameTokenUnquoted(): void
    {
        $result = $this->parse('series:WheelOfTime');

        $this->assertSame('WheelOfTime', $result['series_name']);
        $this->assertSame('', $result['search']);
    }

    #[Test]
    public function parsesTagToken(): void
    {
        $result = $this->parse('tag:funny');

        $this->assertSame('funny', $result['tag']);
        $this->assertSame('', $result['search']);
    }

    #[Test]
    public function parsesMixedIdAndNameTokensTogether(): void
    {
        $result = $this->parse('authorId:5 genre:Fantasy Mistborn');

        $this->assertSame(5, $result['author_id']);
        $this->assertSame('Fantasy', $result['genre_name']);
        $this->assertSame('Mistborn', $result['search']);
    }

    #[Test]
    public function nameTokenDoesNotCollideWithIdToken(): void
    {
        $result = $this->parse('authorId:5 genreId:9');

        $this->assertSame(5, $result['author_id']);
        $this->assertSame(9, $result['genre_id']);
        $this->assertNull($result['author_name']);
        $this->assertNull($result['genre_name']);
        $this->assertSame('', $result['search']);
    }

    #[Test]
    public function returnsNullNameTokensWhenAbsent(): void
    {
        $result = $this->parse('Mistborn');

        $this->assertNull($result['genre_name']);
        $this->assertNull($result['author_name']);
        $this->assertNull($result['series_name']);
        $this->assertNull($result['tag']);
    }

    #[Test]
    public function collapsesExtraWhitespaceAfterTokenRemoval(): void
    {
        $result = $this->parse('genre:Fantasy   author:"Brandon Sanderson"   Mistborn');

        $this->assertSame('Fantasy', $result['genre_name']);
        $this->assertSame('Brandon Sanderson', $result['author_name']);
        $this->assertSame('Mistborn', $result['search']);
    }
}
