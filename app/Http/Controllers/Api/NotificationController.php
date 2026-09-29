<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Community\CommunityNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The user's community notifications, fetched by clients during sync (no push).
 * `since` is the id cursor of the newest notification the device already has.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly CommunityNotifier $notifier)
    {
    }

    /** GET /notifications?since={id}&limit={n} — oldest first after the cursor */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $data = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);
        $max = (int) config('community.max_page_size', 100);
        $limit = min($max, (int) ($data['limit'] ?? $max));
        $since = (int) ($data['since'] ?? 0);

        $page = UserNotification::with('actor')
            ->where('user_id', $user->id)
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $page->count() > $limit;
        $notifications = $page->take($limit);

        return response()->json([
            'notifications' => $notifications->map(fn (UserNotification $n) => $this->format($n))->values(),
            'cursor' => $notifications->last()?->id ?? $since,
            'has_more' => $hasMore,
            'unread_count' => $this->unreadCount($user),
        ]);
    }

    /** POST /notifications/read — mark the given ids, or everything, as read */
    public function markRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = UserNotification::where('user_id', $user->id)->whereNull('read_at');
        if (!($data['all'] ?? false)) {
            $query->whereIn('id', $data['ids'] ?? []);
        }
        $updated = $query->update(['read_at' => now()]);

        return response()->json(['updated' => $updated, 'unread_count' => $this->unreadCount($user)]);
    }

    /** GET /notifications/preferences */
    public function preferences(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json(['preferences' => $this->notifier->preferencesFor($user)]);
    }

    /** PUT /notifications/preferences — {preferences: {type: show|badge|off}} */
    public function updatePreferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        /** @var array<string, string> $defaults */
        $defaults = config('community.notification_defaults', []);
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['string', Rule::in(UserNotification::DELIVERIES)],
        ]);

        $unknown = array_diff(array_keys($data['preferences']), array_keys($defaults));
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown notification type.',
                'types' => array_values($unknown),
            ], 422);
        }

        foreach ($data['preferences'] as $type => $delivery) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'type' => $type],
                ['delivery' => $delivery],
            );
        }

        return response()->json(['preferences' => $this->notifier->preferencesFor($user)]);
    }

    private function unreadCount(User $user): int
    {
        return UserNotification::where('user_id', $user->id)->whereNull('read_at')->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function format(UserNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'delivery' => $n->delivery,
            'actor' => $n->actor ? ['id' => $n->actor->id, 'name' => $n->actor->name] : null,
            'payload' => $n->payload ?? [],
            'read_at' => $n->read_at?->toISOString(),
            'created_at' => $n->created_at?->toISOString(),
        ];
    }
}
