<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookRequestApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Normalize legacy or incorrect series input to canonical format.
     * Accepts string, array of strings, or old key-value object.
     * Always returns array of objects with seriesName and number.
     *
     * @param  mixed  $seriesInput
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

        $admins = $this->documentStoreService->getAdminUsers();
        $adminId = $admins[0]['id'] ?? null;

        if (! is_int($adminId)) {
            return response()->json(['error' => 'No admin user available'], 500);
        }

        $senderId = Auth::id();

        if (! is_int($senderId)) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $payload = [
            'type' => 'book_request',
            'book_id' => $request->input('book_id'),
            'title' => $request->title,
            'author' => $request->author,
            'series' => self::normalizeSeriesInput($request->input('series')),
            'description' => $request->description,
        ];

        $messageId = $this->documentStoreService->createMessage([
            'sender_id' => $senderId,
            'recipient_id' => $adminId,
            'content' => json_encode($payload),
        ]);

        if (! is_string($messageId) || $messageId === '') {
            return response()->json(['error' => 'Failed to create book request'], 500);
        }

        return response()->json(['id' => $messageId], 201);
    }
}
