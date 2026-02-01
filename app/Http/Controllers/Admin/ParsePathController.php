<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookDirectoryParser;
use Illuminate\Http\Request;

class ParsePathController extends Controller
{
    public function __construct(
        private BookDirectoryParser $parser
    ) {
    }

    public function parsePath(Request $request)
    {
        $path = $request->input('path');

        if (empty($path)) {
            return response()->json(['error' => 'Path is required'], 400);
        }

        // Use the parser's processDirPath method - this is what parseDirectory uses
        $reflection = new \ReflectionClass($this->parser);
        $method = $reflection->getMethod('processDirPath');
        $method->setAccessible(true);

        // Remove storage root if present and normalize path
        $storageRoot = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root'), '/');
        $normalizedPath = trim(str_replace($storageRoot, '', $path), '/');

        $bookPathInfo = $method->invoke($this->parser, $normalizedPath);

        // Check if parsing failed
        if (!empty($bookPathInfo['skipped']) || !empty($bookPathInfo['error'])) {
            return response()->json(['error' => 'Could not parse path'], 400);
        }

        // Extract series info
        $seriesName = '';
        $seriesNumber = '';
        if (!empty($bookPathInfo['series'])) {
            if (is_array($bookPathInfo['series'])) {
                $seriesName = array_key_first($bookPathInfo['series']);
                $seriesNumber = $bookPathInfo['series'][$seriesName] ?? '';
            } else {
                $seriesName = $bookPathInfo['series'];
                $seriesNumber = $bookPathInfo['seriesNumber'] ?? '';
            }
        }

        // Format author as string
        $author = '';
        if (!empty($bookPathInfo['author'])) {
            if (is_array($bookPathInfo['author'])) {
                $author = implode(', ', $bookPathInfo['author']);
            } else {
                $author = $bookPathInfo['author'];
            }
        }

        // Format genre as string
        $genre = '';
        if (!empty($bookPathInfo['genre'])) {
            if (is_array($bookPathInfo['genre'])) {
                $genre = implode(', ', $bookPathInfo['genre']);
            } else {
                $genre = $bookPathInfo['genre'];
            }
        }

        $result = [
            'author' => $author !== 'Unknown Author' ? $author : '',
            'title' => $bookPathInfo['title'] ?? '',
            'genre' => $genre,
            'series' => $seriesName,
            'seriesNumber' => $seriesNumber,
        ];

        return response()->json($result);
    }
}
