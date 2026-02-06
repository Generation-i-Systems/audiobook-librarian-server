<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

class IdentifyDevice
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->header('X-Device-ID');
        $deviceName = $request->header('X-Device-Name');

        if ($deviceId && $deviceName && auth()->check()) {
            $user = auth()->user();

            try {
                $device = Device::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'user_id' => $user->id,
                    ],
                    [
                        'name' => $deviceName,
                        'last_seen' => now(),
                    ]
                );

                $request->attributes->set('device', $device);
                $request->attributes->set('device_id', $deviceId);
                $request->attributes->set('device_name', $deviceName);
            } catch (\Exception $e) {
                Log::error('Failed to register/update device', [
                    'device_id' => $deviceId,
                    'device_name' => $deviceName,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }
}
