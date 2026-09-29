<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserNotification;

/**
 * Records community notifications for clients to pick up during sync. Nothing is pushed:
 * the user's per-type preference decides whether the entry is shown as a local
 * notification, only counted on the inbox badge, or not recorded at all.
 */
class CommunityNotifier
{
    /**
     * @param array<string, mixed> $payload
     */
    public function notify(User $recipient, string $type, ?User $actor, array $payload): ?UserNotification
    {
        $delivery = $this->deliveryFor($recipient, $type);

        if ($delivery === UserNotification::DELIVERY_OFF) {
            return null;
        }

        return UserNotification::create([
            'user_id' => $recipient->id,
            'type' => $type,
            'delivery' => $delivery,
            'actor_id' => $actor?->id,
            'payload' => $payload,
        ]);
    }

    public function deliveryFor(User $user, string $type): string
    {
        $chosen = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->value('delivery');

        return is_string($chosen) ? $chosen : $this->defaultDelivery($type);
    }

    public function defaultDelivery(string $type): string
    {
        /** @var array<string, string> $defaults */
        $defaults = config('community.notification_defaults', []);

        return $defaults[$type] ?? UserNotification::DELIVERY_SHOW;
    }

    /**
     * Every known type with the user's effective delivery.
     *
     * @return array<string, string>
     */
    public function preferencesFor(User $user): array
    {
        /** @var array<string, string> $defaults */
        $defaults = config('community.notification_defaults', []);

        $chosen = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->pluck('delivery', 'type')
            ->all();

        return array_merge($defaults, array_intersect_key($chosen, $defaults));
    }
}
