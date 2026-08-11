<?php

declare(strict_types=1);

namespace App\Services\Recommendations\Concerns;

use App\Models\User;
use App\Services\BookCompletionService;

/**
 * Shared "don't recommend a book the user has already engaged with" helper. Every
 * strategy uses this so a book already queued/wishlisted/in-progress/completed/etc.
 * never shows up as a "recommendation" again. Engagement is merged across
 * user_book_status AND the progress-sync tables (book_progress/book_positions) via
 * BookCompletionService, since many users only ever have progress-sync data and no
 * user_book_status rows at all.
 */
trait ExcludesEngagedBooks
{
    /**
     * @return array<int, int>
     */
    protected function excludedBookIds(User $user): array
    {
        return app(BookCompletionService::class)->getEngagedBookIdsForUser($user->id);
    }
}
