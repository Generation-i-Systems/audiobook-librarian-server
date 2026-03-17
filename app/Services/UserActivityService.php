<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Badge;
use App\Models\Book;
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

            $listeningEvents = ListeningEvent::where('user_id', $userId)
                ->with('book')
                ->orderBy('timestamp_ms', 'desc')
                ->get()
                ->groupBy('book_id');

            $derivedProgress = $listeningEvents->map(function ($events) {
                /** @var ListeningEvent $latest */
                $latest = $events->first();
                /** @var Book|null $book */
                $book = $latest->book;

                $percentage = 0;
                $metadata = $latest->metadata ?? [];

                if (isset($metadata['progress_percentage'])) {
                    $percentage = $metadata['progress_percentage'];
                } elseif ($book instanceof Book && $book->duration) {
                    $percentage = ($latest->position_ms / ($book->duration * 1000)) * 100;
                }

                $isCompleted = $latest->event_type === 'BOOK_FINISH'
                    || $latest->event_type === 'BOOK_MARK_COMPLETE'
                    || $percentage >= 95;

                return [
                    'book_id' => $latest->book_id,
                    'book_title' => $book ? $book->title : 'Unknown Book',
                    'percentage' => (float) $percentage,
                    'last_listened_at' => Carbon::createFromTimestampMs($latest->timestamp_ms),
                    'completed' => $isCompleted,
                ];
            })->values();

            $derivedStatuses = $derivedProgress->map(function ($item) {
                return [
                    'book_id' => $item['book_id'],
                    'book_title' => $item['book_title'],
                    'status' => $item['completed'] ? 'Finished' : 'In Progress',
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
