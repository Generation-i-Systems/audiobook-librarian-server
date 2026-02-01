<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookQueueController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    public function index()
    {
        $user = Auth::user();
        $documentStore = $this->documentStoreService;
        $queue = $documentStore->getBookQueue($user->id);

        return view('queue.index', compact('queue'));
    }


    public function add($bookId)
    {
        $user = Auth::user();
        $documentStore = $this->documentStoreService;
        $queue = $documentStore->getBookQueue($user->id);
        foreach ($queue as $item) {
            if ($item['book_id'] == $bookId) {
                return back()->with('error', 'Book already in queue.');
            }
        }
        $documentStore->addBookToQueue($user->id, $bookId);

        return back()->with('success', 'Book added to queue!');
    }


    public function remove($bookId)
    {
        $user = Auth::user();
        $documentStore = $this->documentStoreService;
        $documentStore->removeBookFromQueue($user->id, $bookId);
        // @phpstan-ignore-next-line
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
        $documentStore = $this->documentStoreService;

        // Implement: update the order of books in queue in document store

        // For now, just return success
        return response()->json(['success' => true]);
    }


    private function reorderQueue($userId)
    {
        // Optionally implement reordering in document store if needed
        // For now, this is a stub
    }
}
