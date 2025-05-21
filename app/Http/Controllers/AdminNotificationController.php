<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminNotificationController extends Controller
{
    public function sendNotification(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'user_id' => 'nullable|string', // Optional: specific user (Firestore id)
        ]);

        $message = $request->input('message');
        $userId = $request->input('user_id');

        $firestore = new FirestoreService();
        if ($userId) {
            // Send to a specific user
            $userDoc = $firestore->db->collection('users')->document($userId)->snapshot();
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
            $users = $firestore->db->collection('users')->documents();
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
