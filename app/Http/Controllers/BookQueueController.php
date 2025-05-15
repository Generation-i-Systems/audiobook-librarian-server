<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookQueueController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $firestore = new FirestoreService();
        $queue = $firestore->getBookQueue($user->id);
        return view('queue.index', compact('queue'));
    }


    public function add($bookId)
    {
        $user = Auth::user();
        $firestore = new FirestoreService();
        $queue = $firestore->getBookQueue($user->id);
        foreach ($queue as $item) {
            if ($item['book_id'] == $bookId) {
                return back()->with('error', 'Book already in queue.');
            }
        }
        $firestore->addBookToQueue($user->id, $bookId);
        return back()->with('success', 'Book added to queue!');
    }

    public function remove($bookId)
    {
        $user = Auth::user();
        $firestore = new FirestoreService();
        $firestore->removeBookFromQueue($user->id, $bookId);
        $this->reorderQueue($user->id);
        return back()->with('success', 'Book removed from queue.');
    }

    public function updateOrder(Request $request)
    {
        $request->validate([
            'queue' => 'required|array',
            'queue.*.book_id' => 'required',
            'queue.*.order' => 'required|integer',
        ]);
        $user = Auth::user();
        $firestore = new FirestoreService();
        // Implement: update the order of books in queue in Firestore
        // (You may want to add a method in FirestoreService for this)
        // For now, just return success
        return response()->json(['success' => true]);
    }

    private function reorderQueue($userId)
    {
        // Optionally implement reordering in Firestore if needed
        // For now, this is a stub
    }
}
