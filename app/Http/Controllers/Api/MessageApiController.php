<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Community\CommunityAudience;
use App\Services\MessageHistoryService;
use App\Support\CommunityStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class MessageApiController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        private readonly MessageHistoryService $history,
        private readonly CommunityAudience $audience,
    ) {
        $this->documentStoreService = $documentStoreService;
    }

    /** GET /messages/history?box=received|sent&page=&per_page= — everything, read or not, newest first */
    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'box' => ['nullable', 'string', Rule::in(MessageHistoryService::BOXES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $max = (int) config('community.max_page_size', 100);
        $perPage = min($max, (int) ($data['per_page'] ?? 20));

        return response()->json($this->history->page(
            $user,
            $data['box'] ?? MessageHistoryService::BOX_RECEIVED,
            (int) ($data['page'] ?? 1),
            $perPage,
        ));
    }

    public function index(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $messages = \App\Models\Message::where('recipient_id', $userId)
            ->whereNull('acknowledged_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $messages]);
    }

    /**
     * POST /messages — with `recipient_id` a direct message to someone the sender may reach
     * (same rules as recommendations); without it, the legacy message to the first admin.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $senderId = auth()->id();

        if (! is_int($senderId)) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (isset($data['recipient_id'])) {
            return $this->sendDirect($request, (int) $data['recipient_id'], $data['subject'] ?? null, $data['content']);
        }

        $admins = $this->documentStoreService->getAdminUsers();
        $adminId = $admins[0]['id'] ?? null;

        if (! is_int($adminId)) {
            return response()->json(['error' => 'No admin user available'], 500);
        }

        $messageId = $this->documentStoreService->createMessage([
            'sender_id' => $senderId,
            'recipient_id' => $adminId,
            'type' => 'general',
            'content' => $data['content'],
        ]);

        if (! is_string($messageId) || $messageId === '') {
            return response()->json(['error' => 'Failed to create message'], 500);
        }

        return response()->json(['id' => $messageId], 201);
    }

    private function sendDirect(Request $request, int $recipientId, ?string $subject, string $content): JsonResponse
    {
        /** @var User $sender */
        $sender = $request->user();

        if ($recipientId === $sender->id) {
            return response()->json([
                'message' => 'You cannot message yourself.',
                'errors' => ['recipient_id' => ['You cannot message yourself.']],
            ], 422);
        }

        if (CommunityStatus::mode() !== CommunityStatus::MODE_FULL) {
            return response()->json(['message' => 'Messaging other users is not available on this server.'], 403);
        }

        $recipient = User::find($recipientId);
        if ($recipient === null || !$this->audience->canReachUser($sender, $recipient)) {
            return response()->json(['message' => 'You cannot send messages to that person.'], 403);
        }

        $limiterKey = 'direct-message:' . $sender->id;
        if (RateLimiter::tooManyAttempts($limiterKey, (int) config('community.message_sends_per_hour', 60))) {
            return response()->json([
                'message' => 'Too many messages sent recently. Try again later.',
                'retryAfter' => RateLimiter::availableIn($limiterKey),
            ], 429);
        }
        RateLimiter::hit($limiterKey, 3600);

        $messageId = $this->history->send($sender, $recipient, $subject, $content);
        if (! is_string($messageId) || $messageId === '') {
            return response()->json(['error' => 'Failed to create message'], 500);
        }

        return response()->json(['id' => $messageId], 201);
    }

    public function acknowledge(Request $request, int $id)
    {
        $userId = auth()->id();
        $message = \App\Models\Message::where('recipient_id', $userId)
            ->where('id', $id)
            ->first();

        if (!$message) {
            return response()->json(['error' => 'Message not found'], 404);
        }

        $message->update(['acknowledged_at' => now()]);

        return response()->json(['success' => true]);
    }
}
