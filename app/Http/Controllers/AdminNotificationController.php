<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller for sending admin notifications to users (push/broadcast).
 *
 * Uses DocumentStoreServiceInterface for user lookup.
 */
class AdminNotificationController extends Controller
{
    /**
     * Send a notification to all users or a specific user by ID.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function sendNotification(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'user_id' => 'nullable|string', // Optional: specific user (by id)
        ]);

        $message = $request->input('message');
        $userId = $request->input('user_id');

        $documentStore = app(DocumentStoreServiceInterface::class);
        if ($userId) {
            // Send to a specific user
            $user = $documentStore->getUserById($userId);
            if (!$user) {
                return back()->withErrors(['user_id' => 'User not found.']);
            }
        }

        // No FCM/ADM send-side integration exists yet (device push tokens are
        // registered per-device via DeviceController::updatePushToken() into the
        // devices table, but nothing ever dispatches an actual push from here) -
        // this must not claim a notification was sent.
        Log::info('Admin attempted to send a push notification, but push sending is not implemented', [
            'user_id' => $userId,
            'message' => $message,
        ]);

        return back()->withErrors(['message' => 'Sending push notifications is not yet implemented.']);
    }
}
