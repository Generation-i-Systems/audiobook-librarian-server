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
}
