<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class ImportFileController extends Controller
{
    /**
     * List configured import roots.
     * @return \Illuminate\Http\JsonResponse
     */
    public function roots()
    {
        $roots = collect(Config::get('import.roots', []))
            ->map(function ($path, $i) {
                return [
                    'value' => $path,
                    'label' => basename($path) ?: $path,
                ];
            })->values();
        return response()->json($roots);
    }

    /**
     * List files and directories in the given root/path.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(Request $request)
    {
        $root = $request->input('root');
        $path = $request->input('path', '');
        $absRoot = realpath($root);
        if (!$absRoot || !is_dir($absRoot)) {
            return response()->json(['error' => 'Invalid root'], 400);
        }
        $absPath = $absRoot . ($path ? DIRECTORY_SEPARATOR . $path : '');
        $absPath = realpath($absPath) ?: $absPath;
        if (strpos($absPath, $absRoot) !== 0) {
            return response()->json(['error' => 'Path traversal detected'], 400);
        }
        if (!is_dir($absPath)) {
            return response()->json(['error' => 'Not a directory'], 400);
        }
        $items = collect(File::directories($absPath))
            ->map(fn ($d) => [
                'type' => 'dir',
                'name' => basename($d),
            ])
            ->merge(
                collect(File::files($absPath))
                    ->filter(fn ($f) => in_array(strtolower($f->getExtension()), Config::get('import.allowed_extensions', [])))
                    ->map(fn ($f) => [
                        'type' => 'file',
                        'name' => $f->getFilename(),
                    ])
            )
            ->values();
        $parent = $absPath !== $absRoot;
        return response()->json([
            'parent' => $parent,
            'items' => $items,
        ]);
    }

    /**
     * Extract metadata from a file or directory for import.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function extract(Request $request)
    {
        $root = $request->input('root');
        $relPath = $request->input('path');
        $type = $request->input('type');
        $absRoot = realpath($root);
        if (!$absRoot || !is_dir($absRoot)) {
            Log::warning('[ImportFile] Invalid root: ' . $root);
            return response()->json(['success' => false, 'message' => 'Invalid root'], 400);
        }
        $absPath = $absRoot . ($relPath ? DIRECTORY_SEPARATOR . $relPath : '');
        $absPath = realpath($absPath) ?: $absPath;
        if (strpos($absPath, $absRoot) !== 0) {
            Log::warning('[ImportFile] Path traversal detected: ' . $absPath);
            return response()->json(['success' => false, 'message' => 'Path traversal detected'], 400);
        }
        if (!file_exists($absPath)) {
            Log::warning('[ImportFile] File or directory does not exist: ' . $absPath);
            return response()->json(['success' => false, 'message' => 'File or directory does not exist'], 404);
        }
        $meta = [
            'title' => null,
            'author' => null,
            'series' => null,
            'genre' => null,
        ];
        try {
            if ($type === 'file') {
                if (class_exists('getID3')) {
                    $getID3 = new \getID3();
                    $info = $getID3->analyze($absPath);
                    $meta['title'] = $info['tags']['id3v2']['title'][0] ?? ($info['tags']['id3v1']['title'][0] ?? null);
                    $meta['author'] = $info['tags']['id3v2']['artist'][0] ?? ($info['tags']['id3v1']['artist'][0] ?? null);
                    $meta['series'] = $info['tags']['id3v2']['album'][0] ?? ($info['tags']['id3v1']['album'][0] ?? null);
                    $meta['genre'] = $info['tags']['id3v2']['genre'][0] ?? ($info['tags']['id3v1']['genre'][0] ?? null);
                } else {
                    $base = pathinfo($absPath, PATHINFO_FILENAME);
                    $meta['title'] = $base;
                }
            } elseif ($type === 'dir') {
                $base = basename($absPath);
                $meta['title'] = $base;
            }
            $directoryPath = $this->composeDirectoryPath($meta);
            return response()->json(array_merge(['success' => true, 'directoryPath' => $directoryPath], $meta));
        } catch (\Exception $e) {
            Log::error('[ImportFile] Metadata extraction failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Metadata extraction error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Compose a directory path from genre, author, series, seriesNumber, and title (skipping empty values).
     * @param array $meta
     * @return string
     */
    protected function composeDirectoryPath(array $meta)
    {
        $parts = [];
        foreach (['genre', 'author', 'series', 'seriesNumber', 'title'] as $key) {
            if (!empty($meta[$key])) {
                $parts[] = preg_replace('/[\\\/]/', '-', trim($meta[$key]));
            }
        }
        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Move the selected file or directory to the composed directoryPath under the import destination root.
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function moveSelected(Request $request)
    {
        $root = $request->input('root');
        $relPath = $request->input('path');
        $type = $request->input('type');
        $directoryPath = $request->input('directoryPath');
        $absRoot = realpath($root);
        if (!$absRoot || !is_dir($absRoot)) {
            Log::warning('[ImportFile] Invalid root: ' . $root);
            return response()->json(['success' => false, 'message' => 'Invalid root'], 400);
        }
        $absPath = $absRoot . ($relPath ? DIRECTORY_SEPARATOR . $relPath : '');
        $absPath = realpath($absPath) ?: $absPath;
        if (strpos($absPath, $absRoot) !== 0) {
            Log::warning('[ImportFile] Path traversal detected: ' . $absPath);
            return response()->json(['success' => false, 'message' => 'Path traversal detected'], 400);
        }
        if (!file_exists($absPath)) {
            Log::warning('[ImportFile] File or directory does not exist: ' . $absPath);
            return response()->json(['success' => false, 'message' => 'File or directory does not exist'], 404);
        }
        // Determine import destination root
        $destRoot = Config::get('audiobooks.root')
            ?? Config::get('import.dest_root')
            ?? storage_path('audiobooks');
        $destDir = $destRoot . DIRECTORY_SEPARATOR . $directoryPath;
        if (!file_exists($destDir)) {
            if (!File::makeDirectory($destDir, 0775, true)) {
                Log::error('[ImportFile] Failed to create directory: ' . $destDir);
                return response()->json(['success' => false, 'message' => 'Failed to create destination directory'], 500);
            }
        }
        $target = $destDir . DIRECTORY_SEPARATOR . basename($absPath);
        if (realpath($absPath) === realpath($target)) {
            Log::info('[ImportFile] Source and destination are the same, skipping move.');
            return response()->json(['success' => true, 'newPath' => $target, 'message' => 'Already in target location.']);
        }
        try {
            if ($type === 'file') {
                File::move($absPath, $target);
            } elseif ($type === 'dir') {
                File::moveDirectory($absPath, $target);
            } else {
                return response()->json(['success' => false, 'message' => 'Unknown type'], 400);
            }
            Log::info('[ImportFile] Moved "' . $absPath . '" to "' . $target . '"');
            return response()->json(['success' => true, 'newPath' => $target]);
        } catch (\Exception $e) {
            Log::error('[ImportFile] Move failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Move failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
