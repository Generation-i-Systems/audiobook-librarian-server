<?php

declare(strict_types=1);

namespace App\Services\Recommendations;

use App\Models\Genre;
use App\Models\User;
use App\Services\BookCompletionService;
use Illuminate\Support\Collection;

/**
 * Genres a user has completed at least one book in — shared by DurationBasedStrategy
 * and the "surprise me" endpoint, both of which restrict candidates to genres the user
 * is already known to enjoy.
 */
class FavoredGenreResolver
{
    /**
     * @return Collection<int, int>
     */
    public function forUser(User $user): Collection
    {
        $completedBookIds = app(BookCompletionService::class)->getCompletedBookIdsForUser($user->id);

        return Genre::query()
            ->whereHas('books', function ($query) use ($completedBookIds): void {
                $query->whereIn('books.id', $completedBookIds ?: [0]);
            })
            ->pluck('id');
    }
}
