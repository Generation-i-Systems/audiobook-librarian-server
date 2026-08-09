<?php

declare(strict_types=1);

namespace App\Services\Recommendations;

use App\Models\User;

/**
 * A single "shelf-producing" strategy for the Netflix-style discovery page. Each
 * implementation is registered in config('recommendations.strategies'); adding a
 * new discoverability idea later means writing a new class here and registering
 * it — no schema or client changes required.
 */
interface RecommendationStrategyInterface
{
    /**
     * A stable identifier for this strategy, used as a prefix/fallback for shelf keys
     * and to look up its enabled/disabled config flag.
     */
    public function key(): string;

    public function isEnabled(): bool;

    /**
     * Zero, one, or several shelves for this user (e.g. a per-seed-book strategy may
     * return one shelf per seed). Must not throw for "nothing to show" — return [].
     *
     * @return ShelfResult[]
     */
    public function generate(User $user): array;
}
