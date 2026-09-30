<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Group;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Models\UserNotification;
use App\Models\UserRecommendation;
use App\Services\Community\CommunityAudience;
use App\Services\Community\CommunityNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Book recommendations between users of this server. One send may address people,
 * groups the sender belongs to, or (admins only) everyone; it fans out to one row per
 * recipient so each person has their own inbox state, reaction and reply.
 */
class RecommendationController extends Controller
{
    public function __construct(
        private readonly CommunityAudience $audience,
        private readonly CommunityNotifier $notifier,
    ) {
    }

    /** POST /recommendations/{book} (alias POST /books/{book}/recommend) */
    public function send(Request $request, Book $book): JsonResponse
    {
        /** @var User $sender */
        $sender = Auth::user();

        $data = $request->validate([
            'recipient_id' => ['nullable', 'integer', 'exists:users,id', Rule::notIn([$sender->id])],
            'recipient_ids' => ['nullable', 'array', 'max:100'],
            'recipient_ids.*' => ['integer', 'distinct', 'exists:users,id', Rule::notIn([$sender->id])],
            'group_ids' => ['nullable', 'array', 'max:20'],
            'group_ids.*' => ['integer', 'distinct', 'exists:groups,id'],
            'everyone' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
            'start_position_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $userIds = collect($data['recipient_ids'] ?? [])
            ->when(isset($data['recipient_id']), fn ($ids) => $ids->push($data['recipient_id']))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $groupIds = collect($data['group_ids'] ?? [])->map(fn ($id) => (int) $id);
        $everyone = (bool) ($data['everyone'] ?? false);

        if ($userIds->isEmpty() && $groupIds->isEmpty() && !$everyone) {
            return response()->json(['message' => 'Choose at least one recipient, group or everyone.'], 422);
        }

        $targets = $this->resolveTargets($sender, $userIds, $groupIds, $everyone);
        if ($targets instanceof JsonResponse) {
            return $targets;
        }

        $alreadyOpen = UserRecommendation::query()
            ->where('sender_id', $sender->id)
            ->where('book_id', $book->id)
            ->whereIn('recipient_id', $targets->keys())
            ->whereNull('acknowledged_at')
            ->whereNull('dismissed_at')
            ->pluck('recipient_id')
            ->map(fn ($id) => (int) $id)
            ->values();
        $targets = $targets->except($alreadyOpen->all());

        if ($targets->isEmpty()) {
            $conflict = 'Everyone selected already has an open recommendation for this book from you.';
            if ($alreadyOpen->count() === 1) {
                $conflict = 'You have already sent this user an unacknowledged recommendation for this book.';
            }

            return response()->json(['message' => $conflict], 409);
        }

        $limiterKey = 'community-recommend:' . $sender->id;
        $maxSends = (int) config('community.recommendation_sends_per_hour', 30);
        if (RateLimiter::tooManyAttempts($limiterKey, $maxSends)) {
            return response()->json([
                'message' => 'Too many recommendations sent recently. Try again later.',
                'retryAfter' => RateLimiter::availableIn($limiterKey),
            ], 429);
        }
        RateLimiter::hit($limiterKey, 3600);

        $batchId = (string) Str::uuid();
        $created = $targets->map(function (array $target) use ($sender, $book, $data, $batchId) {
            $recipient = $target['user'];
            $recommendation = UserRecommendation::create([
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'book_id' => $book->id,
                'message' => $data['message'] ?? null,
                'batch_id' => $batchId,
                'audience_type' => $target['audience'],
                'group_id' => $target['group_id'],
                'start_position_ms' => $data['start_position_ms'] ?? null,
            ]);

            $this->notifier->notify($recipient, UserNotification::TYPE_RECOMMENDATION, $sender, [
                'recommendation_id' => $recommendation->id,
                'book_id' => $book->id,
                'book_title' => $book->title,
                'sender_name' => $sender->name,
                'message' => $recommendation->message,
            ]);

            return $recommendation;
        })->values();

        return response()->json([
            'message' => 'Recommendation sent successfully.',
            'batchId' => $batchId,
            'recipientCount' => $created->count(),
            'skippedRecipientIds' => $alreadyOpen,
        ], 201);
    }

    /** GET /recommendations/inbox — open (not acknowledged or dismissed) recommendations, newest first */
    public function inbox(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $recommendations = UserRecommendation::with(['sender', 'book:id,title,cover_image', 'group:id,name'])
            ->where('recipient_id', $user->id)
            ->whereNull('acknowledged_at')
            ->whereNull('dismissed_at')
            ->orderByDesc('id')
            ->limit($this->pageSize($request))
            ->get()
            ->map(fn (UserRecommendation $r) => $this->format($r));

        return response()->json($recommendations);
    }

    /** GET /recommendations/sent — what the current user sent, with each recipient's response */
    public function sent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $recommendations = UserRecommendation::query()
            ->with(['sender', 'recipient', 'book:id,title,cover_image', 'group:id,name'])
            ->where('sender_id', $user->id)
            ->orderByDesc('id')
            ->limit($this->pageSize($request))
            ->get();

        return response()->json($recommendations->map(function (UserRecommendation $r) {
            $recipient = $r->recipient;

            return $this->format($r) + [
                'recipient' => $recipient ? $this->person($recipient) : null,
                'recipientProgress' => $recipient ? $this->progressFor($recipient, $r->book_id) : null,
            ];
        }));
    }

    /** PATCH /recommendations/{recommendation} — recipient marks seen/dismissed or reacts/replies */
    public function update(Request $request, UserRecommendation $recommendation): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($recommendation->recipient_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $data = $request->validate([
            'seen' => ['nullable', 'boolean'],
            'dismissed' => ['nullable', 'boolean'],
            'reaction' => ['nullable', 'string', Rule::in(UserRecommendation::REACTIONS)],
            'reply' => ['nullable', 'string', 'max:500'],
        ]);

        if (($data['seen'] ?? false) && $recommendation->seen_at === null) {
            $recommendation->seen_at = now();
        }
        if (array_key_exists('dismissed', $data) && $data['dismissed'] !== null) {
            $recommendation->dismissed_at = $data['dismissed'] ? ($recommendation->dismissed_at ?? now()) : null;
        }

        $reactionChanged = isset($data['reaction']) && $data['reaction'] !== $recommendation->reaction;
        $replyChanged = isset($data['reply']) && $data['reply'] !== $recommendation->reply;
        if ($reactionChanged) {
            $recommendation->reaction = $data['reaction'];
        }
        if ($replyChanged) {
            $recommendation->reply = $data['reply'];
        }
        if ($reactionChanged || $replyChanged) {
            $recommendation->responded_at = now();
            $recommendation->seen_at ??= now();
        }
        $recommendation->save();
        $recommendation->load(['sender', 'book:id,title,cover_image', 'group:id,name']);

        $sender = $recommendation->sender;
        if ($sender !== null && ($reactionChanged || $replyChanged)) {
            $type = UserNotification::TYPE_RECOMMENDATION_REACTION;
            if ($replyChanged) {
                $type = UserNotification::TYPE_RECOMMENDATION_REPLY;
            }
            $this->notifier->notify($sender, $type, $user, [
                'recommendation_id' => $recommendation->id,
                'book_id' => $recommendation->book_id,
                'book_title' => $recommendation->book?->title,
                'recipient_name' => $user->name,
                'reaction' => $recommendation->reaction,
                'reply' => $recommendation->reply,
            ]);
        }

        return response()->json(['recommendation' => $this->format($recommendation)]);
    }

    /** POST /recommendations/{recommendation}/acknowledge — legacy clients' "done with this" */
    public function acknowledge(UserRecommendation $recommendation): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($recommendation->recipient_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if (is_null($recommendation->acknowledged_at)) {
            $recommendation->acknowledged_at = now();
            $recommendation->seen_at ??= now();
            $recommendation->save();
        }

        return response()->json([
            'message' => 'Recommendation acknowledged.',
            'recommendation' => $this->format(
                $recommendation->load(['sender', 'book:id,title,cover_image', 'group:id,name'])
            ),
        ]);
    }

    /**
     * Recipients keyed by user id, each with the audience that reached them. A person named
     * directly wins over reaching them through a group, which wins over "everyone".
     *
     * @param Collection<int, int> $userIds
     * @param Collection<int, int> $groupIds
     * @return Collection<int, array{user: User, audience: string, group_id: int|null}>|JsonResponse
     */
    private function resolveTargets(
        User $sender,
        Collection $userIds,
        Collection $groupIds,
        bool $everyone,
    ): Collection|JsonResponse {
        /** @var array<int, array{user: User, audience: string, group_id: int|null}> $targets */
        $targets = [];

        if ($everyone) {
            if (!$this->audience->canReachEveryone($sender)) {
                return response()->json(['message' => 'Only admins can recommend to everyone.'], 403);
            }
            foreach ($this->audience->reachableUsers($sender) as $user) {
                $targets[$user->id] = [
                    'user' => $user,
                    'audience' => UserRecommendation::AUDIENCE_ALL,
                    'group_id' => null,
                ];
            }
        }

        foreach (Group::whereIn('id', $groupIds)->get() as $group) {
            if (!$this->audience->canReachGroup($sender, $group)) {
                return response()->json(['message' => 'You can only recommend to groups you belong to.'], 403);
            }
            foreach ($this->audience->reachableGroupMembers($sender, $group) as $user) {
                $targets[$user->id] = [
                    'user' => $user,
                    'audience' => UserRecommendation::AUDIENCE_GROUP,
                    'group_id' => $group->id,
                ];
            }
        }

        foreach (User::whereIn('id', $userIds)->get() as $user) {
            if (!$this->audience->canReachUser($sender, $user)) {
                return response()->json([
                    'message' => 'You cannot send recommendations to that person.',
                    'recipientId' => $user->id,
                ], 403);
            }
            $targets[$user->id] = [
                'user' => $user,
                'audience' => UserRecommendation::AUDIENCE_USER,
                'group_id' => null,
            ];
        }

        return collect($targets);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(UserRecommendation $r): array
    {
        return [
            'id' => $r->id,
            'batchId' => $r->batch_id,
            'senderId' => $r->sender_id,
            'recipientId' => $r->recipient_id,
            'bookId' => $r->book_id,
            'message' => $r->message,
            'audienceType' => $r->audience_type,
            'groupId' => $r->group_id,
            'startPositionMs' => $r->start_position_ms,
            'createdAt' => $r->created_at?->toISOString(),
            'seenAt' => $r->seen_at?->toISOString(),
            'dismissedAt' => $r->dismissed_at?->toISOString(),
            'acknowledgedAt' => $r->acknowledged_at?->toISOString(),
            'reaction' => $r->reaction,
            'reply' => $r->reply,
            'respondedAt' => $r->responded_at?->toISOString(),
            'sender' => $r->sender ? $this->person($r->sender) : null,
            'group' => $r->group ? ['id' => $r->group->id, 'name' => $r->group->name] : null,
            'book' => $r->book ? [
                'id' => $r->book->id,
                'title' => $r->book->title,
                'coverImage' => $r->book->cover_image,
            ] : null,
        ];
    }

    /**
     * @return array{id: int, name: string|null, photoUrl: string|null}
     */
    private function person(User $user): array
    {
        $photo = $user->getRawOriginal('photo_url');

        return ['id' => $user->id, 'name' => $user->name, 'photoUrl' => is_string($photo) ? $photo : null];
    }

    /** "finished", "started" or null; only when the recipient chose to share progress with senders. */
    private function progressFor(User $recipient, int $bookId): ?string
    {
        if (!$recipient->community_share_progress) {
            return null;
        }

        $status = UserBookStatus::where('user_id', $recipient->id)->where('book_id', $bookId)->first();
        if ($status === null) {
            return null;
        }
        if ($status->finished_at !== null || $status->marked_read_at !== null) {
            return 'finished';
        }

        return $status->started_at !== null ? 'started' : null;
    }

    private function pageSize(Request $request): int
    {
        $max = (int) config('community.max_page_size', 100);

        return max(1, min($max, (int) $request->query('limit', (string) $max)));
    }
}
