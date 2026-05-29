<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Services\BookDirectoryMoveService;
use App\Services\BookEditPlannedActionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class BookActionController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function plannedActions(Request $request, string $id): JsonResponse
    {
        $directoryPath = (string) $request->input('directoryPath', '');
        $coverImageUrl = (string) $request->input('coverImageUrl', '');
        $audibleCoverImageUrl = (string) $request->input('audibleCoverImageUrl', '');

        $coverUrl = $audibleCoverImageUrl !== '' ? $audibleCoverImageUrl : $coverImageUrl;

        $service = new BookEditPlannedActionsService($this->documentStoreService);
        $plan = $service->computePlannedActions($id, $directoryPath, $coverUrl);

        return response()->json($plan);
    }

    public function executeImmediateMove(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'oldDirectoryPath' => 'required|string',
            'newDirectoryPath' => 'required|string',
        ]);

        $oldDirectoryPath = trim($validated['oldDirectoryPath']);
        $newDirectoryPath = trim($validated['newDirectoryPath']);

        if ($oldDirectoryPath === $newDirectoryPath) {
            return response()->json(['success' => false, 'message' => 'Old and new paths are the same'], 400);
        }

        try {
            $book = $this->documentStoreService->getBook($id);
            if (!$book) {
                return response()->json(['success' => false, 'message' => 'Book not found'], 404);
            }

            $oldCoverBasename = !empty($book['coverImage']) ? basename((string) $book['coverImage']) : null;
            $moveService = app(BookDirectoryMoveService::class);
            $moveResult = $moveService->moveBookDirectoryContents($oldDirectoryPath, $newDirectoryPath, $oldCoverBasename);

            if (!$moveResult['moved']) {
                return response()->json(['success' => false, 'message' => 'Failed to move directory contents'], 500);
            }

            $actualNewPath = $moveResult['directoryPath'] ?? $newDirectoryPath;
            $newCoverImage = $moveResult['coverImage'] ?? $oldCoverBasename;

            $book['directoryPath' ] = $actualNewPath;
            if ($newCoverImage) {
                $book['coverImage'] = $newCoverImage;
            }

            $this->documentStoreService->updateBook($id, $book);

            return response()->json([
                'success' => true,
                'message' => 'Directory moved successfully',
                'directoryPath' => $actualNewPath,
                'coverImage' => $newCoverImage,
            ]);
        } catch (\Exception $e) {
            Log::error('Error executing immediate move: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error moving directory: ' . $e->getMessage()], 500);
        }
    }

    public function download(string $id): BinaryFileResponse
    {
        $book = $this->documentStoreService->getBook($id);
        $directoryPath = $book['directoryPath'] ?? null;

        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }

        $files = Storage::disk('books')->files($directoryPath);
        if (empty($files)) {
            abort(404, 'No files found for this book.');
        }

        $zipFileName = str_replace(' ', '_', $book['title']) . '.zip';
        $zipPath = storage_path('app/public/temp/' . $zipFileName);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create zip archive.');
        }

        foreach ($files as $file) {
            $zip->addFile(Storage::disk('books')->path($file), basename($file));
        }
        $zip->close();

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    public function getRawJson(string $id): JsonResponse
    {
        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            abort(404);
        }
        return response()->json($book, 200, ['Content-Type' => 'application/json'], JSON_PRETTY_PRINT);
    }

    public function saveRawJson(Request $request, string $id): JsonResponse
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
        unset($data['_id']);
        $data['id'] = $id;
        if (!$this->documentStoreService->getBook($id)) {
            return response()->json(['message' => 'Book not found.'], 404);
        }
        $this->documentStoreService->updateBook($id, $data);
        return response()->json(['success' => true]);
    }
}
