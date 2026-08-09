<?php

declare(strict_types=1);

namespace App\Services\Recommendations;

use App\Models\RecommendationShelf;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs every enabled RecommendationStrategyInterface for a user and replaces their
 * cached recommendation_shelves/recommendation_shelf_books rows with the result.
 * Always run from a queued job or scheduled command — never inline during an HTTP
 * request (see EmbeddingPipeline's vector-store cost notes).
 */
class RecommendationEngine
{
    /**
     * @param RecommendationStrategyInterface[] $strategies
     */
    public function __construct(private readonly array $strategies)
    {
    }

    public static function fromConfig(): self
    {
        $strategies = array_map(
            static fn (string $class): RecommendationStrategyInterface => app($class),
            config('recommendations.strategies', [])
        );

        return new self($strategies);
    }

    public function recompute(User $user): void
    {
        $sortOrder = 0;
        $shelves = [];

        foreach ($this->strategies as $strategy) {
            if (!$strategy->isEnabled()) {
                continue;
            }

            try {
                foreach ($strategy->generate($user) as $result) {
                    $shelves[] = ['result' => $result, 'sort_order' => $sortOrder++];
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
}
