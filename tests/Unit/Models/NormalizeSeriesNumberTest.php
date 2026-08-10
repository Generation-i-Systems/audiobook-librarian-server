<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Book;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NormalizeSeriesNumberTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('seriesNumberValues')]
    public function seriesNumberIsNormalizedWhenASeriesRelationIsSaved(string $input, string $expected): void
    {
        $book = Book::factory()->create();
        $series = Series::factory()->create();

        $book->series()->attach($series->id, ['series_number' => $input]);

        $this->assertSame($expected, DB::table('book_series')->value('series_number'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function seriesNumberValues(): array
    {
        return [
            'padded whole number' => ['003', '3'],
            'padded compound number' => ['01,02', '1,2'],
            'padded fraction below one' => ['00.5', '0.5'],
            'zero fraction is preserved' => ['0.5', '0.5'],
            'zero range is preserved' => ['0-3', '0-3'],
            'non-numeric text is preserved' => ['1, Ahriman: Warhammer 40, 000', '1, Ahriman: Warhammer 40, 000'],
        ];
    }
}
