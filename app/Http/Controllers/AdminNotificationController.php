<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminNotificationController extends Controller
{
    public function sendNotification(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'user_id' => 'nullable|exists:users,id', // Optional: specific user
        ]);

        $message = $request->input('message');
        $userId = $request->input('user_id');

        if ($userId) {
            // Send to a specific user
            $user = User::findOrFail($userId);
            $this->sendPushNotification($user, $message);
            return back()->with('success', 'Notification sent to specific user!');
        } else {
            // Send to all users
            $users = User::all();
            foreach ($users as $user) {
                $this->sendPushNotification($user, $message);
            }
            return back()->with('success', 'Notification sent to all users!');
        }
    }

    private function sendPushNotification(User $user, string $message)
    {
        // Implement your push notification logic here (Firebase Cloud Messaging)
        // For example:
        $deviceToken = $user->device_token; // Store device tokens in the users table
        if ($deviceToken) {
            // Send notification
            // You'll need to use a library like Firebase Admin SDK to send the notification
            Log::info("Sending push notification to user {$user->id} with message: {$message}");
        }
    }
}
