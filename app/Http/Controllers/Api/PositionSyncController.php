<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookProgress;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PositionSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $requestingDeviceId = $request->header('X-Device-ID');

        $query = BookProgress::where('book_progress.user_id', $user->id);

        if ($requestingDeviceId) {
            $query->where('book_progress.device_id', '!=', $requestingDeviceId);
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

        $latestPerBook = $allProgress->groupBy('book_id')->map(function ($group) {
            return $group->first();
        })->values();

        $devices = Device::where('user_id', $user->id)
            ->whereIn('device_id', $latestPerBook->pluck('device_id')->unique())
            ->get()
            ->keyBy('device_id');

        $positions = $latestPerBook->map(function ($progress) use ($devices) {
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

        return response()->json([
            'server_timestamp' => now()->toIso8601String(),
            'positions' => $positions,
        ]);
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

        if (!$deviceId || !$deviceName) {
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

            $accepted++;
        }

        return response()->json([
            'server_timestamp' => now()->toIso8601String(),
            'accepted' => $accepted,
            'conflicts' => $conflicts,
        ]);
    }
}
