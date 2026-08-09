<?php

declare(strict_types=1);

namespace App\Services\Recommendations;

use App\Models\Genre;
use App\Models\User;
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
        return Genre::query()
            ->whereHas('books', function ($query) use ($user): void {
                $query->whereHas('statuses', fn ($q) => $q->where('user_id', $user->id)->where('status', 'completed'));
            })
            ->pluck('id');
    }
}
