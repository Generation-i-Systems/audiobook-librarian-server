<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BookJsonController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Get the raw JSON data for a book from $documentStore
     *
     * @param  string  $id  The book ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRawJson($id)
    {
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($id);
        if (!$book) {
            abort(404);
        }

        return response()->json($book, 200, ['Content-Type' => 'application/json'], JSON_PRETTY_PRINT);
    }

    /**
     * Save raw JSON for a book (admin only).
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveRawJson($id, Request $request)
    {
        $json = $request->input('json');
        if (empty($json)) {
            return response()->json(['message' => 'No JSON provided.'], 400);
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return response()->json(['message' => 'Invalid JSON: ' . $e->getMessage()], 422);
        }
        // Remove _id if present, always use string id
        unset($data['_id']);
        $data['id'] = $id;
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($id);
        if (!$book) {
            return response()->json(['message' => 'Book not found.'], 404);
        }
        $documentStore->updateBook($id, $data);
        return response()->json(['success' => true]);
    }
}
