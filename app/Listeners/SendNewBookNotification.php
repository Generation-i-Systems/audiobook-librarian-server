<?php

namespace App\Listeners;

use App\Events\NewBookAdded;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendNewBookNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(NewBookAdded $event)
    {
        $book = $event->book;

        // Find followers of the author
        $authorFollowers = User::whereHas('follows', function ($query) use ($book) {
            $query->where('followable_type', 'author')
                  ->where('followable_id', $book->author_id);
        })->get();

        // Find followers of the series
        $seriesFollowers = User::whereHas('follows', function ($query) use ($book) {
            $query->where('followable_type', 'series')
                  ->where('followable_id', $book->series);
        })->get();

        $followers = $authorFollowers->merge($seriesFollowers)->unique('id');

        foreach ($followers as $follower) {
            // Send push notification (using a service like Firebase Cloud Messaging)
            $this->sendPushNotification($follower, $book);
        }
    }

    private function sendPushNotification(User $user, Book $book)
    {
        // Implement your push notification logic here (Firebase Cloud Messaging)
        // For example:
        $deviceToken = $user->device_token; // Store device tokens in the users table
        if ($deviceToken) {
            // Send notification
            // You'll need to use a library like Firebase Admin SDK to send the notification
            Log::info("Sending push notification to user {$user->id} for book {$book->title}");
        }
    }
}
