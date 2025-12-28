<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookFilesystemService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BookFilesystemController extends Controller
{
    public function __construct(
        private readonly BookFilesystemService $bookFilesystemService
    ) {
    }

    public function renameImportItem(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
            'new_name' => 'required|string',
        ]);

        $result = $this->bookFilesystemService->renameItem(
            (string) $request->input('path'),
            (string) $request->input('new_name')
        );

        if (!($result['success'] ?? false)) {
            return response()->json([
                'error' => $result['error'] ?? 'Rename failed.',
            ], (int) ($result['status'] ?? 500));
        }

        return response()->json([
            'success' => true,
            'newPath' => $result['newPath'],
            'newName' => $result['newName'],
        ]);
    }

    public function filesAjax(Request $request): JsonResponse
    {
        $directory = (string) $request->input('directory', '');

        return response()->json($this->bookFilesystemService->listFiles($directory));
    }

    public function browseDirectories(Request $request): JsonResponse
    {
        $requestedPath = (string) $request->input('path', '');

        return response()->json($this->bookFilesystemService->browseDirectories($requestedPath));
    }
}
