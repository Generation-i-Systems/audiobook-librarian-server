<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStatsServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ReadingStatsApiController extends Controller
{
    protected DocumentStatsServiceInterface $statsService;

    public function __construct(DocumentStatsServiceInterface $statsService)
    {
        $this->statsService = $statsService;
    }

    public function recordSession(Request $request, string $bookId)
    {
        $validator = Validator::make($request->all(), [
            'started_at' => 'sometimes|nullable|date',
            'ended_at' => 'sometimes|nullable|date|after_or_equal:started_at',
            'duration_seconds' => 'sometimes|nullable|integer|min:0',
            'pages' => 'sometimes|nullable|integer|min:0',
            'position_start' => 'sometimes|nullable|integer|min:0',
            'position_end' => 'sometimes|nullable|integer|min:0',
            'device' => 'sometimes|nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = (string) Auth::id();
        $data = $validator->validated();
        $session = $this->statsService->recordReadingSession($userId, $bookId, $data);

        return response()->json([
            'session' => $session,
        ], 201);
    }

    public function getDaily(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date|after_or_equal:from',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = (string) Auth::id();
        $from = $validator->validated()['from'] ?? null;
        $to = $validator->validated()['to'] ?? null;
        $stats = $this->statsService->getDailyStats($userId, $from, $to);

        return response()->json(['data' => $stats]);
    }

    public function getBookStats(Request $request, string $bookId)
    {
        $userId = (string) Auth::id();
        $stats = $this->statsService->getBookStats($userId, $bookId);

        return response()->json(['data' => $stats]);
    }

    public function getUserStats(Request $request)
    {
        $userId = (string) Auth::id();
        $stats = $this->statsService->getUserStats($userId);

        return response()->json(['data' => $stats]);
    }

    public function getStreaks(Request $request)
    {
        $userId = (string) Auth::id();
        $streaks = $this->statsService->getStreaks($userId);

        return response()->json(['data' => $streaks]);
    }
}
