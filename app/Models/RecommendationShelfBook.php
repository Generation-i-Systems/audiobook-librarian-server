<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shelf_id
 * @property int $book_id
 * @property int $rank
 * @property float|null $score
 * @property-read \App\Models\RecommendationShelf $shelf
 * @property-read \App\Models\Book $book
 */
class RecommendationShelfBook extends Model
{
    protected $fillable = [
        'shelf_id',
        'book_id',
        'rank',
        'score',
    ];

    protected $casts = [
        'rank' => 'integer',
        'score' => 'float',
    ];

    /** @return BelongsTo<RecommendationShelf, $this> */
    public function shelf(): BelongsTo
    {
        return $this->belongsTo(RecommendationShelf::class, 'shelf_id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
