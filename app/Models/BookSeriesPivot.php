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
}
