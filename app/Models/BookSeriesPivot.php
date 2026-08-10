<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Typed pivot for the book_series table, so `$book->pivot->series_number` /
 * `$series->pivot->series_number` resolve statically instead of via untyped
 * dynamic property access on the base Pivot class.
 *
 * @property string|null $series_number
 */
class BookSeriesPivot extends Pivot
{
    protected $table = 'book_series';

    protected static function booted(): void
    {
        static::saving(function (BookSeriesPivot $pivot): void {
            $pivot->series_number = self::normalizeSeriesNumber($pivot->series_number);
        });
    }

    public static function normalizeSeriesNumber(mixed $seriesNumber): mixed
    {
        if (
            !is_string($seriesNumber)
            || preg_match('/^\d+(?:\.\d+)?(?:\s*[-,]\s*\d+(?:\.\d+)?)*$/', $seriesNumber) !== 1
        ) {
            return $seriesNumber;
        }

        return preg_replace('/(?<!\d)0+(?=\d)/', '', $seriesNumber);
    }
}
