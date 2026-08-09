<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Concerns;

use App\Models\User;

/**
 * Shared "don't recommend a book the user has already engaged with" helper. Every
 * strategy uses this so a book already queued/wishlisted/in-progress/completed/etc.
 * never shows up as a "recommendation" again.
 */
trait ExcludesEngagedBooks
{
    /**
     * @return array<int, int>
     */
    protected function excludedBookIds(User $user): array
    {
        return $user->bookStatuses()
            ->whereNotNull('book_id')
            ->pluck('book_id')
            ->unique()
            ->values()
            ->all();
    }
}
