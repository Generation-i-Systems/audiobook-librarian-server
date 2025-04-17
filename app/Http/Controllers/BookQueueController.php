?php

namespace App\Http\Controllers;

use App\Models\BookQueue;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookQueueController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $queue = BookQueue::where('user_id', $user->id)
            ->orderBy('order')
            ->with('book')
            ->get();

        return view('queue.index', compact('queue'));
    }

    public function add(Book $book)
    {
        $user = Auth::user();

        // Check if the book is already in the queue
        $existingQueueItem = BookQueue::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->first();

        if ($existingQueueItem) {
            return back()->with('error', 'Book already in queue.');
        }

        // Determine the next available order position
        $lastQueueItem = BookQueue::where('user_id', $user->id)->orderBy('order', 'desc')->first();
        $nextOrder = $lastQueueItem ? $lastQueueItem->order + 1 : 1;

        BookQueue::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'order' => $nextOrder,
        ]);

        return back()->with('success', 'Book added to queue!');
    }

    public function remove(Book $book)
    {
        $user = Auth::user();

        BookQueue::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->delete();

        // Reorder the queue after removal (optional, but good practice)
        $this->reorderQueue($user->id);

        return back()->with('success', 'Book removed from queue.');
    }

    public function updateOrder(Request $request)
    {
        $request->validate([
            'queue' => 'required|array',
            'queue.*.id' => 'required|integer',
            'queue.*.order' => 'required|integer',
        ]);

        $user = Auth::user();

        foreach ($request->queue as $queueItemData) {
            $queueItem = BookQueue::where('user_id', $user->id)
                ->where('id', $queueItemData['id'])
                ->first();

            if ($queueItem) {
                $queueItem->order = $queueItemData['order'];
                $queueItem->save();
            }
        }

        return response()->json(['success' => true]);
    }

    private function reorderQueue($userId)
    {
        $queue = BookQueue::where('user_id', $userId)->orderBy('order')->get();
        $order = 1;
        foreach ($queue as $item) {
            $item->order = $order++;
            $item->save();
        }
    }
}
