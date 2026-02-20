<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookPosition;
use App\Models\BookProgress;
use App\Models\Device;
use App\Services\BadgeService;
use App\Services\PositionMaterializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PositionSyncController extends Controller
{
    /**
     * Get positions from other devices.
     * Reads from book_positions (event-materialized) with fallback to book_progress.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $requestingDeviceId = $request->header('X-Device-ID');

        $positions = $this->getPositionsFromBookPositions($user, $requestingDeviceId, $request);

        if ($positions->isEmpty()) {
            $positions = $this->getPositionsFromBookProgress($user, $requestingDeviceId, $request);
        }

        return response()->json([
            'server_timestamp' => now()->toIso8601String(),
            'positions' => $positions,
        ]);
    }

    private function getPositionsFromBookPositions($user, ?string $excludeDeviceId, Request $request)
    {
        $query = BookPosition::where('book_positions.user_id', $user->id);

        if ($excludeDeviceId) {
            $query->where('book_positions.device_id', '!=', $excludeDeviceId);
        }

        $query->join('devices', function ($join) use ($user) {
            $join->on('book_positions.device_id', '=', 'devices.device_id')
                ->where('devices.user_id', '=', $user->id)
                ->where('devices.sync_enabled', '=', true);
        });

        if ($request->has('since')) {
            $query->where('book_positions.updated_at', '>', $request->input('since'));
        }

        if ($request->has('book_ids')) {
            $bookIds = explode(',', $request->input('book_ids'));
            $query->whereIn('book_positions.book_id', $bookIds);
        }

        $allPositions = $query->select('book_positions.*')
            ->orderBy('book_positions.updated_at', 'desc')
            ->get();

        $latestPerBook = $allPositions->groupBy('book_id')->map(fn ($group) => $group->first())->values();

        $devices = Device::where('user_id', $user->id)
            ->whereIn('device_id', $latestPerBook->pluck('device_id')->unique())
            ->get()
            ->keyBy('device_id');

        return $latestPerBook->map(function ($pos) use ($devices) {
            $device = $devices->get($pos->device_id);

            return [
                'book_id' => $pos->book_id,
                'device_id' => $pos->device_id,
                'device_name' => $device->name ?? 'Unknown Device',
                'position_ms' => $pos->position_ms,
                'progress_percentage' => (float) $pos->progress_percentage,
                'current_chapter' => $pos->current_chapter,
                'current_chapter_name' => $pos->current_chapter_name,
                'is_finished' => $pos->completed,
                'updated_at' => $pos->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        });
    }

    private function getPositionsFromBookProgress($user, ?string $excludeDeviceId, Request $request)
    {
        $query = BookProgress::where('book_progress.user_id', $user->id);

        if ($excludeDeviceId) {
            $query->where('book_progress.device_id', '!=', $excludeDeviceId);
        }

        $query->join('devices', function ($join) use ($user) {
            $join->on('book_progress.device_id', '=', 'devices.device_id')
                ->where('devices.user_id', '=', $user->id)
                ->where('devices.sync_enabled', '=', true);
        });

        if ($request->has('since')) {
            $query->where('book_progress.updated_at', '>', $request->input('since'));
        }

        if ($request->has('book_ids')) {
            $bookIds = explode(',', $request->input('book_ids'));
            $query->whereIn('book_progress.book_id', $bookIds);
        }

        $allProgress = $query->select('book_progress.*')
            ->orderBy('book_progress.updated_at', 'desc')
            ->get();

        $latestPerBook = $allProgress->groupBy('book_id')->map(fn ($group) => $group->first())->values();

        $devices = Device::where('user_id', $user->id)
            ->whereIn('device_id', $latestPerBook->pluck('device_id')->unique())
            ->get()
            ->keyBy('device_id');

        return $latestPerBook->map(function ($progress) use ($devices) {
            $device = $devices->get($progress->device_id);

            return [
                'book_id' => $progress->book_id,
                'device_id' => $progress->device_id,
                'device_name' => $device->name ?? 'Unknown Device',
                'position_ms' => $progress->current_position_seconds * 1000,
                'progress_percentage' => (float) $progress->progress_percentage,
                'current_chapter' => $progress->current_chapter,
                'current_chapter_name' => $progress->current_chapter_name,
                'is_finished' => $progress->completed,
                'updated_at' => $progress->updated_at->toIso8601String(),
            ];
        });
    }

    public function show(Request $request, int $bookId): JsonResponse
    {
        $user = auth()->user();

        $progress = BookProgress::where('user_id', $user->id)
            ->where('book_id', $bookId)
            ->orderBy('updated_at', 'desc')
            ->get();

        $devices = Device::where('user_id', $user->id)
            ->whereIn('device_id', $progress->pluck('device_id')->unique())
            ->get()
            ->keyBy('device_id');

        $positions = $progress->map(function ($p) use ($devices) {
            $device = $devices->get($p->device_id);

            return [
                'device_id' => $p->device_id,
                'device_name' => $device->name ?? 'Unknown Device',
                'position_ms' => $p->current_position_seconds * 1000,
                'progress_percentage' => (float) $p->progress_percentage,
                'is_finished' => $p->completed,
                'updated_at' => $p->updated_at->toIso8601String(),
            ];
        });

        return response()->json([
            'book_id' => $bookId,
            'positions' => $positions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        $deviceId = $request->header('X-Device-ID');
        $deviceName = $request->header('X-Device-Name');

        if (! $deviceId || ! $deviceName) {
            return response()->json([
                'error' => 'X-Device-ID and X-Device-Name headers are required',
            ], 400);
        }

        $validated = $request->validate([
            'client_timestamp' => 'required|date',
            'positions' => 'required|array|min:1',
            'positions.*.book_id' => 'required|integer',
            'positions.*.position_ms' => 'required|integer|min:0',
            'positions.*.progress_percentage' => 'required|numeric|min:0|max:100',
            'positions.*.current_chapter' => 'nullable|integer|min:1',
            'positions.*.current_chapter_name' => 'nullable|string|max:255',
            'positions.*.is_finished' => 'nullable|boolean',
            'positions.*.updated_at' => 'required|date',
        ]);

        $conflicts = [];
        $accepted = 0;
        $positionMaterializer = app(PositionMaterializer::class);

        foreach ($validated['positions'] as $pos) {
            $existingProgress = BookProgress::where('user_id', $user->id)
                ->where('book_id', $pos['book_id'])
                ->where('device_id', '!=', $deviceId)
                ->orderBy('updated_at', 'desc')
                ->first();

            $posUpdatedAt = \Carbon\Carbon::parse($pos['updated_at']);

            if ($existingProgress && $existingProgress->updated_at > $posUpdatedAt) {
                $device = Device::where('device_id', $existingProgress->device_id)
                    ->where('user_id', $user->id)
                    ->first();

                $conflicts[] = [
                    'book_id' => $pos['book_id'],
                    'server_position_ms' => $existingProgress->current_position_seconds * 1000,
                    'server_device_id' => $existingProgress->device_id,
                    'server_device_name' => $device->name ?? 'Unknown Device',
                    'server_updated_at' => $existingProgress->updated_at->toIso8601String(),
                ];
            }

            BookProgress::updateOrCreate(
                [
                    'book_id' => $pos['book_id'],
                    'device_id' => $deviceId,
                    'user_id' => $user->id,
                ],
                [
                    'current_position_seconds' => (int) ($pos['position_ms'] / 1000),
                    'progress_percentage' => $pos['progress_percentage'],
                    'current_chapter' => $pos['current_chapter'] ?? null,
                    'current_chapter_name' => $pos['current_chapter_name'] ?? null,
                    'completed' => $pos['is_finished'] ?? false,
                    'completed_at' => ($pos['is_finished'] ?? false) ? now() : null,
                    'last_listened_at' => $pos['updated_at'],
                ]
            );

            $positionMaterializer->materialize([
                'id' => 'position-sync-' . $pos['book_id'] . '-' . $deviceId . '-' . time(),
                'user_id' => $user->id,
                'book_id' => $pos['book_id'],
                'event_type' => 'PLAY_PAUSE',
                'timestamp_ms' => (int) ($posUpdatedAt->timestamp * 1000),
                'position_ms' => $pos['position_ms'],
                'metadata' => [
                    'chapterIndex' => $pos['current_chapter'] ?? null,
                    'chapterName' => $pos['current_chapter_name'] ?? null,
                ],
                'device_id' => $deviceId,
            ]);

            $accepted++;
        }

        $badgesEarned = [];
        try {
            $badgeService = app(BadgeService::class);
            $newBadges = $badgeService->evaluateUserBadges((string) $user->id, $deviceId);
            if (! empty($newBadges)) {
                $badgesEarned = array_map(function ($userBadge) {
                    return [
                        'id' => $userBadge->badge->id,
                        'key' => $userBadge->badge->key,
                        'name' => $userBadge->badge->name,
                        'description' => $userBadge->badge->description,
                        'icon' => $userBadge->badge->icon,
                        'tier' => $userBadge->badge->tier,
                        'points' => $userBadge->badge->points,
                        'earned_at' => $userBadge->earned_at->toISOString(),
                    ];
                }, $newBadges);
            }
        } catch (\Exception $e) {
            Log::warning('Badge evaluation failed during position sync', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $response = [
            'server_timestamp' => now()->toIso8601String(),
            'accepted' => $accepted,
            'conflicts' => $conflicts,
        ];

        if (! empty($badgesEarned)) {
            $response['badges_earned'] = $badgesEarned;
        }

        return response()->json($response);
    }
}
