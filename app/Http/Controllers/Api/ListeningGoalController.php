<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListeningGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ControllerDatabaseService as ControllerDatabase;

class ListeningGoalController extends Controller
{
    /** GET /goals/listening — list all active listening goals with current progress */
    public function index(): JsonResponse
    {
        $goals = ListeningGoal::where('user_id', Auth::id())
            ->where('is_active', true)
            ->with(['genre', 'playlist'])
            ->orderBy('period_type')
            ->get()
            ->map(fn ($goal) => $this->formatGoalWithProgress($goal));

        return response()->json(['goals' => $goals]);
    }

    /** POST /goals/listening — create a new listening goal */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period_type'    => 'required|string|in:day,week,month,year',
            'metric'         => 'required|string|in:total_hours,genre_hours,playlist_hours,fiction_hours,nonfiction_hours',
            'target_minutes' => 'required|integer|min:1|max:14400',
            'genre_id'       => 'nullable|integer|exists:genres,id',
            'playlist_id'    => 'nullable|integer|exists:playlists,id',
        ]);

        if (!empty($validated['playlist_id'])) {
            abort_if(
                \App\Models\Playlist::where('id', $validated['playlist_id'])
                    ->where('user_id', Auth::id())->doesntExist(),
                403,
                'Playlist not found'
            );
        }

        $goal = ListeningGoal::create([
            'user_id'        => Auth::id(),
            'period_type'    => $validated['period_type'],
            'metric'         => $validated['metric'],
            'target_minutes' => $validated['target_minutes'],
            'genre_id'       => $validated['genre_id'] ?? null,
            'playlist_id'    => $validated['playlist_id'] ?? null,
            'is_active'      => true,
        ]);

        $goal->load(['genre', 'playlist']);
        return response()->json(['goal' => $this->formatGoalWithProgress($goal)], 201);
    }

    /** PUT /goals/listening/{goal} — update a goal */
    public function update(Request $request, ListeningGoal $goal): JsonResponse
    {
        abort_if($goal->user_id !== Auth::id(), 403);

        $validated = $request->validate([
            'period_type'    => 'sometimes|string|in:day,week,month,year',
            'metric'         => 'sometimes|string|in:total_hours,genre_hours,playlist_hours,fiction_hours,nonfiction_hours',
            'target_minutes' => 'sometimes|integer|min:1|max:14400',
            'genre_id'       => 'nullable|integer|exists:genres,id',
            'playlist_id'    => 'nullable|integer|exists:playlists,id',
            'is_active'      => 'sometimes|boolean',
        ]);

        if (!empty($validated['playlist_id'])) {
            abort_if(
                \App\Models\Playlist::where('id', $validated['playlist_id'])
                    ->where('user_id', Auth::id())->doesntExist(),
                403,
                'Playlist not found'
            );
        }

        $goal->update($validated);
        $goal->load(['genre', 'playlist']);

        return response()->json(['goal' => $this->formatGoalWithProgress($goal)]);
    }

    /** DELETE /goals/listening/{goal} — delete a goal */
    public function destroy(ListeningGoal $goal): JsonResponse
    {
        abort_if($goal->user_id !== Auth::id(), 403);
        $goal->delete();
        return response()->json(['message' => 'Goal deleted']);
    }

    private function computeProgressMinutes(ListeningGoal $goal): int
    {
        $userId = Auth::id();
        $periodStart = match ($goal->period_type) {
            'day'   => now()->startOfDay(),
            'week'  => now()->startOfWeek(\Carbon\Carbon::SUNDAY),
            'month' => now()->startOfMonth(),
            'year'  => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

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
            ->where('listening_statistics.listening_date', '>=', $periodStart->toDateString());

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
        }

        $seconds = $query->sum('listening_statistics.seconds_listened');
        return (int) round($seconds / 60);
    }

    private function formatGoalWithProgress(ListeningGoal $goal): array
    {
        $progressMinutes = $this->computeProgressMinutes($goal);
        $percentage = $goal->target_minutes > 0
            ? min(100, round(($progressMinutes / $goal->target_minutes) * 100, 1))
            : 0;

        return [
            'id'               => $goal->id,
            'period_type'      => $goal->period_type,
            'metric'           => $goal->metric,
            'target_minutes'   => $goal->target_minutes,
            'progress_minutes' => $progressMinutes,
            'progress_percent' => $percentage,
            'genre_id'         => $goal->genre_id,
            'genre_name'       => $goal->genre?->name,
            'playlist_id'      => $goal->playlist_id,
            'playlist_name'    => $goal->playlist?->name,
            'is_active'        => $goal->is_active,
            'created_at'       => $goal->created_at?->toIso8601String(),
        ];
    }
}
