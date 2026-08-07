<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListeningGoal;
use App\Models\Playlist;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\BookCompletionService;
use App\Services\ControllerDatabaseService as ControllerDatabase;

class ListeningGoalController extends Controller
{
    public function __construct(private readonly BookCompletionService $bookCompletionService)
    {
    }

    private const METRICS = 'total_hours,genre_hours,playlist_hours,fiction_hours,nonfiction_hours,books_finished,series_hours,author_hours,book_hours';

    /** GET /goals/listening — list all active listening goals with current progress */
    public function index(): JsonResponse
    {
        $goals = ListeningGoal::where('user_id', Auth::id())
            ->where('is_active', true)
            ->with(['genre', 'playlist', 'series', 'author', 'book'])
            ->orderBy('period_type')
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
            'target_minutes' => 'required|integer|min:1|max:14400',
            'genre_id'       => 'nullable|integer|exists:genres,id',
            'playlist_id'    => 'nullable|integer|exists:playlists,id',
            'series_id'      => 'nullable|integer|exists:series,id',
            'author_id'      => 'nullable|integer|exists:authors,id',
            'book_id'        => 'nullable|integer|exists:books,id',
            'start_date'     => 'required_if:period_type,custom|nullable|date',
            'end_date'       => 'required_if:period_type,custom|nullable|date|after_or_equal:start_date',
        ]);

        $this->assertCustomRangeConsistency($validated['period_type'], $validated['start_date'] ?? null, $validated['end_date'] ?? null);
        $this->assertPlaylistOwnership($validated['playlist_id'] ?? null);

        $goal = ListeningGoal::create([
            'user_id'        => Auth::id(),
            'period_type'    => $validated['period_type'],
            'metric'         => $validated['metric'],
            'target_minutes' => $validated['target_minutes'],
            'genre_id'       => $validated['genre_id'] ?? null,
            'playlist_id'    => $validated['playlist_id'] ?? null,
            'series_id'      => $validated['series_id'] ?? null,
            'author_id'      => $validated['author_id'] ?? null,
            'book_id'        => $validated['book_id'] ?? null,
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
            'start_date'     => 'sometimes|nullable|date',
            'end_date'       => 'sometimes|nullable|date|after_or_equal:start_date',
            'is_active'      => 'sometimes|boolean',
        ]);

        $resolvedPeriodType = $validated['period_type'] ?? $goal->period_type;
        $resolvedStartDate = array_key_exists('start_date', $validated) ? $validated['start_date'] : $goal->start_date?->toDateString();
        $resolvedEndDate = array_key_exists('end_date', $validated) ? $validated['end_date'] : $goal->end_date?->toDateString();
        $this->assertCustomRangeConsistency($resolvedPeriodType, $resolvedStartDate, $resolvedEndDate);
        $this->assertPlaylistOwnership($validated['playlist_id'] ?? null);

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
        $progressAmount = $this->computeProgressAmount($goal, $periodStart, $periodEnd);
        $progressPercent = $this->progressPercent($goal, $progressAmount);

        $entries = $goal->metric === 'books_finished'
            ? $this->booksFinishedEntries($goal, $periodStart, $periodEnd)
            : $this->hourEntries($goal, $periodStart, $periodEnd);

        return response()->json([
            'period_start'     => $periodStart->toDateString(),
            'period_end'       => $periodEnd->toDateString(),
            'elapsed_percent'  => $this->elapsedPercent($periodStart, $periodEnd),
            'progress_percent' => $progressPercent,
            'metric'           => $goal->metric,
            'entries'          => $entries,
        ]);
    }

    private function assertCustomRangeConsistency(string $periodType, ?string $startDate, ?string $endDate): void
    {
        if ($periodType === 'custom') {
            abort_if(empty($startDate) || empty($endDate), 422, 'custom period requires start_date and end_date');
        } else {
            abort_if(!empty($startDate) || !empty($endDate), 422, 'start_date/end_date are only allowed when period_type is custom');
        }
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

        $seconds = $this->scopedListeningQuery($goal, $periodStart, $periodEnd)
            ->sum('listening_statistics.seconds_listened');

        return (int) round($seconds / 60);
    }

    private function scopedListeningQuery(ListeningGoal $goal, Carbon $periodStart, Carbon $periodEnd)
    {
        $userId = Auth::id();
        $deviceIds = ControllerDatabase::table('devices')
            ->where('user_id', $userId)
            ->pluck('device_id');

        $query = ControllerDatabase::table('listening_statistics')
            ->where(function ($statsQuery) use ($userId, $deviceIds): void {
                $statsQuery->where('listening_statistics.user_id', $userId);

                if ($deviceIds->isNotEmpty()) {
                    $statsQuery->orWhereIn('listening_statistics.device_id', $deviceIds);
                }
            })
            ->where('listening_statistics.listening_date', '>=', $periodStart->toDateString())
            ->where('listening_statistics.listening_date', '<=', $periodEnd->toDateString());

        switch ($goal->metric) {
            case 'genre_hours':
                $query->join('books', 'books.id', '=', 'listening_statistics.book_id')
                    ->join('book_genre', 'book_genre.book_id', '=', 'books.id')
                    ->join('genres', function ($join) {
                        $join->on('genres.id', '=', 'book_genre.genre_id')
                            ->whereNull('genres.deleted_at');
                    })
                    ->where('book_genre.genre_id', $goal->genre_id);
                break;
            case 'fiction_hours':
                $query->join('books', 'books.id', '=', 'listening_statistics.book_id')
                    ->join('book_genre', 'book_genre.book_id', '=', 'books.id')
                    ->join('genres', function ($join) {
                        $join->on('genres.id', '=', 'book_genre.genre_id')
                            ->whereNull('genres.deleted_at');
                    })
                    ->where('genres.is_fiction', true);
                break;
            case 'nonfiction_hours':
                $query->join('books', 'books.id', '=', 'listening_statistics.book_id')
                    ->join('book_genre', 'book_genre.book_id', '=', 'books.id')
                    ->join('genres', function ($join) {
                        $join->on('genres.id', '=', 'book_genre.genre_id')
                            ->whereNull('genres.deleted_at');
                    })
                    ->where('genres.is_fiction', false);
                break;
            case 'playlist_hours':
                $query->join('user_book_status', function ($join) use ($userId, $goal) {
                    $join->on('user_book_status.book_id', '=', 'listening_statistics.book_id')
                        ->where('user_book_status.user_id', $userId)
                        ->where('user_book_status.playlist_id', $goal->playlist_id);
                });
                break;
            case 'series_hours':
                $query->join('book_series', 'book_series.book_id', '=', 'listening_statistics.book_id')
                    ->where('book_series.series_id', $goal->series_id);
                break;
            case 'author_hours':
                $query->join('author_book', 'author_book.book_id', '=', 'listening_statistics.book_id')
                    ->where('author_book.author_id', $goal->author_id);
                break;
            case 'book_hours':
                $query->where('listening_statistics.book_id', $goal->book_id);
                break;
        }

        return $query;
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

    /** @return array<int, array{type:string,date:string,minutes:int,books:array<int,array{book_id:int,title:string,minutes:int}>}> */
    private function hourEntries(ListeningGoal $goal, Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = $this->scopedListeningQuery($goal, $periodStart, $periodEnd)
            ->join('books', 'books.id', '=', 'listening_statistics.book_id')
            ->selectRaw(
                'DATE(listening_statistics.listening_date) as listening_day, ' .
                'listening_statistics.book_id as book_id, ' .
                'books.title as title, ' .
                'SUM(listening_statistics.seconds_listened) as secs'
            )
            ->groupBy('listening_day', 'listening_statistics.book_id', 'books.title')
            ->orderByDesc('listening_day')
            ->get();

        $byDate = $rows->groupBy('listening_day');

        return $byDate->map(function ($dayRows, $date): array {
            $books = $dayRows->map(fn ($row): array => [
                'book_id' => (int) $row->book_id,
                'title'   => (string) $row->title,
                'minutes' => (int) round($row->secs / 60),
            ])->values()->all();

            return [
                'type'    => 'day',
                'date'    => (string) $date,
                'minutes' => array_sum(array_column($books, 'minutes')),
                'books'   => $books,
            ];
        })->sortByDesc('date')->values()->all();
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
        $progressAmount = $this->computeProgressAmount($goal, $periodStart, $periodEnd);

        return [
            'id'               => $goal->id,
            'period_type'      => $goal->period_type,
            'metric'           => $goal->metric,
            'target_minutes'   => $goal->target_minutes,
            'progress_minutes' => $progressAmount,
            'progress_percent' => $this->progressPercent($goal, $progressAmount),
            'genre_id'         => $goal->genre_id,
            'genre_name'       => $goal->genre?->name,
            'playlist_id'      => $goal->playlist_id,
            'playlist_name'    => $goal->playlist?->name,
            'series_id'        => $goal->series_id,
            'series_name'      => $goal->series?->name,
            'author_id'        => $goal->author_id,
            'author_name'      => $goal->author?->name,
            'book_id'          => $goal->book_id,
            'book_title'       => $goal->book?->title,
            'start_date'       => $periodStart->toDateString(),
            'end_date'         => $periodEnd->toDateString(),
            'elapsed_percent'  => $this->elapsedPercent($periodStart, $periodEnd),
            'is_active'        => $goal->is_active,
            'created_at'       => $goal->created_at?->toIso8601String(),
        ];
    }
}
