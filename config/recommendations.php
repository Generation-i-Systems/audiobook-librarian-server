<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Discovery Shelf Strategies
    |--------------------------------------------------------------------------
    |
    | Each class implements RecommendationStrategyInterface and contributes zero,
    | one, or several shelves to a user's Netflix-style discovery page. Adding a
    | new discoverability idea is purely additive: write a new strategy class
    | and register it here — no schema or client changes required.
    |
    */
    'strategies' => [
        \App\Services\Recommendations\Strategies\SimilarToRecentBooksStrategy::class,
        \App\Services\Recommendations\Strategies\NewForYouStrategy::class,
        \App\Services\Recommendations\Strategies\GenreAffinityStrategy::class,
        \App\Services\Recommendations\Strategies\ContinueSeriesStrategy::class,
        \App\Services\Recommendations\Strategies\AuthorAffinityStrategy::class,
        \App\Services\Recommendations\Strategies\DurationBasedStrategy::class,
    ],
];
