<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Author;

class DirectoryBrowserController extends Controller
{

    public function browse(Request $request)
    {
        $basePath = env('BOOK_STORAGE_PATH');

        if (!$basePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return response()->json(['error' => 'The book store path is invalid. Verify the .env exists and the value is valid.'], 400);
        }
        if (!is_dir($basePath)) {
            Log::error('BOOK_STORAGE_4TH is not a directory');
            return response()->json(['error' => 'The book store path is invalid. Verify the is_dir function is not in a loop or another process.'], 400);
        }

        $path = $request->input('path', $basePath);
        if (!is_dir($basePath . $path)) {
            return response()->json(['error' => 'Invalid directory.'], 400);
        }

        $files = $this->scanDirectory($basePath . $path);
        if ($files === false) {
            Log::error('Attempt to perform a scandir but failed to get value.');
            return response()->json(['error' => 'The scan for all files has been invalid to be done.'], 400);
        }
        $data = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..')
                continue;

            $filePath = $path . '/' . $file;
            $isPotentialBookDirectory = $this->isPotentialBookDirectory($basePath . $filePath);

            if (is_dir($basePath . $filePath)) {
                $book = Book::where('directory_path', $filePath)->first();
                $bookId = $book ? $book->id : null;

                if ($this->isPotentialBookDirectory($basePath . $filePath)) {
                    if ($book) {
                        $data[] = [
                            'type' => 'directory',
                            'name' => $file,
                            'path' => $filePath,
                            'edit' => route('admin.books.edit', ['book' => $book->id])
                        ];
                    } else {
                        $data[] = [
                            'type' => 'directory',
                            'name' => $file,
                            'path' => $filePath,
                            'create' => route('admin.books.create') . '?path=' . urlencode($filePath)
                        ];
                    }

                } else {
                    $data[] = [
                        'type' => 'directory',
                        'name' => $file,
                        'path' => $filePath,
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
            if (pathinfo($file, PATHINFO_EXTENSION) === "mp3" || pathinfo($file, PATHINFO_EXTENSION) === "m4b") {
                $hasAudioFiles = true;

                break;
            } else if (is_dir($directoryPath . '/' . $file) && !in_array(strtolower($file), ['mp3', 'm4b', 'm4a'])) {
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
