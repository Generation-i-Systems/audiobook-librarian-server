<?php

declare(strict_types=1);

namespace App\Services\Recommendations;

use App\Models\RecommendationShelf;
use App\Models\RecommendationShelfDismissal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs every enabled RecommendationStrategyInterface for a user and replaces their
 * cached recommendation_shelves/recommendation_shelf_books rows with the result.
 * Normally run from a queued job or scheduled command (see EmbeddingPipeline's
 * vector-store cost notes); the only inline use is a user's explicit POST /discovery/refresh.
 */
class RecommendationEngine
{
    /**
     * @param RecommendationStrategyInterface[] $strategies
     * @param int $jitter max places a book may move on a shelf (0 = keep strategy order)
     * @param (\Closure(): float)|null $random returns a float in [0, 1); injectable for tests
     */
    public function __construct(
        private readonly array $strategies,
        private readonly int $jitter = 0,
        private readonly ?\Closure $random = null,
    ) {
    }

    public static function fromConfig(): self
    {
        $strategies = array_map(
            static fn (string $class): RecommendationStrategyInterface => app($class),
            config('recommendations.strategies', [])
        );

        return new self($strategies, max(0, (int) config('recommendations.jitter', 0)));
    }

    public function recompute(User $user): void
    {
        $results = [];

        foreach ($this->strategies as $strategy) {
            if (!$strategy->isEnabled()) {
                continue;
            }

            try {
                foreach ($strategy->generate($user) as $result) {
                    $results[] = $result;
                }
            } catch (\Throwable $e) {
                // One failing strategy must not prevent the others from producing shelves.
                Log::error('Recommendation strategy failed', [
                    'strategy' => $strategy->key(),
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $dismissedKeys = RecommendationShelfDismissal::where('user_id', $user->id)->pluck('shelf_key')->all();
        $results = array_values(array_filter($results, fn (ShelfResult $result): bool => !in_array($result->shelfKey, $dismissedKeys, true)));

        $results = array_map(fn (ShelfResult $result): ShelfResult => $this->jittered($result), $results);

        // Each book appears on at most one shelf: earlier shelves claim books first, and a
        // shelf left with no books is dropped. Dismissed shelves are filtered out above so
        // they never claim books from the shelves the user still sees.
        $claimed = [];
        $shelves = [];
        foreach ($results as $result) {
            $books = [];
            foreach ($result->books as $book) {
                if (isset($claimed[$book['book_id']])) {
                    continue;
                }
                $claimed[$book['book_id']] = true;
                $books[] = $book;
            }
            if ($books === []) {
                continue;
            }
            $shelves[] = ['result' => new ShelfResult($result->shelfKey, $result->title, $books), 'sort_order' => count($shelves)];
        }

        DB::transaction(function () use ($user, $shelves): void {
            RecommendationShelf::where('user_id', $user->id)->delete();

            foreach ($shelves as $entry) {
                /** @var ShelfResult $result */
                $result = $entry['result'];

                $shelf = RecommendationShelf::create([
                    'user_id' => $user->id,
                    'shelf_key' => $result->shelfKey,
                    'title' => $result->title,
                    'sort_order' => $entry['sort_order'],
                    'computed_at' => now(),
                ]);

                foreach ($result->books as $rank => $book) {
                    $shelf->shelfBooks()->create([
                        'book_id' => $book['book_id'],
                        'rank' => $rank,
                        'score' => $book['score'],
                    ]);
                }
            }
        });
    }

    /** Nudge each book up to `$jitter` places from its strategy rank, so shelves vary between recomputes. */
    private function jittered(ShelfResult $result): ShelfResult
    {
        if ($this->jitter <= 0) {
            return $result;
        }

        $random = $this->random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
        $keyed = [];
        foreach ($result->books as $position => $book) {
            $keyed[] = ['key' => $position + $random() * $this->jitter, 'book' => $book];
        }
        usort($keyed, fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return new ShelfResult(
            $result->shelfKey,
            $result->title,
            array_map(static fn (array $entry): array => $entry['book'], $keyed),
        );
    }
}
