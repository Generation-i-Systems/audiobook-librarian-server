<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookProgress;
use App\Models\ClientBook;
use App\Models\ListeningGoal;
use App\Models\Playlist;
use App\Models\UserBookStatus;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use App\Services\BookCompletionService;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use App\Services\ListeningActivityService;

class ListeningGoalController extends Controller
{
    public function __construct(
        private readonly BookCompletionService $bookCompletionService,
        private readonly ListeningActivityService $listeningActivityService
    ) {
    }

    private const METRICS = 'total_hours,genre_hours,playlist_hours,playlist_completion,playlist_books_finished,fiction_hours,nonfiction_hours,books_finished,series_hours,author_hours,book_hours,book_completion';

    /** GET /goals/listening — list all active (not-yet-expired) listening goals with current progress */
    public function index(): JsonResponse
    {
        $goals = ListeningGoal::where('user_id', Auth::id())
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('period_type', '!=', 'custom')
                    ->orWhereNull('end_date')
                    ->orWhere('end_date', '>=', now()->toDateString());
            })
            ->with(['genre', 'playlist', 'series', 'author', 'book'])
            ->orderBy('period_type')
            ->get()
            ->map(fn ($goal) => $this->formatGoalWithProgress($goal));

        return response()->json(['goals' => $goals]);
    }

    /** GET /goals/listening/history — expired or deactivated custom-period goals with final progress */
    public function history(): JsonResponse
    {
        $goals = ListeningGoal::where('user_id', Auth::id())
            ->where('period_type', 'custom')
            ->where(function ($query) {
                $query->where('end_date', '<', now()->toDateString())
                    ->orWhere('is_active', false);
            })
            ->with(['genre', 'playlist', 'series', 'author', 'book'])
            ->orderByDesc('end_date')
            ->get()
            ->map(fn ($goal) => $this->formatGoalWithProgress($goal));

        return response()->json(['goals' => $goals]);
    }

    /** POST /goals/listening — create a new listening goal */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_type'    => 'required|string|in:day,week,month,year,custom',
            'metric'         => 'required|string|in:' . self::METRICS,
            'target_minutes' => 'required_unless:metric,book_completion,playlist_completion,playlist_books_finished|integer|min:1|max:14400',
            'genre_id'       => 'nullable|integer|exists:genres,id',
            'playlist_id'    => 'nullable|integer|exists:playlists,id',
            'series_id'      => 'nullable|integer|exists:series,id',
            'author_id'      => 'nullable|integer|exists:authors,id',
            'book_id'        => 'nullable|integer|exists:books,id',
            'book_title'     => 'nullable|string|max:255',
            'book_author'    => 'nullable|string|max:255',
            'start_date'     => 'required_if:period_type,custom|nullable|date',
            'end_date'       => 'required_if:period_type,custom|nullable|date',
        ]);

        $this->assertCustomRangeConsistency($validated['period_type'], $validated['start_date'] ?? null, $validated['end_date'] ?? null);
        $this->assertBookCompletionRequirements(
            $validated['metric'],
            $validated['period_type'],
            $validated['book_id'] ?? null,
            $validated['book_title'] ?? null,
            $validated['book_author'] ?? null
        );
        $this->assertPlaylistOwnership($validated['playlist_id'] ?? null);
        $this->assertPlaylistCompletionRequirements($validated['metric'], $validated['playlist_id'] ?? null);

        $targetMinutes = $this->resolveTargetMinutes($validated);

        $goal = ListeningGoal::create([
            'user_id'        => Auth::id(),
            'period_type'    => $validated['period_type'],
            'metric'         => $validated['metric'],
            'target_minutes' => $targetMinutes,
            'genre_id'       => $validated['genre_id'] ?? null,
            'playlist_id'    => $validated['playlist_id'] ?? null,
            'series_id'      => $validated['series_id'] ?? null,
            'author_id'      => $validated['author_id'] ?? null,
            'book_id'        => $validated['book_id'] ?? null,
            'book_title'     => $validated['book_title'] ?? null,
            'book_author'    => $validated['book_author'] ?? null,
            'start_date'     => $validated['start_date'] ?? null,
            'end_date'       => $validated['end_date'] ?? null,
            'is_active'      => true,
        ]);

        $goal->load(['genre', 'playlist', 'series', 'author', 'book']);
        return response()->json(['goal' => $this->formatGoalWithProgress($goal)], 201);
    }

    /** PUT /goals/listening/{goal} — update a goal */
    public function update(Request $request, ListeningGoal $goal): JsonResponse
    {
        abort_if($goal->user_id !== Auth::id(), 403);

        $validated = $request->validate([
            'period_type'    => 'sometimes|string|in:day,week,month,year,custom',
            'metric'         => 'sometimes|string|in:' . self::METRICS,
            'target_minutes' => 'sometimes|integer|min:1|max:14400',
            'genre_id'       => 'nullable|integer|exists:genres,id',
            'playlist_id'    => 'nullable|integer|exists:playlists,id',
            'series_id'      => 'nullable|integer|exists:series,id',
            'author_id'      => 'nullable|integer|exists:authors,id',
            'book_id'        => 'nullable|integer|exists:books,id',
            'book_title'     => 'nullable|string|max:255',
            'book_author'    => 'nullable|string|max:255',
            'start_date'     => 'sometimes|nullable|date',
            'end_date'       => 'sometimes|nullable|date',
            'is_active'      => 'sometimes|boolean',
        ]);

        $resolvedPeriodType = $validated['period_type'] ?? $goal->period_type;
        $resolvedMetric = $validated['metric'] ?? $goal->metric;
        $resolvedBookId = array_key_exists('book_id', $validated) ? $validated['book_id'] : $goal->book_id;
        $resolvedBookTitle = array_key_exists('book_title', $validated) ? $validated['book_title'] : $goal->book_title;
        $resolvedBookAuthor = array_key_exists('book_author', $validated) ? $validated['book_author'] : $goal->book_author;
        $resolvedStartDate = array_key_exists('start_date', $validated) ? $validated['start_date'] : $goal->start_date?->toDateString();
        $resolvedEndDate = array_key_exists('end_date', $validated) ? $validated['end_date'] : $goal->end_date?->toDateString();
        $resolvedPlaylistId = array_key_exists('playlist_id', $validated) ? $validated['playlist_id'] : $goal->playlist_id;
        $this->assertCustomRangeConsistency($resolvedPeriodType, $resolvedStartDate, $resolvedEndDate);
        $this->assertBookCompletionRequirements($resolvedMetric, $resolvedPeriodType, $resolvedBookId, $resolvedBookTitle, $resolvedBookAuthor);
        $this->assertPlaylistOwnership($validated['playlist_id'] ?? null);
        $this->assertPlaylistCompletionRequirements($resolvedMetric, $resolvedPlaylistId);

        if ($resolvedMetric === 'book_completion') {
            $validated['target_minutes'] = $this->resolveTargetMinutes([
                'metric'         => $resolvedMetric,
                'book_id'        => $resolvedBookId,
                'target_minutes' => $validated['target_minutes'] ?? $goal->target_minutes,
            ]);
        }

        $goal->update($validated);
        $goal->load(['genre', 'playlist', 'series', 'author', 'book']);

        return response()->json(['goal' => $this->formatGoalWithProgress($goal)]);
    }

    /** DELETE /goals/listening/{goal} — delete a goal */
    public function destroy(ListeningGoal $goal): JsonResponse
    {
        abort_if($goal->user_id !== Auth::id(), 403);
        $goal->delete();
        return response()->json(['message' => 'Goal deleted']);
    }

    /** GET /goals/listening/{goal}/breakdown — which books/days are contributing to progress */
    public function breakdown(ListeningGoal $goal): JsonResponse
    {
        abort_if($goal->user_id !== Auth::id(), 403);

        [$periodStart, $periodEnd] = $this->resolvePeriod($goal);

        if ($this->isPlaylistCompletionMetric($goal->metric)) {
            $amounts = $this->playlistCompletionAmounts($goal);
            $progressPercent = $amounts['target'] > 0 ? min(100, round(($amounts['amount'] / $amounts['target']) * 100, 1)) : 0;
            $elapsedPercent = 100.0;
            $entries = $this->playlistCompletionEntries($goal);
        } else {
            $progressAmount = $this->computeProgressAmount($goal, $periodStart, $periodEnd);
            $progressPercent = $this->progressPercent($goal, $progressAmount);
            $elapsedPercent = $this->elapsedPercent($periodStart, $periodEnd);
            $entries = match ($goal->metric) {
                'books_finished'  => $this->booksFinishedEntries($goal, $periodStart, $periodEnd),
                'book_completion' => $this->bookCompletionEntries($goal, $progressAmount),
                default           => $this->hourEntries($goal, $periodStart, $periodEnd),
            };
        }

        return response()->json([
            'period_start'     => $periodStart->toDateString(),
            'period_end'       => $periodEnd->toDateString(),
            'elapsed_percent'  => $elapsedPercent,
            'progress_percent' => $progressPercent,
            'metric'           => $goal->metric,
            'entries'          => $entries,
        ]);
    }

    /**
     * Laravel's `after_or_equal:start_date` validation rule crashes with a
     * DateMalformedStringException (it falls back to parsing the literal string "start_date" as
     * a date) whenever start_date resolves to null - which required_if/sometimes freely allow
     * mid-validation. Doing the date-order comparison here instead, once both fields are known to
     * be non-empty, sidesteps that entirely.
     */
    private function assertCustomRangeConsistency(string $periodType, ?string $startDate, ?string $endDate): void
    {
        if ($periodType === 'custom') {
            abort_if(empty($startDate) || empty($endDate), 422, 'custom period requires start_date and end_date');
            abort_if(Carbon::parse($startDate)->gt(Carbon::parse($endDate)), 422, 'end_date must be on or after start_date');
        } else {
            abort_if(!empty($startDate) || !empty($endDate), 422, 'start_date/end_date are only allowed when period_type is custom');
        }
    }

    private function assertBookCompletionRequirements(string $metric, string $periodType, ?int $bookId, ?string $bookTitle, ?string $bookAuthor): void
    {
        if ($metric !== 'book_completion') {
            return;
        }

        abort_if($periodType !== 'custom', 422, 'book_completion goals require period_type=custom');
        $hasBookId = !empty($bookId);
        $hasTitleAuthor = !empty($bookTitle) && !empty($bookAuthor);
        abort_if(!$hasBookId && !$hasTitleAuthor, 422, 'book_completion goals require book_id, or book_title and book_author');
    }

    /**
     * When a real catalog book_id is known, its duration is the authoritative target - client
     * input is ignored so it can't drift from the book's real length. When the client can only
     * identify the book by (title, author) - e.g. a local-only book never matched to the catalog
     * - there's no server-side duration to derive from, so the client-supplied target_minutes is
     * trusted instead (same as every other metric, and same as the lite server's book_completion).
     */
    private function resolveTargetMinutes(array $validated): int
    {
        if ($this->isPlaylistCompletionMetric($validated['metric'])) {
            // The real target (total playlist duration or item count) is recomputed fresh on
            // every read in playlistCompletionAmounts() since playlist membership can change -
            // this stored value is never used for progress display.
            return 1;
        }

        if ($validated['metric'] !== 'book_completion') {
            return $validated['target_minutes'];
        }

        if (!empty($validated['book_id'] ?? null)) {
            return $this->deriveBookCompletionTargetMinutes($validated['book_id']);
        }

        abort_if(empty($validated['target_minutes'] ?? null), 422, 'target_minutes is required for a book_completion goal without book_id');

        return $validated['target_minutes'];
    }

    private function deriveBookCompletionTargetMinutes(?int $bookId): int
    {
        $book = Book::find($bookId);
        abort_if($book === null || empty($book->duration), 422, 'book_completion requires a book with known duration');

        return max(1, (int) round($book->duration / 60));
    }

    private function isPlaylistCompletionMetric(string $metric): bool
    {
        return in_array($metric, ['playlist_completion', 'playlist_books_finished'], true);
    }

    private function assertPlaylistCompletionRequirements(string $metric, ?int $playlistId): void
    {
        if (!$this->isPlaylistCompletionMetric($metric)) {
            return;
        }

        abort_if(empty($playlistId), 422, $metric . ' goals require playlist_id');
    }

    private function assertPlaylistOwnership(?int $playlistId): void
    {
        if (empty($playlistId)) {
            return;
        }

        abort_if(
            Playlist::where('id', $playlistId)->where('user_id', Auth::id())->doesntExist(),
            403,
            'Playlist not found'
        );
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolvePeriod(ListeningGoal $goal): array
    {
        return match ($goal->period_type) {
            'day'    => [now()->startOfDay(), now()->endOfDay()],
            'week'   => [now()->startOfWeek(Carbon::SUNDAY), now()->endOfWeek(Carbon::SATURDAY)],
            'month'  => [now()->startOfMonth(), now()->endOfMonth()],
            'year'   => [now()->startOfYear(), now()->endOfYear()],
            'custom' => [Carbon::parse($goal->start_date)->startOfDay(), Carbon::parse($goal->end_date)->endOfDay()],
            default  => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function elapsedPercent(Carbon $periodStart, Carbon $periodEnd): float
    {
        $totalSpan = max(1, $periodStart->diffInSeconds($periodEnd));
        $elapsed = $periodStart->diffInSeconds(now()->lessThan($periodEnd) ? now() : $periodEnd);

        return round(min(100, max(0, ($elapsed / $totalSpan) * 100)), 1);
    }

    private function computeProgressAmount(ListeningGoal $goal, Carbon $periodStart, Carbon $periodEnd): int
    {
        $userId = Auth::id();

        if ($goal->metric === 'books_finished') {
            return $this->bookCompletionService
                ->getCompletedBookDatesForUser($userId, $periodStart, $goal->genre_id)
                ->filter(fn (Carbon $date): bool => $date->lte($periodEnd))
                ->count();
        }

        if ($goal->metric === 'book_completion') {
            return $this->bookCompletionProgressMinutes($goal);
        }

        if ($this->isPlaylistCompletionMetric($goal->metric)) {
            return $this->playlistCompletionAmounts($goal)['amount'];
        }

        return $this->sumSessionMinutes($goal, $periodStart, $periodEnd);
    }

    /**
     * `listening_statistics` (the table this used to query) is no longer written by any current
     * client - real listening activity lives in the event-sourced `listening_events` table,
     * already reconstructed into session-shaped rows by ListeningActivityService for streaks/
     * badges. getSessions(userId) with no device id already aggregates across every device the
     * user has ever synced from (goals must reflect that, not just the requesting device).
     */
    private function sumSessionMinutes(ListeningGoal $goal, Carbon $periodStart, Carbon $periodEnd): int
    {
        if ($goal->metric === 'book_hours' && $goal->book_id === null) {
            // Local-only book (title/author identity, no catalog book_id) - listening_events has
            // no title/author columns to match against, only a numeric book_id.
            return 0;
        }

        $bookIdFilter = $this->resolveMatchingBookIds($goal);
        $seconds = $this->sessionsInRange(Auth::id(), $periodStart, $periodEnd, $bookIdFilter)
            ->sum('seconds_listened');

        return (int) round($seconds / 60);
    }

    /**
     * Sessions within [periodStart, periodEnd], optionally restricted to a book_id set.
     *
     * @return Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)>
     */
    private function sessionsInRange(int $userId, Carbon $periodStart, Carbon $periodEnd, ?Collection $bookIdFilter): Collection
    {
        $rangeStart = $periodStart->copy()->startOfDay();
        $rangeEnd = $periodEnd->copy()->endOfDay();

        return $this->listeningActivityService->getSessions($userId)
            ->filter(function (object $session) use ($rangeStart, $rangeEnd, $bookIdFilter): bool {
                $date = Carbon::parse($session->listening_date);
                if ($date->lt($rangeStart) || $date->gt($rangeEnd)) {
                    return false;
                }

                return $bookIdFilter === null || $bookIdFilter->contains($session->book_id);
            });
    }

    /** @return Collection<int, int>|null null means "no book restriction" (total_hours) */
    private function resolveMatchingBookIds(ListeningGoal $goal): ?Collection
    {
        return match ($goal->metric) {
            'genre_hours' => Book::whereHas(
                'genres',
                fn ($q) => $q->where('genres.id', $goal->genre_id)->whereNull('genres.deleted_at')
            )->pluck('id'),
            'fiction_hours' => Book::whereHas('genres', fn ($q) => $q->where('genres.is_fiction', true))->pluck('id'),
            'nonfiction_hours' => Book::whereHas('genres', fn ($q) => $q->where('genres.is_fiction', false))->pluck('id'),
            'playlist_hours' => UserBookStatus::where('user_id', Auth::id())
                ->where('playlist_id', $goal->playlist_id)
                ->whereNotNull('book_id')
                ->pluck('book_id'),
            'series_hours' => ControllerDatabase::table('book_series')->where('series_id', $goal->series_id)->pluck('book_id'),
            'author_hours' => ControllerDatabase::table('author_book')->where('author_id', $goal->author_id)->pluck('book_id'),
            'book_hours' => $goal->book_id !== null ? collect([$goal->book_id]) : collect(),
            default => null,
        };
    }

    /**
     * Progress for a book_completion goal is the book's actual playback position, not
     * accumulated listening minutes in the goal's date range — re-listening or scrubbing
     * must not corrupt "how much of the book is done."
     */
    private function bookCompletionProgressMinutes(ListeningGoal $goal): int
    {
        $userId = Auth::id();

        if ($goal->book_id !== null) {
            $isCompleted = UserBookStatus::where('user_id', $userId)
                ->where('book_id', $goal->book_id)
                ->where('status', 'completed')
                ->exists();

            // Furthest position across all of the user's devices, not whichever device synced
            // most recently - a stale sync from a device that's behind must not regress progress.
            $furthestPositionSeconds = BookProgress::where('user_id', $userId)
                ->where('book_id', $goal->book_id)
                ->max('current_position_seconds');
        } else {
            // No catalog book_id (e.g. a local-only book never matched to the catalog) - fall
            // back to the same (title, author) identity the client already reports positions
            // and status under for non-library books.
            $title = $goal->book_title ?? '__no_match__';
            $author = $goal->book_author ?? '__no_match__';

            $isCompleted = UserBookStatus::where('user_id', $userId)
                ->where('title', $title)
                ->where('author', $author)
                ->where('status', 'completed')
                ->exists();

            $clientBookId = ClientBook::where('title', $title)->where('author', $author)->value('id');
            $furthestPositionSeconds = $clientBookId === null
                ? null
                : BookProgress::where('user_id', $userId)
                    ->where('client_book_id', $clientBookId)
                    ->max('current_position_seconds');
        }

        if ($isCompleted) {
            return $goal->target_minutes;
        }

        if ($furthestPositionSeconds === null) {
            return 0;
        }

        return min($goal->target_minutes, (int) round($furthestPositionSeconds / 60));
    }

    /**
     * Progress for playlist_completion/playlist_books_finished is a live snapshot of the
     * playlist's current membership, not accumulated over any period - a book added or removed
     * from the playlist immediately changes the denominator, same reasoning as book_completion
     * trusting the book's actual playback position over accumulated session minutes.
     *
     * @return array{amount:int, target:int}
     */
    private function playlistCompletionAmounts(ListeningGoal $goal): array
    {
        $playlist = $goal->playlist;
        if ($playlist === null) {
            return ['amount' => 0, 'target' => 1];
        }

        $items = UserBookStatus::where('playlist_id', $playlist->id)
            ->where('user_id', $goal->user_id)
            ->whereNotNull('book_id')
            ->get(['book_id', 'status', 'marked_read_at']);

        if ($items->isEmpty()) {
            return ['amount' => 0, 'target' => 1];
        }

        if ($goal->metric === 'playlist_books_finished') {
            return [
                'amount' => $items->whereNotNull('marked_read_at')->count(),
                'target' => $items->count(),
            ];
        }

        $bookIds = $items->pluck('book_id');
        $durationsSeconds = Book::whereIn('id', $bookIds)->pluck('duration', 'id');
        $positionsSeconds = BookProgress::where('user_id', $goal->user_id)
            ->whereIn('book_id', $bookIds)
            ->selectRaw('book_id, MAX(current_position_seconds) as position')
            ->groupBy('book_id')
            ->pluck('position', 'book_id');

        $targetSeconds = 0;
        $progressSeconds = 0;
        foreach ($items as $item) {
            $durationSeconds = (int) ($durationsSeconds[$item->book_id] ?? 0);
            if ($durationSeconds <= 0) {
                continue;
            }
            $targetSeconds += $durationSeconds;
            $progressSeconds += $item->status === 'completed'
                ? $durationSeconds
                : min($durationSeconds, (int) ($positionsSeconds[$item->book_id] ?? 0));
        }

        return [
            'amount' => (int) round($progressSeconds / 60),
            'target' => max(1, (int) round($targetSeconds / 60)),
        ];
    }

    /** @return array<int, array{type:string,book_id:int,title:string,finished_at:?string,minutes:?int}> */
    private function playlistCompletionEntries(ListeningGoal $goal): array
    {
        $playlist = $goal->playlist;
        if ($playlist === null) {
            return [];
        }

        $items = UserBookStatus::where('playlist_id', $playlist->id)
            ->where('user_id', $goal->user_id)
            ->whereNotNull('book_id')
            ->with('book')
            ->get();

        if ($goal->metric === 'playlist_books_finished') {
            return $items->map(fn (UserBookStatus $item): array => [
                'type'        => 'book',
                'book_id'     => $item->book_id,
                'title'       => $item->book->title,
                'finished_at' => $item->marked_read_at?->toDateString(),
                'minutes'     => null,
            ])->values()->all();
        }

        $bookIds = $items->pluck('book_id');
        $positionsSeconds = BookProgress::where('user_id', $goal->user_id)
            ->whereIn('book_id', $bookIds)
            ->selectRaw('book_id, MAX(current_position_seconds) as position')
            ->groupBy('book_id')
            ->pluck('position', 'book_id');

        return $items->map(fn (UserBookStatus $item): array => [
            'type'        => 'book',
            'book_id'     => $item->book_id,
            'title'       => $item->book->title,
            'finished_at' => null,
            'minutes'     => $item->status === 'completed'
                ? (int) round(($item->book->duration ?? 0) / 60)
                : (int) round(((int) ($positionsSeconds[$item->book_id] ?? 0)) / 60),
        ])->values()->all();
    }

    /** @return array<int, array{type:string,book_id:int,title:string,finished_at:string}> */
    private function booksFinishedEntries(ListeningGoal $goal, Carbon $periodStart, Carbon $periodEnd): array
    {
        $userId = Auth::id();

        return $this->bookCompletionService
            ->getCompletedBooksWithTitles($userId, $periodStart, $periodEnd, $goal->genre_id)
            ->sortByDesc('finished_at')
            ->map(fn (array $entry): array => [
                'type'        => 'book',
                'book_id'     => $entry['book_id'],
                'title'       => $entry['title'],
                'finished_at' => $entry['finished_at']->toDateString(),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{type:string,book_id:?int,title:?string,progress_minutes:int,target_minutes:int,remaining_minutes:int,target_date:?string}> */
    private function bookCompletionEntries(ListeningGoal $goal, int $progressAmount): array
    {
        return [[
            'type'              => 'book_completion',
            'book_id'           => $goal->book_id,
            'title'             => $goal->book_id !== null ? $goal->book->title : $goal->book_title,
            'progress_minutes'  => $progressAmount,
            'target_minutes'    => $goal->target_minutes,
            'remaining_minutes' => max(0, $goal->target_minutes - $progressAmount),
            'target_date'       => $goal->end_date?->toDateString(),
        ]];
    }

    /** @return array<int, array{type:string,date:string,minutes:int,books:array<int,array{book_id:int,title:string,minutes:int}>}> */
    private function hourEntries(ListeningGoal $goal, Carbon $periodStart, Carbon $periodEnd): array
    {
        $bookIdFilter = $this->resolveMatchingBookIds($goal);
        $sessions = $this->sessionsInRange(Auth::id(), $periodStart, $periodEnd, $bookIdFilter);
        $bookTitles = Book::whereIn('id', $sessions->pluck('book_id')->unique())->pluck('title', 'id');

        return $sessions->groupBy('listening_date')
            ->map(function (Collection $dayRows, string $date) use ($bookTitles): array {
                $books = $dayRows->groupBy('book_id')
                    ->map(fn (Collection $rows, int $bookId): array => [
                        'book_id' => $bookId,
                        'title'   => (string) ($bookTitles[$bookId] ?? 'Unknown'),
                        'minutes' => (int) round($rows->sum('seconds_listened') / 60),
                    ])
                    ->values()
                    ->all();

                return [
                    'type'    => 'day',
                    'date'    => $date,
                    'minutes' => array_sum(array_column($books, 'minutes')),
                    'books'   => $books,
                ];
            })
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    private function progressPercent(ListeningGoal $goal, int $progressAmount): float
    {
        return $goal->target_minutes > 0
            ? min(100, round(($progressAmount / $goal->target_minutes) * 100, 1))
            : 0;
    }

    private function formatGoalWithProgress(ListeningGoal $goal): array
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod($goal);

        if ($this->isPlaylistCompletionMetric($goal->metric)) {
            $amounts = $this->playlistCompletionAmounts($goal);
            $targetMinutes = $amounts['target'];
            $progressAmount = $amounts['amount'];
            $progressPercent = $targetMinutes > 0 ? min(100, round(($progressAmount / $targetMinutes) * 100, 1)) : 0;
            $elapsedPercent = 100.0;
        } else {
            $targetMinutes = $goal->target_minutes;
            $progressAmount = $this->computeProgressAmount($goal, $periodStart, $periodEnd);
            $progressPercent = $this->progressPercent($goal, $progressAmount);
            $elapsedPercent = $this->elapsedPercent($periodStart, $periodEnd);
        }

        return [
            'id'               => $goal->id,
            'period_type'      => $goal->period_type,
            'metric'           => $goal->metric,
            'target_minutes'   => $targetMinutes,
            'progress_minutes' => $progressAmount,
            'progress_percent' => $progressPercent,
            'genre_id'         => $goal->genre_id,
            'genre_name'       => $goal->genre?->name,
            'playlist_id'      => $goal->playlist_id,
            'playlist_name'    => $goal->playlist?->name,
            'series_id'        => $goal->series_id,
            'series_name'      => $goal->series?->name,
            'author_id'        => $goal->author_id,
            'author_name'      => $goal->author?->name,
            'book_id'          => $goal->book_id,
            'book_title'       => $goal->book_id !== null ? $goal->book->title : $goal->book_title,
            'book_author'      => $goal->book_author,
            'start_date'       => $periodStart->toDateString(),
            'end_date'         => $periodEnd->toDateString(),
            'elapsed_percent'  => $elapsedPercent,
            'is_active'        => $goal->is_active,
            'created_at'       => $goal->created_at?->toIso8601String(),
        ];
    }
}
