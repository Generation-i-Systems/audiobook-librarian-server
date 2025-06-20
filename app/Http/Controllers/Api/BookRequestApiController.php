<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookRequestApiController extends Controller
{
    /**
     * Normalize legacy or incorrect series input to canonical format.
     * Accepts string, array of strings, or old key-value object.
     * Always returns array of objects with seriesName and number.
     * @param mixed $seriesInput
     * @return array
     */
    public static function normalizeSeriesInput($seriesInput): array
    {
        if (empty($seriesInput)) {
            return [];
        }
        if (is_string($seriesInput)) {
            // Assume just a name, no number
            return [['seriesName' => $seriesInput, 'number' => '']];
        }
        if (is_array($seriesInput)) {
            // Canonical format
            if (isset($seriesInput[0]['seriesName']) || isset($seriesInput[0]['number'])) {
                return array_map(function ($item) {
                    return [
                        'seriesName' => $item['seriesName'] ?? ($item['name'] ?? ''),
                        'number' => $item['number'] ?? '',
                    ];
                }, $seriesInput);
            }
            // Legacy: [{Buryoku: 9}]
            if (isset($seriesInput[0]) && is_array($seriesInput[0]) && count($seriesInput[0]) === 1) {
                $out = [];
                foreach ($seriesInput as $item) {
                    foreach ($item as $name => $number) {
                        $out[] = ['seriesName' => $name, 'number' => (string) $number];
                    }
                }
                return $out;
            }
        }
        return [];
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            // Accept canonical format: array of objects with seriesName and number
            'series' => 'nullable|array',
            'series.*.seriesName' => 'nullable|string|max:255',
            'series.*.number' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'book_id' => 'required',
        ]);

        $userId = Auth::id();
        $bookRequest = $this->documentStoreService->createBookRequest([
            'user_id' => $userId,
            'book_id' => $request->input('book_id'),
            'title' => $request->title,
            'author' => $request->author,
            'series' => self::normalizeSeriesInput($request->input('series')),
            'description' => $request->description,
            'status' => 'pending',
        ]);

        return response()->json($bookRequest, 201); // 201 Created
    }
}
