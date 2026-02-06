<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $devices = Device::where('user_id', $user->id)
            ->orderBy('last_seen', 'desc')
            ->get()
            ->map(function ($device) {
                return [
                    'device_id' => $device->device_id,
                    'name' => $device->name,
                    'last_seen' => $device->last_seen->toIso8601String(),
                    'sync_enabled' => $device->sync_enabled,
                    'created_at' => $device->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'devices' => $devices,
        ]);
    }

    public function update(Request $request, string $deviceId): JsonResponse
    {
        $user = auth()->user();

        $device = Device::where('device_id', $deviceId)
            ->where('user_id', $user->id)
            ->first();

        if (!$device) {
            return response()->json([
                'error' => 'Device not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $device->name = $validated['name'];
        $device->save();

        return response()->json([
            'device_id' => $device->device_id,
            'name' => $device->name,
            'last_seen' => $device->last_seen->toIso8601String(),
            'sync_enabled' => $device->sync_enabled,
        ]);
    }

    public function destroy(string $deviceId): JsonResponse
    {
        $user = auth()->user();

        $device = Device::where('device_id', $deviceId)
            ->where('user_id', $user->id)
            ->first();

        if (!$device) {
            return response()->json([
                'error' => 'Device not found',
            ], 404);
        }

        $device->delete();

        return response()->json([], 204);
    }

    public function updateSyncEnabled(Request $request, string $deviceId): JsonResponse
    {
        $user = auth()->user();

        $device = Device::where('device_id', $deviceId)
            ->where('user_id', $user->id)
            ->first();

        if (!$device) {
            return response()->json([
                'error' => 'Device not found',
            ], 404);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $device->sync_enabled = $validated['enabled'];
        $device->save();

        return response()->json([
            'device_id' => $device->device_id,
            'sync_enabled' => $device->sync_enabled,
        ]);
    }
}
