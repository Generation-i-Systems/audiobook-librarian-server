<?php

namespace App\Listeners;

use App\Contracts\DocumentStoreServiceInterface;
use App\Events\NewBookAdded;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class SendNewBookNotification
{
    protected $documentStore;

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        $this->firestore = $documentStore;
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(NewBookAdded $event)
    {
        $book = $event->book;
        $followers = [];
        $bookId = $book['id'] ?? '';
        $bookTitle = $book['title'] ?? 'Unknown Book';
        $bookAuthors = $book['author'] ?? [];
        $bookSeries = $book['series'] ?? [];

        // Ensure authors is always an array
        if (!is_array($bookAuthors)) {
            $bookAuthors = [$bookAuthors];
        }

        try {
            // Get all users who follow any of the book's authors
            if (!empty($bookAuthors)) {
                $authorFollowers = [];
                foreach ($bookAuthors as $author) {
                    $followersQuery = $this->firestore->getClient()
                        ->collection('user_follows')
                        ->where('followable_type', '=', 'author')
                        ->where('followable_id', '=', $author);

                    $authorFollowersDocs = $followersQuery->documents();
                    foreach ($authorFollowersDocs as $doc) {
                        if ($doc->exists()) {
                            $followData = $doc->data();
                            $followers[$followData['user_id']] = true;
                        }
                    }
                }
            }

            // Get all users who follow the book's series
            if (!empty($bookSeries)) {
                foreach (array_keys($bookSeries) as $seriesName) {
                    $seriesFollowers = $this->firestore->getClient()
                        ->collection('user_follows')
                        ->where('followable_type', '=', 'series')
                        ->where('followable_id', '=', $seriesName)
                        ->documents();

                    foreach ($seriesFollowers as $doc) {
                        if ($doc->exists()) {
                            $followData = $doc->data();
                            $followers[$followData['user_id']] = true;
                        }
                    }
                }
            }

            // Get user details for each follower and send notifications
            foreach (array_keys($followers) as $userId) {
                $userDoc = $this->firestore->getClient()
                    ->collection('users')
                    ->document($userId)
                    ->snapshot();

                if ($userDoc->exists()) {
                    $user = $userDoc->data();
                    $this->sendPushNotification($user, [
                        'id' => $bookId,
                        'title' => $bookTitle,
                        'authors' => $bookAuthors,
                        'series' => $bookSeries,
                    ]);
                }
            }

            Log::info(sprintf(
                'Sent new book notifications to %d followers for book: %s',
                count($followers),
                $bookTitle
            ));
        } catch (\Exception $e) {
            Log::error('Error sending new book notifications: ' . $e->getMessage(), [
                'book_id' => $bookId,
                'book_title' => $bookTitle,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Send push notification to a user's device
     *
     * @param  array  $user  User data from Firestore
     * @param  array  $book  Book data from Firestore
     */
    private function sendPushNotification(array $user, array $book)
    {
        try {
            // Skip if user has no device token
            if (empty($user['fcm_tokens'])) {
                return;
            }

            $title = 'New Book Available';
            // Format authors for display
            $authors = $book['author'] ?? [];
            $authorText = !empty($authors) ? (is_array($authors) ? implode(', ', $authors) : $authors) : 'an unknown author';

            $body = sprintf(
                '%s by %s has been added to the library',
                $book['title'] ?? 'A new book',
                $authorText
            );

            // Create notification payload
            $notification = Notification::create($title, $body);

            // Create message for each device token
            foreach ((array) $user['fcm_tokens'] as $token) {
                $message = CloudMessage::withTarget('token', $token)
                    ->withNotification($notification)
                    ->withData([
                        'type' => 'new_book',
                        'book_id' => $book['id'] ?? '',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ]);

                // Send the message using Firebase Admin SDK
                $messaging = app('firebase.messaging');
                $messaging->send($message);

                Log::debug(sprintf(
                    'Sent push notification to user %s for book %s',
                    $user['id'] ?? 'unknown',
                    $book['title'] ?? 'unknown'
                ));
            }
        } catch (\Exception $e) {
            Log::error('Error sending push notification: ' . $e->getMessage(), [
                'user_id' => $user['id'] ?? 'unknown',
                'book_id' => $book['id'] ?? 'unknown',
            ]);
        }
    }
}
