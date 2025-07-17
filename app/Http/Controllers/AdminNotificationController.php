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
            if ($user) {
                // Ensure the user array has an 'id' key
                if (!isset($user['id'])) {
                    $user['id'] = $userId;
                }
                $this->sendPushNotification($user, $message);

                return back()->with('success', 'Notification sent to specific user!');
            } else {
                return back()->withErrors(['user_id' => 'User not found.']);
            }
        } else {
            // Send to all users
            $users = $documentStore->getAllUsers();
            foreach ($users as $user) {
                // Ensure each user array has an 'id' key
                if (!isset($user['id']) && isset($user['_id'])) {
                    $user['id'] = $user['_id'];
                }
                $this->sendPushNotification($user, $message);
            }

            return back()->with('success', 'Notification sent to all users!');
        }
    }

    /**
     * Send a push notification to a user (stub, replace with FCM logic).
     *
     * @param  array  $user
     * @return void
     */
    private function sendPushNotification($user, string $message)
    {
        // Implement your push notification logic here (Firebase Cloud Messaging)
        // For example:
        $deviceToken = isset($user['device_token']) ? $user['device_token'] : null;
        // TBD: Store device tokens by user
        if ($deviceToken) {
            // Send notification
            // TBD: You'll need to use a library like Firebase Admin SDK to send the notification
            Log::info(
                "Sending push notification to user {$user['id']} with message: {$message}"
            );
        }
    }
}
