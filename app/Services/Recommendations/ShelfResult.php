<?php

declare(strict_types=1);

namespace App\Services\Recommendations;

/**
 * One "discovery shelf" produced by a strategy. `books` is ordered best-first.
 */
final class ShelfResult
{
    /**
     * @param array<int, array{book_id: int, score: float|null}> $books
     */
    public function __construct(
        public readonly string $shelfKey,
        public readonly string $title,
        public readonly array $books,
    ) {
    }
}
