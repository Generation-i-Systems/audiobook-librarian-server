<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingProgressController extends Controller
{
    /**
     * @var DocumentStoreServiceInterface
     */
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }
    public function update(Request $request)
    {
        $request->validate([
            'book_id' => 'required|string',
            'current_position' => 'required|integer|min:0',
        ]);
        $user = Auth::user();
        $userId = $user->id;
        $bookId = $request->book_id;
        $this->documentStoreService->setReadingProgress($userId, $bookId, $request->current_position);
        return response()->json(['success' => true]);
    }

    public function get(Request $request)
    {
        $request->validate([
            'book_id' => 'required|string',
        ]);
        $user = Auth::user();
        $userId = $user->id;
        $bookId = $request->book_id;
        $currentPosition = $this->documentStoreService->getReadingProgress($userId, $bookId);
        return response()->json(['current_position' => $currentPosition]);
    }
}
