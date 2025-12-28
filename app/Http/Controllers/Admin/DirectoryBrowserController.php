<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DirectoryBrowserController extends Controller
{
    public function browse(Request $request)
    {
        $basePath = (string) config('app.book_root', '/media/lyra_data1/audiobooks/books');

        if (!$basePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');

            return response()->json(
                [
                    'error' => 'The book store path is invalid. Verify the .env exists and the value is valid.',
                ],
                400
            );
        }
        if (!is_dir($basePath)) {
            Log::error('BOOK_STORAGE_PATH is not a directory');

            return response()->json(
                [
                    'error' => 'The book store path is invalid. Verify the is_dir function is not in a loop.',
                ],
                400
            );
        }

        $path = (string) $request->input('path', '');
        $filterLetter = $request->input('filter_letter');
        $search = $request->input('search');

        $relativePath = trim($path, '/');
        $fullPath = $relativePath === '' ? $basePath : $basePath . '/' . $relativePath;

        if (!is_dir($fullPath)) {
            Log::error('Path: ' . $fullPath . ' is not a directory');

            return response()->json([
                'error' => 'Invalid directory.',
            ], 400);
        }

        $files = $this->scanDirectory($fullPath);

        if ($files === false) {
            Log::error('Attempt to perform a scandir but failed to get value.');

            return response()->json([
                'error' => 'The scan for all files has been invalid to be done.',
            ], 400);
        }

        $data = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Filtering by letter
            if ($filterLetter && stripos($file, $filterLetter) !== 0) {
                continue;
            }

            // Filtering by search string
            if ($search && stripos($file, $search) === false) {
                continue;
            }

            $filePath = ltrim($path . '/' . $file, '/');
            $isPotentialBookDirectory = $this->isPotentialBookDirectory($basePath . $filePath);

            if (is_dir($basePath . '/' . $filePath)) {
                $documentStoreService = app(DocumentStoreServiceInterface::class);
                $book = $documentStoreService->findBookByDirectoryPath($filePath);
                $bookId = $book['id'] ?? null;

                if ($this->isPotentialBookDirectory($basePath . '/' . $filePath)) {
                    if (!empty($bookId)) {
                        $data[] = [
                            'type' => 'book',
                            'id' => $bookId,  // Include book ID for frontend reference
                            'name' => $file,
                            'path' => $filePath,
                            'edit' => route('admin.books.edit', ['book' => $bookId]),
                        ];
                    } else {
                        $data[] = [
                            'type' => 'directory',
                            'name' => $file,
                            'path' => $filePath,
                            'create' => route('admin.books.create') . '?path=' . urlencode($filePath),
                            'bulk_import' => route('admin.books.bulkImportDir', ['dir' => $filePath]),
                        ];
                    }
                } else {
                    // Log::error('Not a book directory: ' . $basePath . '/' . $filePath);
                    $data[] = [
                        'type' => 'directory',
                        'name' => $file,
                        'path' => $filePath,
                        'bulk_import' => route('admin.books.bulkImportDir', ['dir' => $filePath]),
                    ];
                }
            } else {
                $extension = pathinfo($basePath . $filePath, PATHINFO_EXTENSION);

                if (in_array(strtolower($extension), ['mp3', 'm4b', 'm4a'])) {
                    $data[] = [
                        'type' => 'file',
                        'name' => $file,
                        'path' => $filePath,
                    ];
                }
            }
        }

        return response()->json($data, 200, [], JSON_PRETTY_PRINT);
    }

    private function isPotentialBookDirectory($directoryPath): bool
    {
        if (!is_dir($directoryPath)) {
            return false; // It's a file, not a directory
        }

        $audioFiles = Storage::files($directoryPath);
        $files = $this->scanDirectory($directoryPath);

        if ($files === false) {
            Log::error("Failed to scan directory: $directoryPath");

            return false;  // Return an empty array on failure
        }

        $hasAudioFiles = false;
        $hasSubDirectories = false;

        foreach ($files as $file) {
            if (in_array(pathinfo($file, PATHINFO_EXTENSION), ['mp3', 'm4b', 'm4a'])) {
                $hasAudioFiles = true;

                break;
            } elseif (is_dir($directoryPath . '/' . $file) && !in_array(strtolower($file), ['mp3', 'm4b', 'm4a'])) {
                $hasSubDirectories = true;
                break;
            }
        }

        return $hasAudioFiles && !$hasSubDirectories;
    }

    private function scanDirectory($path)
    {
        $files = scandir($path);
        foreach ($files as $idx => $file) {
            if ($file[0] == '.') {
                unset($files[$idx]);
            }
        }

        if ($files === false) {
            Log::error("Failed to scan directory: $path");

            return [];  // Return an empty array on failure
        }

        return $files;
    }
}
