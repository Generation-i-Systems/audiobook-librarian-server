<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\RecommendationShelf;
use App\Models\RecommendationShelfBook;
use App\Models\Series;
use App\Models\User;
use App\Services\BookDataTransformer;
use App\Services\Recommendations\FavoredGenreResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Serves the precomputed discovery shelves (RecommendationEngine::recompute) — this
 * controller only ever reads cached rows, never computes recommendations live — plus
 * the separate, live-computed "surprise me" single-book pick.
 */
class DiscoveryController extends Controller
{
    private const DEFAULT_SHELF_PREVIEW_SIZE = 10;
    private const MAX_SHELF_PREVIEW_SIZE = 20;
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 50;

    /** How many random candidates to scan per attempt when looking for a first-in-series book. */
    private const SURPRISE_CANDIDATE_BATCH = 30;
    private const SURPRISE_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly BookDataTransformer $transformer,
        private readonly FavoredGenreResolver $favoredGenres,
    ) {
    }

    public function shelves(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $previewSize = min(self::MAX_SHELF_PREVIEW_SIZE, max(1, (int) $request->input('per_page', self::DEFAULT_SHELF_PREVIEW_SIZE)));

        $shelves = RecommendationShelf::where('user_id', $userId)
            ->orderBy('sort_order')
            ->withCount('shelfBooks')
            ->with(['shelfBooks' => function ($query) use ($previewSize): void {
                $query->limit($previewSize)->with(['book.authors', 'book.narrators', 'book.series', 'book.genres']);
            }])
            ->get();

        $data = $shelves->map(fn (RecommendationShelf $shelf): array => [
            'shelf_key' => $shelf->shelf_key,
            'title' => $shelf->title,
            'sort_order' => $shelf->sort_order,
            'books' => $shelf->shelfBooks
                ->map(fn (RecommendationShelfBook $shelfBook) => $this->transformer->toBookListItem($shelfBook->book, $userId))
                ->values()
                ->all(),
            'has_more' => $shelf->shelf_books_count > $previewSize,
        ])->values()->all();

        return response()->json(['data' => $data]);
    }

    public function shelfBooks(Request $request, string $shelfKey): JsonResponse
    {
        $userId = Auth::id();
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(self::MAX_PAGE_SIZE, max(1, (int) $request->input('per_page', self::DEFAULT_PAGE_SIZE)));

        $shelf = RecommendationShelf::where('user_id', $userId)->where('shelf_key', $shelfKey)->first();
        if (!$shelf) {
            return response()->json([
                'data' => [],
                'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
            ]);
        }

        $total = $shelf->shelfBooks()->count();
        $shelfBooks = $shelf->shelfBooks()
            ->with(['book.authors', 'book.narrators', 'book.series', 'book.genres'])
            ->forPage($page, $perPage)
            ->get();

        $books = $shelfBooks
            ->map(fn (RecommendationShelfBook $shelfBook) => $this->transformer->toBookListItem($shelfBook->book, $userId))
            ->values()
            ->all();

        return response()->json([
            'data' => $books,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * "Surprise me": one random unread book, live-computed (not a precomputed shelf) —
     * intentionally simple, for decision-fatigue relief rather than relevance. Prefers a
     * book that starts a series (or isn't in one) in a genre the user already knows they
     * enjoy; falls back to any first-in-series book, then to any unread book at all, so it
     * always returns something if the library has any unread book left.
     */
    public function surprise(): JsonResponse
    {
        $userId = Auth::id();
        $user = User::find($userId);
        $excluded = $user->bookStatuses()->whereNotNull('book_id')->pluck('book_id')->all();

        $favoredGenreIds = $this->favoredGenres->forUser($user);

        $book = null;
        if ($favoredGenreIds->isNotEmpty()) {
            $book = $this->pickFirstInSeriesCandidate($excluded, $favoredGenreIds);
        }
        $book ??= $this->pickFirstInSeriesCandidate($excluded, null);
        $book ??= Book::query()->whereNotIn('id', $excluded ?: [0])->inRandomOrder()->first();

        if (!$book) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->transformer->toBookListItem($book, $userId)]);
    }

    /**
     * @param array<int, int> $excludedBookIds
     * @param Collection<int, int>|null $genreIds null means "no genre constraint"
     */
    private function pickFirstInSeriesCandidate(array $excludedBookIds, ?Collection $genreIds): ?Book
    {
        for ($attempt = 0; $attempt < self::SURPRISE_MAX_ATTEMPTS; $attempt++) {
            $candidates = Book::query()
                ->whereNotIn('id', $excludedBookIds ?: [0])
                ->when($genreIds !== null, fn (Builder $q) => $q->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $genreIds)))
                ->with('series')
                ->inRandomOrder()
                ->limit(self::SURPRISE_CANDIDATE_BATCH)
                ->get();

            foreach ($candidates as $candidate) {
                if ($this->isFirstInSeries($candidate)) {
                    return $candidate;
                }
            }

            if ($candidates->count() < self::SURPRISE_CANDIDATE_BATCH) {
                break; // exhausted the whole eligible pool already
            }
        }

        return null;
    }

    private function isFirstInSeries(Book $book): bool
    {
        if ($book->series->isEmpty()) {
            return true;
        }

        foreach ($book->series as $series) {
            $bookNumber = (int) ($series->pivot->series_number ?? PHP_INT_MAX);
            $minNumber = $this->minSeriesNumber($series);
            if ($bookNumber <= $minNumber) {
                return true;
            }
        }

        return false;
    }

    private function minSeriesNumber(Series $series): int
    {
        return $series->books()->get()
            ->map(fn (Book $b) => (int) ($b->pivot->series_number ?? PHP_INT_MAX))
            ->min() ?? PHP_INT_MAX;
    }
}
