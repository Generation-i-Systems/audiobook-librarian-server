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

        // Use the parser's extractAuthorFromPath method
        $author = $this->parser->extractAuthorFromPath($path);
        
        // Parse the path components
        $parts = explode('/', trim($path, '/'));
        $parts = array_values(array_filter($parts, fn($p) => !empty(trim($p))));
        
        $result = [
            'author' => $author !== 'Unknown Author' ? $author : '',
            'title' => '',
            'genre' => '',
            'series' => '',
            'seriesNumber' => '',
        ];
        
        // Extract genre (first part if it exists)
        if (count($parts) >= 1) {
            $result['genre'] = $parts[0];
        }
        
        // Get the last part (usually the title or book directory)
        if (count($parts) >= 1) {
            $lastPart = $parts[count($parts) - 1];
            
            // Try to extract series info from the last part
            // Format: "Title - Series Name #Number" or just "Title"
            if (preg_match('/^(.+?)\s*-\s*(.+?)\s*#\s*(\d+(?:\.\d+)?)$/', $lastPart, $matches)) {
                $result['title'] = trim($matches[1]);
                $result['series'] = trim($matches[2]);
                $result['seriesNumber'] = trim($matches[3]);
            } elseif (preg_match('/^(.+?)\s*#\s*(\d+(?:\.\d+)?)$/', $lastPart, $matches)) {
                // Format: "Title #Number" (series name from parent directory)
                $result['title'] = trim($matches[1]);
                $result['seriesNumber'] = trim($matches[2]);
                if (count($parts) >= 3) {
                    $result['series'] = $parts[count($parts) - 2];
                }
            } else {
                $result['title'] = $lastPart;
            }
        }
        
        // If we have 5 parts, it's likely: genre/author/series/number/title
        if (count($parts) >= 5) {
            $result['series'] = $parts[2];
            $result['seriesNumber'] = $parts[3];
        }
        
        return response()->json($result);
    }
}
