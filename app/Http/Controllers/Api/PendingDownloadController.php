<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PendingDownload;
use App\Services\PendingDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * API Controller for pending-download records.
 *
 * These are registered by the ABB bridge browser extension at the moment a
 * magnet link is sent to the torrent client, so the import pipeline can
 * later pre-fill metadata for the resulting downloaded folder instead of
 * relying purely on filename parsing.
 */
class PendingDownloadController extends Controller
{
    public function __construct(
        protected PendingDownloadService $pendingDownloadService
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'magnet_uri' => 'required|string|starts_with:magnet:?',
            'abb_url' => 'required|string|max:1000',
            'abb_category' => 'nullable|string|max:255',
            'release_name' => 'nullable|string|max:500',
            'books' => 'required|array|min:1',
            'books.*.title' => 'required|string|max:500',
            'books.*.authors' => 'nullable|array',
            'books.*.authors.*' => 'string|max:255',
            'books.*.genre' => 'nullable|string|max:255',
            'books.*.tags' => 'nullable|array',
            'books.*.tags.*' => 'string|max:100',
            'books.*.description' => 'nullable|string',
            'books.*.cover_url' => 'nullable|string|max:1000',
            'books.*.series_name' => 'nullable|string|max:255',
            'books.*.series_number' => 'nullable|string|max:50',
            'books.*.narrator' => 'nullable|string|max:255',
            'books.*.abb_url' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $userId = $request->user()?->id;

        $pendingDownload = $this->pendingDownloadService->createFromRequest(
            $data,
            $request->all(),
            $userId === null ? null : (int) $userId
        );

        return response()->json([
            'success' => true,
            'data' => $pendingDownload->load('books'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = PendingDownload::query()->with('books');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('magnet_infohash')) {
            $query->where('magnet_infohash', $request->input('magnet_infohash'));
        }

        if ($request->filled('release_name')) {
            $query->where('release_name', 'like', '%' . $request->input('release_name') . '%');
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('created_at')->paginate(50),
        ]);
    }

    public function show(PendingDownload $pendingDownload): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $pendingDownload->load('books'),
        ]);
    }

    public function consume(Request $request, PendingDownload $pendingDownload): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'matches' => 'required|array|min:1',
            'matches.*.pending_download_book_id' => 'required|integer|exists:pending_download_books,id',
            'matches.*.book_id' => 'required|integer|exists:books,id',
            'matched_directory' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $this->pendingDownloadService->consumeForBooks(
            $pendingDownload,
            $data['matches'],
            $data['matched_directory'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $pendingDownload->fresh('books'),
        ]);
    }
}
