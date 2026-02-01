<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class ExternalReadApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
        $this->middleware('auth:api');
    }

    /**
     * List external/previously-read entries for a book
     */
    public function getExternalReads(Request $request, string $bookId)
    {
        $book = $this->documentStoreService->getBook($bookId);
        if (! $book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        $userId = Auth::id();
        $entries = $this->documentStoreService->getExternalReads($userId, $bookId);

        $formatted = array_map(function (array $entry) {
            return $this->formatEntry($entry);
        }, $entries);

        return response()->json(['data' => $formatted]);
    }

    /**
     * Create a new external/previously-read entry
     */
    public function createExternalRead(Request $request, string $bookId)
    {
        $validator = Validator::make($request->all(), [
            'origin' => 'sometimes|string|in:external,previous',
            'source' => 'sometimes|nullable|string|max:255',
            'note' => 'sometimes|nullable|string',
            'started_at' => 'sometimes|nullable|date',
            'finished_at' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $book = $this->documentStoreService->getBook($bookId);
        if (! $book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        $userId = Auth::id();

        $data = [
            'user_id' => $userId,
            'book_id' => $bookId,
            'origin' => $request->input('origin', 'external'),
            'source' => $request->input('source'),
            'note' => $request->input('note'),
        ];

        if ($request->filled('started_at')) {
            $data['started_at'] = Carbon::parse($request->input('started_at'));
        }
        if ($request->filled('finished_at')) {
            $data['finished_at'] = Carbon::parse($request->input('finished_at'));
        }

        $id = $this->documentStoreService->createExternalRead($data);

        $created = $this->documentStoreService->getExternalRead((string) $id, $userId, $bookId) ?? array_merge($data, ['id' => (string) $id]);

        return response()->json($this->formatEntry($created), 201);
    }

    public function createExternalReadNonLibrary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'origin' => 'sometimes|string|in:external,previous',
            'source' => 'sometimes|nullable|string|max:255',
            'note' => 'sometimes|nullable|string',
            'started_at' => 'sometimes|nullable|date',
            'finished_at' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = Auth::id();

        $data = [
            'user_id' => $userId,
            'book_id' => null,
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'origin' => $request->input('origin', 'external'),
            'source' => $request->input('source'),
            'note' => $request->input('note'),
        ];

        if ($request->filled('started_at')) {
            $data['started_at'] = Carbon::parse($request->input('started_at'));
        }
        if ($request->filled('finished_at')) {
            $data['finished_at'] = Carbon::parse($request->input('finished_at'));
        }

        $id = $this->documentStoreService->createExternalRead($data);

        // Since it's non-library, we can't use getExternalRead that filters by book_id easily
        // But createExternalRead returns the ID
        $created = array_merge($data, ['id' => (string) $id]);

        return response()->json($this->formatEntry($created), 201);
    }

    /**
     * Get a specific external/previously-read entry
     */
    public function getExternalRead(Request $request, string $bookId, string $externalReadId)
    {
        $userId = Auth::id();
        $entry = $this->documentStoreService->getExternalRead($externalReadId, $userId, $bookId);

        if (! $entry) {
            return response()->json(['error' => 'External read not found'], 404);
        }

        return response()->json($this->formatEntry($entry));
    }

    /**
     * Update an external/previously-read entry
     */
    public function updateExternalRead(Request $request, string $bookId, string $externalReadId)
    {
        $validator = Validator::make($request->all(), [
            'origin' => 'sometimes|string|in:external,previous',
            'source' => 'sometimes|nullable|string|max:255',
            'note' => 'sometimes|nullable|string',
            'started_at' => 'sometimes|nullable|date',
            'finished_at' => 'sometimes|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = Auth::id();
        $existing = $this->documentStoreService->getExternalRead($externalReadId, $userId, $bookId);
        if (! $existing) {
            return response()->json(['error' => 'External read not found'], 404);
        }

        $update = [];
        foreach (['origin', 'source', 'note'] as $field) {
            if ($request->has($field)) {
                $update[$field] = $request->input($field);
            }
        }
        if ($request->has('started_at')) {
            $update['started_at'] = $request->filled('started_at') ? Carbon::parse($request->input('started_at')) : null;
        }
        if ($request->has('finished_at')) {
            $update['finished_at'] = $request->filled('finished_at') ? Carbon::parse($request->input('finished_at')) : null;
        }

        $this->documentStoreService->updateExternalRead($externalReadId, $update);

        $updated = $this->documentStoreService->getExternalRead($externalReadId, $userId, $bookId);

        return response()->json($this->formatEntry($updated));
    }

    /**
     * Delete an external/previously-read entry
     */
    public function deleteExternalRead(Request $request, string $bookId, string $externalReadId)
    {
        $userId = auth('api')->id();
        $deleted = $this->documentStoreService->deleteExternalRead($externalReadId, $userId, $bookId);
        if (! $deleted) {
            return response()->json(['error' => 'External read not found'], 404);
        }

        return response()->json(null, 204);
    }

    private function formatEntry(array $entry): array
    {
        $id = (string) ($entry['_id'] ?? ($entry['id'] ?? ''));

        $started = $entry['started_at'] ?? null;
        $finished = $entry['finished_at'] ?? null;
        $created = $entry['created_at'] ?? null;
        $updated = $entry['updated_at'] ?? null;

        $toIso = function ($value) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format(\DateTimeInterface::ATOM);
            }

            return $value;
        };

        return [
            'id' => $id,
            'book_id' => $entry['book_id'] ?? null,
            'title' => $entry['title'] ?? null,
            'author' => $entry['author'] ?? null,
            'origin' => $entry['origin'] ?? 'external',
            'source' => $entry['source'] ?? null,
            'note' => $entry['note'] ?? null,
            'started_at' => $toIso($started),
            'finished_at' => $toIso($finished),
            'created_at' => $toIso($created),
            'updated_at' => $toIso($updated),
        ];
    }
}
