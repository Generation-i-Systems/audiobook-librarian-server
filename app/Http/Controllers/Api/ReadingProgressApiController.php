<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingProgressApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;


    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }


    public function update(Request $request)
    {
        $request->validate([
            'current_position' => 'required|integer|min:0',
        ]);

        $user = Auth::user();

        $userId = (string) Auth::id();
        $bookId = (string) $request->input('book_id');
        $result = $this->documentStoreService->updateReadingProgress($userId, $bookId, (int) $request->current_position);

        return response()->json(['success' => $result], $result ? 200 : 500);
    }


    public function reset(Request $request)
    {
        $userId = (string) Auth::id();
        $bookId = (string) $request->input('book_id');
        $result = $this->documentStoreService->resetReadingProgress($userId, $bookId);

        return response()->json([
            'message' => $result ? 'Progress reset.' : 'Failed to reset progress.',
            'success' => $result,
        ], $result ? 200 : 500);
    }


    public function get(Request $request)
    {
        $user = Auth::user();

        $userId = (string) Auth::id();
        $bookId = (string) $request->input('book_id');
        $progress = $this->documentStoreService->getReadingProgress($userId, $bookId);

        return response()->json(['progress' => $progress]);
    }
}
