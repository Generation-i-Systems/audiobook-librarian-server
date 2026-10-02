<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Message;
use App\Models\User;

/**
 * Message history for the apps: everything a user received or sent, read or not. Admin-written
 * messages store "subject\n\nbody" in `content`; messages sent from the app keep their subject in
 * `payload.subject`. Both are presented as subject + body so clients need no such knowledge.
 */
class MessageHistoryService
{
    public const BOX_RECEIVED = 'received';
    public const BOX_SENT = 'sent';
    public const BOXES = [self::BOX_RECEIVED, self::BOX_SENT];

    private const SUBJECT_MAX_LENGTH = 255;

    public function __construct(private readonly DocumentStoreServiceInterface $documentStoreService)
    {
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, perPage: int, total: int, unread: int}}
     */
    public function page(User $user, string $box, int $page, int $perPage): array
    {
        $column = $box === self::BOX_SENT ? 'sender_id' : 'recipient_id';
        $query = Message::query()->where($column, $user->id);
        $total = (clone $query)->count();

        $messages = $query->with(['sender:id,name,username', 'recipient:id,name,username'])
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => $messages->map(fn (Message $message) => $this->format($message))->values()->all(),
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'unread' => $this->unreadCount($user->id),
            ],
        ];
    }

    /**
     * Newest message id and unread count, so an idle sync tells the client whether to fetch history.
     *
     * @return array{cursor: int, unread: int}
     */
    public function summary(int $userId): array
    {
        return [
            'cursor' => (int) (Message::where('recipient_id', $userId)->max('id') ?? 0),
            'unread' => $this->unreadCount($userId),
        ];
    }

    public function send(User $sender, User $recipient, ?string $subject, string $content): ?string
    {
        return $this->documentStoreService->createMessage([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'type' => 'general',
            'content' => $content,
            'payload' => $subject !== null && $subject !== '' ? ['subject' => $subject] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Message $message): array
    {
        [$subject, $body] = $this->splitContent($message);

        return [
            'id' => $message->id,
            'type' => $message->type,
            'subject' => $subject,
            'body' => $body,
            'sender' => $message->sender ? $this->person($message->sender) : null,
            'recipient' => $this->person($message->recipient),
            'readAt' => $message->acknowledged_at?->toISOString(),
            'createdAt' => $message->created_at?->toISOString(),
        ];
    }

    private function unreadCount(int $userId): int
    {
        return Message::where('recipient_id', $userId)->whereNull('acknowledged_at')->count();
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function splitContent(Message $message): array
    {
        $payload = is_array($message->payload) ? $message->payload : [];
        $storedSubject = $payload['subject'] ?? null;
        if (is_string($storedSubject) && $storedSubject !== '') {
            return [$storedSubject, $message->content];
        }

        $separator = strpos($message->content, "\n\n");
        if ($separator !== false && $separator > 0 && $separator <= self::SUBJECT_MAX_LENGTH) {
            $subject = substr($message->content, 0, $separator);
            if (!str_contains($subject, "\n")) {
                return [$subject, ltrim(substr($message->content, $separator + 2))];
            }
        }

        return [null, $message->content];
    }

    /**
     * @return array{id: int, name: string|null, username: string|null}
     */
    private function person(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'username' => $user->username];
    }
}
