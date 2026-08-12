<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\BookPosition;
use App\Models\ListeningEvent;
use App\Services\BookIdResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-attributes book_positions/listening_events rows stuck under a phantom book_id (the
 * client sent a local-only id that was never matched to a real catalog book — see
 * EventController::sync()'s use of BookIdResolver, added after this bug was found) to their
 * real book_id, using the same path-suffix / title+author resolution used at ingestion time.
 *
 * Defaults to a dry run — nothing is written unless --apply is passed. A book_positions
 * reassignment that would collide with an existing (user_id, real_book_id, device_id) row is
 * always reported as a conflict and skipped, never auto-merged.
 */
class RepairPhantomBookIds extends Command
{
    protected $signature = 'books:repair-phantom-book-ids
                            {--apply : Actually write the reassignments (default is dry-run/report only)}
                            {--user= : Limit to a specific user id}
                            {--keep-existing-on-conflict : For conflicts with no BOOK_FINISH evidence on either side, discard the phantom row and keep whatever real row already exists, instead of leaving both for manual review}';

    protected $description = 'Re-attribute book_positions/listening_events rows stuck under a phantom (unresolved) book_id to their real book_id';

    public function __construct(private readonly BookIdResolver $resolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $keepExistingOnConflict = (bool) $this->option('keep-existing-on-conflict');

        $this->info($apply ? 'APPLYING reassignments...' : 'DRY RUN — no changes will be made (pass --apply to write them)');
        $this->newLine();

        $realBookIds = Book::pluck('id')->all();

        $phantomPairs = $this->phantomUserBookPairs($realBookIds, $userId);

        if ($phantomPairs->isEmpty()) {
            $this->info('No phantom book_id rows found.');

            return self::SUCCESS;
        }

        $this->info("Found {$phantomPairs->count()} distinct (user, phantom book_id) pair(s) to check.");
        $this->newLine();

        $resolvedCount = 0;
        $unresolvedCount = 0;
        $conflictCount = 0;
        $mergedCount = 0;

        foreach ($phantomPairs as $pair) {
            [$pairUserId, $phantomBookId] = $pair;

            $sampleEvent = ListeningEvent::where('user_id', $pairUserId)
                ->where('book_id', $phantomBookId)
                ->whereNotNull('metadata')
                ->orderByDesc('timestamp_ms')
                ->get()
                ->first(function (ListeningEvent $event) {
                    $metadata = $event->metadata ?? [];

                    return !empty($metadata['bookPath'] ?? null) || !empty($metadata['fallbackTitle'] ?? null);
                });

            $bookPath = $sampleEvent?->metadata['bookPath'] ?? null;
            $fallbackTitle = $sampleEvent?->metadata['fallbackTitle'] ?? null;
            $fallbackAuthor = $sampleEvent?->metadata['fallbackAuthor'] ?? null;

            $resolvedId = $this->resolver->resolve($phantomBookId, $bookPath, $fallbackTitle, $fallbackAuthor);

            if ($resolvedId === null) {
                $unresolvedCount++;
                $this->line("  <fg=yellow>UNRESOLVED</> user={$pairUserId} phantom_id={$phantomBookId} title=" . ($fallbackTitle ?? '(none)'));
                continue;
            }

            $realBook = Book::find($resolvedId);
            $eventCount = ListeningEvent::where('user_id', $pairUserId)->where('book_id', $phantomBookId)->count();
            $positionRows = BookPosition::where('user_id', $pairUserId)->where('book_id', $phantomBookId)->get();

            $this->line("  <fg=green>RESOLVED</> user={$pairUserId} phantom_id={$phantomBookId} -> book_id={$resolvedId} (\"{$realBook?->title}\") — {$eventCount} listening_events row(s), {$positionRows->count()} book_positions row(s)");

            foreach ($positionRows as $positionRow) {
                $conflict = BookPosition::where('user_id', $pairUserId)
                    ->where('book_id', $resolvedId)
                    ->where('device_id', $positionRow->device_id)
                    ->first();

                if ($conflict === null) {
                    if ($apply) {
                        DB::table('book_positions')
                            ->where('user_id', $pairUserId)
                            ->where('book_id', $phantomBookId)
                            ->where('device_id', $positionRow->device_id)
                            ->update(['book_id' => $resolvedId]);
                    }
                    continue;
                }

                $merged = $this->mergeOnGenuineCompletion($pairUserId, $phantomBookId, $resolvedId, $positionRow->device_id);

                if ($merged === null) {
                    if ($keepExistingOnConflict) {
                        $conflictCount++;
                        $this->line("    <fg=yellow>DISCARDED</> device={$positionRow->device_id}: no completion evidence either side — keeping existing real row, discarding phantom row (position_ms={$positionRow->position_ms})");

                        if ($apply) {
                            DB::table('book_positions')
                                ->where('user_id', $pairUserId)
                                ->where('book_id', $phantomBookId)
                                ->where('device_id', $positionRow->device_id)
                                ->delete();
                        }
                        continue;
                    }

                    $conflictCount++;
                    $this->line("    <fg=red>CONFLICT</> device={$positionRow->device_id}: phantom row (position_ms={$positionRow->position_ms}, completed=" . ($positionRow->completed ? 'yes' : 'no') . ", last_event=" . $positionRow->last_event_timestamp_ms . ') vs existing real row (position_ms=' . $conflict->position_ms . ', completed=' . ($conflict->completed ? 'yes' : 'no') . ', last_event=' . $conflict->last_event_timestamp_ms . ') — skipped, needs manual merge');
                    continue;
                }

                $mergedCount++;
                $this->line("    <fg=cyan>MERGED</> device={$positionRow->device_id}: found a genuine completion event, merging into position_ms={$merged['position_ms']}, completed=yes");

                if ($apply) {
                    DB::table('book_positions')
                        ->where('user_id', $pairUserId)
                        ->where('book_id', $resolvedId)
                        ->where('device_id', $positionRow->device_id)
                        ->update([
                            'position_ms' => $merged['position_ms'],
                            'completed' => true,
                            'last_event_timestamp_ms' => $merged['last_event_timestamp_ms'],
                            'progress_percentage' => 100,
                        ]);

                    DB::table('book_positions')
                        ->where('user_id', $pairUserId)
                        ->where('book_id', $phantomBookId)
                        ->where('device_id', $positionRow->device_id)
                        ->delete();
                }
            }

            if ($apply) {
                ListeningEvent::where('user_id', $pairUserId)
                    ->where('book_id', $phantomBookId)
                    ->update(['book_id' => $resolvedId]);
            }

            $resolvedCount++;
        }

        $this->newLine();
        $this->info("Resolved: {$resolvedCount}, unresolved: {$unresolvedCount}, merged on genuine completion: {$mergedCount}, conflicts needing manual review: {$conflictCount}");

        if (!$apply && $resolvedCount > 0) {
            $this->newLine();
            $this->warn('This was a dry run. Re-run with --apply to write these reassignments.');
        }

        return self::SUCCESS;
    }

    /**
     * A materialized book_positions row can show a misleading position (e.g. 0) despite
     * completed=true, because a later non-finishing event (a chapter-index reset, a stray
     * SEEK) updates position_ms without ever clearing the completed flag — the row's raw
     * fields alone can't be trusted to decide a conflict. Searching the full event history on
     * both sides of the conflict for a genuine BOOK_FINISH/BOOK_MARK_COMPLETE event finds the
     * real signal underneath. Returns null (no merge) when neither side has one — in that case
     * the conflict is a real "which position is current" question needing a human.
     *
     * @return array{position_ms: int, last_event_timestamp_ms: int}|null
     */
    private function mergeOnGenuineCompletion(int $userId, int $phantomBookId, int $realBookId, string $deviceId): ?array
    {
        $finishEvent = ListeningEvent::where('user_id', $userId)
            ->whereIn('book_id', [$phantomBookId, $realBookId])
            ->where('device_id', $deviceId)
            ->whereIn('event_type', ['BOOK_FINISH', 'BOOK_MARK_COMPLETE'])
            ->orderByDesc('position_ms')
            ->first();

        if ($finishEvent === null) {
            return null;
        }

        $latestTimestamp = ListeningEvent::where('user_id', $userId)
            ->whereIn('book_id', [$phantomBookId, $realBookId])
            ->where('device_id', $deviceId)
            ->max('timestamp_ms');

        return [
            'position_ms' => (int) $finishEvent->position_ms,
            'last_event_timestamp_ms' => (int) ($latestTimestamp ?? $finishEvent->timestamp_ms),
        ];
    }

    /**
     * @param array<int, int> $realBookIds
     * @return \Illuminate\Support\Collection<int, array{0: int, 1: int}>
     */
    private function phantomUserBookPairs(array $realBookIds, ?int $userId): \Illuminate\Support\Collection
    {
        $eventPairs = ListeningEvent::query()
            ->whereNotIn('book_id', $realBookIds ?: [0])
            ->where('book_id', '!=', 0)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->select('user_id', 'book_id')
            ->distinct()
            ->get()
            ->map(fn ($row) => [(int) $row->user_id, (int) $row->book_id]);

        $positionPairs = BookPosition::query()
            ->whereNotIn('book_id', $realBookIds ?: [0])
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->select('user_id', 'book_id')
            ->distinct()
            ->get()
            ->map(fn ($row) => [(int) $row->user_id, (int) $row->book_id]);

        return $eventPairs->concat($positionPairs)->unique(fn ($pair) => $pair[0] . ':' . $pair[1])->values();
    }
}
