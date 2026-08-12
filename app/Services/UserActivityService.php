<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Badge;
use App\Models\Book;
use App\Models\BookPosition;
use App\Models\ListeningEvent;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class UserActivityService
{
    public function getUserActivityData(string $userId): array
    {
        try {
            /** @var User|null $user */
            $user = User::with([
                'badges.badge',
                'progress.book',
                'reviews.book',
                'recommendationsReceived.book',
                'recommendationsReceived.sender',
                'bookStatuses.book',
            ])->find($userId);

            if (!$user) {
                return [];
            }

            $allBadges = Badge::active()->get()->sort(function ($a, $b) {
                if ($a->sort_order !== $b->sort_order) {
                    return $a->sort_order <=> $b->sort_order;
                }

                $tiers = ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4, 'diamond' => 5];
                $weightA = $tiers[$a->tier] ?? 99;
                $weightB = $tiers[$b->tier] ?? 99;

                if ($weightA !== $weightB) {
                    return $weightA <=> $weightB;
                }

                return strcmp($a->name, $b->name);
            });

            $earnedBadgeIds = $user->badges->pluck('badge_id')->toArray();
            $badgesByCategory = $allBadges->groupBy('category')->map(
                fn ($badges) => $this->mapCategoryBadges($badges, $earnedBadgeIds, $user)
            );

            // Events not carrying a real playback position (downloads, app-open, etc.) must
            // never be the sole basis for "In Progress" — a book that's only ever had a
            // DOWNLOAD_COMPLETE event was never actually started. BOOK_MARK_COMPLETE isn't in
            // PositionMaterializer's list (it doesn't carry a position to materialize) but is
            // still a genuine engagement signal here.
            $positionCarryingEventTypes = [...PositionMaterializer::POSITION_CARRYING_EVENTS, 'BOOK_MARK_COMPLETE'];

            $listeningEvents = ListeningEvent::where('user_id', $userId)
                ->with('book')
                ->orderBy('timestamp_ms', 'desc')
                ->get()
                ->groupBy('book_id');

            $positionsByBookId = BookPosition::where('user_id', $userId)
                ->whereIn('book_id', $listeningEvents->keys())
                ->get()
                ->groupBy('book_id')
                ->map(fn ($rows) => $rows->sortByDesc('last_event_timestamp_ms')->first());

            $derivedProgress = $listeningEvents->map(function ($events, $bookId) use ($positionsByBookId, $positionCarryingEventTypes) {
                /** @var ListeningEvent $latest */
                $latest = $events->first();
                /** @var Book|null $book */
                $book = $latest->book;
                $metadata = $latest->metadata ?? [];
                $title = $book instanceof Book ? $book->title : ($metadata['fallbackTitle'] ?? 'Unknown Book');

                /** @var BookPosition|null $position */
                $position = $positionsByBookId->get($bookId);

                // Only trust the materialized position (deduplicated across devices, only ever
                // updated by real playback/completion events) when book_id resolves to a real,
                // known-duration book — a raw "latest event" can be a stale/unrelated event
                // (e.g. a chapter-index reset fired moments after finishing) that misrepresents
                // current progress, and a phantom book_id's completed flag can't be sanity-
                // checked against a duration at all, so it isn't trustworthy either.
                if ($position !== null && $book instanceof Book && $book->duration) {
                    $percentage = $this->clampPercentage(($position->position_ms / ($book->duration * 1000)) * 100);
                    // A position far short of the book's duration can't be a genuine finish —
                    // e.g. a stray/erroneous BOOK_FINISH event (see BookCompletionService).
                    $isPlausibleCompletion = $position->position_ms >= $book->duration * 1000 * BookCompletionService::MIN_COMPLETION_FRACTION;

                    return [
                        'book_id' => $bookId,
                        'book_title' => $title,
                        'percentage' => $percentage,
                        'last_listened_at' => Carbon::createFromTimestampMs((int) $position->last_event_timestamp_ms),
                        'status' => ($position->completed && $isPlausibleCompletion) ? 'Finished' : 'In Progress',
                    ];
                }

                $hasRealPlaybackEvent = $events->contains(fn (ListeningEvent $e) => in_array($e->event_type, $positionCarryingEventTypes, true));
                if (!$hasRealPlaybackEvent) {
                    return [
                        'book_id' => $bookId,
                        'book_title' => $title,
                        'percentage' => 0.0,
                        'last_listened_at' => Carbon::createFromTimestampMs($latest->timestamp_ms),
                        'status' => 'Downloaded',
                    ];
                }

                // A BOOK_FINISH/BOOK_MARK_COMPLETE event elsewhere in the group is a genuine
                // completion signal even if it isn't the absolute latest event — e.g. a
                // chapter-index reset firing moments after a finish, which would otherwise
                // hide the completion behind a stale 0% (this only reaches here when book_id
                // is a phantom id with no materialized BookPosition to fall back on instead).
                $finishEvent = $events->first(fn (ListeningEvent $e) => in_array($e->event_type, ['BOOK_FINISH', 'BOOK_MARK_COMPLETE'], true));
                $eventForStatus = $finishEvent ?? $latest;
                $statusMetadata = $eventForStatus->metadata ?? [];

                $percentage = isset($statusMetadata['progress_percentage'])
                    ? $this->clampPercentage((float) $statusMetadata['progress_percentage'])
                    : (($book instanceof Book && $book->duration)
                        ? $this->clampPercentage(($eventForStatus->position_ms / ($book->duration * 1000)) * 100)
                        : 0.0);

                $isCompleted = $finishEvent !== null || $percentage >= 95;

                return [
                    'book_id' => $bookId,
                    'book_title' => $title,
                    'percentage' => $percentage,
                    'last_listened_at' => Carbon::createFromTimestampMs($latest->timestamp_ms),
                    'status' => $isCompleted ? 'Finished' : 'In Progress',
                ];
            })->values();

            $derivedStatuses = $derivedProgress->map(function ($item) {
                return [
                    'book_id' => $item['book_id'],
                    'book_title' => $item['book_title'],
                    'status' => $item['status'],
                    'updated_at' => $item['last_listened_at'],
                ];
            });

            return [
                'badges_by_category' => $badgesByCategory->toArray(),
                'progress' => $derivedProgress->toArray(),
                'reviews' => $user->reviews->map(fn ($r) => [
                    'book_id' => $r->book_id,
                    'book_title' => $r->book->title,
                    'comment' => $r->comment,
                    'age_rating' => $r->age_rating,
                    'content_rating' => $r->content_rating,
                    'created_at' => $r->created_at,
                ])->toArray(),
                'recommendations' => $user->recommendationsReceived->map(fn ($rec) => [
                    'book_id' => $rec->book_id,
                    'book_title' => $rec->book->title,
                    'sender_name' => $rec->sender?->name,
                    'message' => $rec->message,
                    'created_at' => $rec->created_at,
                    'acknowledged_at' => $rec->acknowledged_at,
                ])->toArray(),
                'statuses' => $derivedStatuses->toArray(),
                'tips' => $this->getBadgeTips($userId),
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getUserActivityData failed: ' . $e->getMessage());

            return [];
        }
    }

    private function clampPercentage(float $percentage): float
    {
        return min(100.0, max(0.0, $percentage));
    }

    public function getBadgeTips(string $userId): array
    {
        $allBadges = Badge::active()->ordered()->get();
        $earnedBadgeIds = UserBadge::where('user_id', $userId)->pluck('badge_id')->toArray();
        $tips = [];

        foreach ($allBadges->groupBy('category') as $category => $badges) {
            $unearnedBadges = $badges->filter(function ($badge) use ($earnedBadgeIds) {
                return !in_array($badge->id, $earnedBadgeIds);
            });

            if ($unearnedBadges->isEmpty()) {
                continue;
            }

            $nextBadge = $unearnedBadges->first();
            $iconPath = "images/badges/{$nextBadge->key}.svg";
            $hasIconFile = file_exists(public_path($iconPath));

            $tips[] = [
                'category' => $category,
                'badge_name' => $nextBadge->name,
                'description' => $nextBadge->description,
                'tip' => "Aim for the '{$nextBadge->name}' badge: {$nextBadge->description}",
                'icon' => $hasIconFile ? "/{$iconPath}" : null,
                'emoji' => $nextBadge->icon,
            ];
        }

        return $tips;
    }

    private function mapCategoryBadges(mixed $badges, array $earnedBadgeIds, User $user): array
    {
        $filteredBadges = collect([]);
        $foundNextUnearned = false;

        foreach ($badges as $badge) {
            $isEarned = in_array($badge->id, $earnedBadgeIds);

            if ($isEarned) {
                $filteredBadges->push($badge);
            } elseif (!$foundNextUnearned) {
                $filteredBadges->push($badge);
                $foundNextUnearned = true;
            }
        }

        return $filteredBadges->map(function (Badge $badge) use ($earnedBadgeIds, $user): array {
            $isEarned = in_array($badge->id, $earnedBadgeIds);
            $userBadge = $isEarned ? $user->badges->firstWhere('badge_id', $badge->id) : null;
            $iconPath = "images/badges/{$badge->key}.svg";
            $hasIconFile = file_exists(public_path($iconPath));

            return [
                'id' => $badge->id,
                'name' => $badge->name,
                'icon' => $hasIconFile ? "/{$iconPath}" : null,
                'emoji' => $badge->icon,
                'description' => $badge->description,
                'tier' => $badge->tier,
                'is_earned' => $isEarned,
                'earned_at' => $userBadge?->earned_at,
            ];
        })->all();
    }
}
