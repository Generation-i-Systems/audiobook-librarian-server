<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller for sending admin notifications to users (push/broadcast).
 *
 * Uses DocumentStoreServiceInterface for user lookup.
 *
 * @package App\Http\Controllers
 */
class AdminNotificationController extends Controller
{
    /**
     * Send a notification to all users or a specific user by Firestore ID.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function sendNotification(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'user_id' => 'nullable|string', // Optional: specific user (Firestore id)
        ]);

        $message = $request->input('message');
        $userId = $request->input('user_id');

        $firestore = app(\App\Contracts\DocumentStoreServiceInterface::class);
        if ($userId) {
            // Send to a specific user
            $userDoc = $firestore->getClient()->collection('users')->document($userId)->snapshot();
            if ($userDoc->exists()) {
                $user = $userDoc->data();
                $user['id'] = $userDoc->id();
                $this->sendPushNotification($user, $message);

                return back()->with('success', 'Notification sent to specific user!');
            } else {
                return back()->withErrors(['user_id' => 'User not found.']);
            }
        } else {
            // Send to all users
            $users = $firestore->getClient()->collection('users')->documents();
            foreach ($users as $userDoc) {
                if ($userDoc->exists()) {
                    $user = $userDoc->data();
                    $user['id'] = $userDoc->id();
                    $this->sendPushNotification($user, $message);
                }
            }

            return back()->with('success', 'Notification sent to all users!');
        }
    }

    /**
     * Send a push notification to a user (stub, replace with FCM logic).
     *
     * @param array $user
     * @param string $message
     * @return void
     */
    private function sendPushNotification($user, string $message)
    {
        // Implement your push notification logic here (Firebase Cloud Messaging)
        // For example:
        $deviceToken = isset($user['device_token']) ? $user['device_token'] : null;
        // Store device tokens in the users collection
        if ($deviceToken) {
            // Send notification
            // You'll need to use a library like Firebase Admin SDK to send the notification
            Log::info(
                "Sending push notification to user {$user['id']} with message: {$message}"
            );
        }
    }
}
