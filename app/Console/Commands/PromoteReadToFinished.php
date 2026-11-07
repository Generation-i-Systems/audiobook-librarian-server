<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ReadToFinishedPromoter;
use Illuminate\Console\Command;

/**
 * One-time (and safe to repeat) fix for books already marked "read" whose last listening progress
 * is over 90%: finishes them at 100% the same way marking read now does (ReadToFinishedPromoter).
 *
 * `-n` is Laravel's own --no-interaction, so the preview is `--dry-run` only.
 */
class PromoteReadToFinished extends Command
{
    protected $signature = 'books:promote-read-to-finished
                            {--user= : Only this user id}
                            {--dry-run : List the books that would be finished without changing anything}';

    protected $description = 'Mark books that are marked read with over 90% listening progress as finished at 100%.';

    public function handle(ReadToFinishedPromoter $promoter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userOption = $this->option('user');

        $users = User::query()
            ->when($userOption !== null, fn ($query) => $query->where('id', (int) $userOption))
            ->whereHas('bookStatuses', fn ($query) => $query->whereNotNull('marked_read_at'))
            ->orderBy('id')
            ->get();

        $total = 0;
        foreach ($users as $user) {
            $candidates = $promoter->findCandidates((int) $user->id);
            if ($candidates === []) {
                if ($this->getOutput()->isVerbose()) {
                    $this->line("User {$user->id}: nothing to finish");
                }
                continue;
            }

            foreach ($candidates as $candidate) {
                $book = $candidate['book'];
                $this->line(sprintf(
                    '%sUser %d: book %d "%s" (%.1f%%)',
                    $dryRun ? '[dry run] ' : '',
                    $user->id,
                    $book->id,
                    $book->title,
                    $candidate['progress'],
                ));
            }

            if (! $dryRun) {
                $promoter->promoteForUser($user, array_map(fn (array $c): int => (int) $c['book']->id, $candidates));
            }
            $total += count($candidates);
        }

        $this->info($dryRun
            ? "{$total} book(s) would be marked finished."
            : "{$total} book(s) marked finished.");

        return self::SUCCESS;
    }
}
