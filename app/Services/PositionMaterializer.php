<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookPosition;
use Illuminate\Support\Facades\Log;

class PositionMaterializer
{
    /** @var list<string> also referenced by UserActivityService to distinguish real listening
     * activity from download/app-open-only noise. */
    public const POSITION_CARRYING_EVENTS = [
        'PLAY_START',
        'PLAY_PAUSE',
        'PLAY_RESUME',
        'PLAY_STOP',
        'SESSION_START',
        'SESSION_END',
        'SEEK',
        'BOOK_FINISH',
    ];

    /**
     * Update the materialized position from a listening event.
     * Only updates if the event is newer than the current record.
     *
     * @return bool true if this event just started or just finished a book for the user —
     *   i.e. their recommendation exclusion set changed and shelves should be recomputed.
     */
    public function materialize(array $eventData): bool
    {
        if (! in_array($eventData['event_type'], self::POSITION_CARRYING_EVENTS, true)) {
            return false;
        }

        $existing = BookPosition::where('user_id', $eventData['user_id'])
            ->where('book_id', $eventData['book_id'])
            ->where('device_id', $eventData['device_id'])
            ->first();

        if ($existing && $existing->last_event_timestamp_ms >= $eventData['timestamp_ms']) {
            return false;
        }

        $metadata = $eventData['metadata'] ?? [];

        $attributes = [
            'position_ms' => $eventData['position_ms'],
            'last_event_timestamp_ms' => $eventData['timestamp_ms'],
            'last_event_id' => $eventData['id'],
        ];

        if (isset($metadata['chapterIndex'])) {
            $attributes['current_chapter'] = $metadata['chapterIndex'];
        }
        if (isset($metadata['chapterName'])) {
            $attributes['current_chapter_name'] = $metadata['chapterName'];
        }
        if ($eventData['event_type'] === 'BOOK_FINISH') {
            $attributes['completed'] = true;
        }

        $wasCompleted = $existing !== null && $existing->completed;
        $hadAnyPriorPosition = BookPosition::where('user_id', $eventData['user_id'])
            ->where('book_id', $eventData['book_id'])
            ->exists();

        BookPosition::updateOrCreate(
            [
                'user_id' => $eventData['user_id'],
                'book_id' => $eventData['book_id'],
                'device_id' => $eventData['device_id'],
            ],
            $attributes,
        );

        $justFinished = ($attributes['completed'] ?? false) && ! $wasCompleted;
        $justStarted = ! $hadAnyPriorPosition && ($eventData['position_ms'] ?? 0) > 0;

        return $justFinished || $justStarted;
    }

    /**
     * Materialize a batch of events.
     * Events should be ordered by timestamp_ms ascending for correctness.
     */
    public function materializeBatch(array $events): void
    {
        foreach ($events as $event) {
            try {
                $this->materialize($event);
            } catch (\Exception $e) {
                Log::warning('Failed to materialize position from event', [
                    'event_id' => $event['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
