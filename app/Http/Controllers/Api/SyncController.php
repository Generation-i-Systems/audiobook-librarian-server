<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookProgress;
use App\Models\ClientBook;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class SyncController extends Controller
{
    /**
     * Bulk sync reading progress
     */
    public function progress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clientTimestamp' => 'required|date', // Not strictly used for logic yet, but good for logs/debugging
            'progress' => 'required|array',
            'progress.*.bookId' => 'nullable|integer',
            'progress.*.title' => 'nullable|string',
            'progress.*.author' => 'nullable|string',
            'progress.*.positionMs' => 'required|integer|min:0',
            'progress.*.progressPercentage' => 'required|numeric|min:0|max:100', // Changed to numeric to allow floats
            'progress.*.lastUpdated' => 'required|date',
        ]);

        $userId = Auth::id(); // May be null if using device_id only
        $deviceId = $request->header('X-Device-ID') ?? 'unknown';

        $serverUpdates = [];

        foreach ($validated['progress'] as $item) {
            $bookId = $item['bookId'] ?? null;
            $title = $item['title'] ?? null;
            $author = $item['author'] ?? null;
            $positionMs = $item['positionMs'];
            $percentage = $item['progressPercentage'];
            $clientUpdated = Carbon::parse($item['lastUpdated']);

            $book = null;
            $clientBook = null;
            $isClientBook = false;

            // 1. Try to find by ID (if provided)
            if ($bookId) {
                // First check main books
                $book = Book::find($bookId);
                if (!$book) {
                    // Then check client books
                    $clientBook = ClientBook::find($bookId);
                    if ($clientBook) {
                        $isClientBook = true;
                    }
                }
            }

            // 2. Try to match by Title + Author if ID match failed
            if (!$book && !$clientBook && $title && $author) {
                // Fuzzy/Exact match on Books
                $book = Book::where('title', 'LIKE', $title) // Simple match for now
                    ->whereHas('authors', function ($q) use ($author) {
                        $q->where('name', 'LIKE', "%{$author}%");
                    })
                    ->first();

                if (!$book) {
                    // Match in ClientBooks
                    $clientBook = ClientBook::where('title', $title)
                       ->where('author', $author)
                       ->first();
                    if ($clientBook) {
                        $isClientBook = true;
                    }
                }
            }

            // 3. Fallback: Create ClientBook
            if (!$book && !$clientBook && $title && $author) {
                $clientBook = ClientBook::create([
                    'title' => $title,
                    'author' => $author,
                ]);
                $isClientBook = true;
            }

            // If we still have no book/clientBook (e.g. no title/author provided), skip
            if (!$book && !$clientBook) {
                continue;
            }

            // 4. Resolve State (Merge Logic)
            // Find existing progress for this book/user/device
            $query = BookProgress::where('device_id', $deviceId);
            if ($book) {
                $query->where('book_id', $book->id);
            } else {
                $query->where('client_book_id', $clientBook->id);
            }

            // If user is logged in, we might want to match by user_id across devices too,
            // but for now let's stick to device_id + user_id linking
            if ($userId) {
                // $query->orWhere('user_id', $userId); // Logic gets complex with unique constraints
            }

            $serverProgress = $query->first();

            $shouldUpdateServer = false;
            $shouldUpdateClient = false;

            if (!$serverProgress) {
                // No server record -> Client wins
                $shouldUpdateServer = true;
            } else {
                // Compare timestamps
                $serverUpdated = $serverProgress->updated_at; // or last_listened_at

                if ($clientUpdated->gt($serverUpdated)) {
                    $shouldUpdateServer = true;
                } elseif ($serverUpdated->gt($clientUpdated)) {
                    $shouldUpdateClient = true;
                }
            }

            if ($shouldUpdateServer) {
                // Update or Create Server Record
                $updateData = [
                    'device_id' => $deviceId,
                    'user_id' => $userId,
                    'current_position_seconds' => (int) ($positionMs / 1000),
                    'progress_percentage' => $percentage,
                    'last_listened_at' => $clientUpdated,
                    'updated_at' => $clientUpdated, // Force update timestamp to match client? Or usage now()?
                                                    // Usually we want server to reflect it processed this now,
                                                    // but conceptually it's the "latest" state.
                                                    // Let's use now() for updated_at, but respect client info.
                ];

                if ($book) {
                    $updateData['book_id'] = $book->id;
                } else {
                    $updateData['client_book_id'] = $clientBook->id;
                }

                BookProgress::updateOrCreate(
                    [
                        'device_id' => $deviceId,
                        'book_id' => $book?->id,
                        'client_book_id' => $clientBook?->id,
                    ],
                    $updateData
                );
            } elseif ($shouldUpdateClient) {
                // Prepare response for client
                $serverUpdates[] = [
                    'bookId' => $book ? $book->id : $clientBook->id, // Existing ID
                    'positionMs' => $serverProgress->current_position_seconds * 1000,
                    'progressPercentage' => (float)$serverProgress->progress_percentage,
                    'source' => 'server',
                    'lastUpdated' => $serverProgress->updated_at->toISOString(),
                ];
            }
        }

        return response()->json([
            'serverTimestamp' => now()->toISOString(),
            'updates' => $serverUpdates,
        ]);
    }
}
