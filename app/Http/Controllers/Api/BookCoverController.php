<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\Traits\BookTransformTrait;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BookCoverController extends Controller
{
    use BookTransformTrait;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function cover($id)
    {
        $book = $this->documentStoreService->getBook($id);

        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        if (empty($book['coverImage'])) {
            return response()->json([
                'error' => 'Cover not found',
                'message' => 'No cover image available for this book',
            ], 404);
        }

        $coverImage = $book['coverImage'];
        $directoryPath = $book['directoryPath'] ?? null;

        Log::info('Cover image requested for book: ' . ($book['title'] ?? '[unknown]') . ' (' . $coverImage . ')');

        // Check if coverImage is a remote URL (starts with http:// or https://)
        if ($this->isRemoteUrl($coverImage)) {
            return $this->proxyRemoteCoverImage($coverImage);
        }

        // Handle local files (both filename-only and full path formats)
        $coverPath = $this->resolveCoverImagePath($coverImage, $directoryPath);

        if (!$coverPath) {
            Log::warning('Could not resolve cover image path', [
                'book_id' => $id,
                'coverImage' => $coverImage,
                'directoryPath' => $directoryPath,
            ]);

            return response()->json([
                'error' => 'Cover not found',
                'message' => 'Cover image file could not be found',
            ], 404);
        }

        // Check if the resolved path exists
        if (Storage::disk('books')->exists($coverPath)) {
            $content = Storage::disk('books')->get($coverPath);
            $mime = mime_content_type(Storage::disk('books')->path($coverPath));
            return response(
                $content,
                200
            )->header('Content-Type', $mime)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                ->header('Cache-Control', 'public, max-age=3600');
        }

        //split out context into multiple log entries
        Log::warning('Cover image file does not exist', [
            'book_id' => $id,
        ]);
        Log::warning('Cover image file does not exist', [
            'resolved_path' => $coverPath,
        ]);
        Log::warning('Cover image file does not exist', [
            'original_coverImage' => $coverImage,
        ]);
        Log::warning('Cover image file does not exist', [
            'directoryPath' => $directoryPath,
        ]);
        Log::warning('Cover image file does not exist', [
            'storage_path' => Storage::disk('books')->path($coverPath),
        ]);

        return $this->coverNotFoundResponse();
    }
}
