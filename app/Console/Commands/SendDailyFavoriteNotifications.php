<?php

namespace App\Console\Commands;

use App\Mail\NewFavoriteBooksNotification;
use App\Models\DiscoveredBook;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendDailyFavoriteNotifications extends Command
{
    protected $signature = 'favorites:send-notifications
                            {--force : Send notifications even if already sent today}';

    protected $description = 'Send daily email notifications for new books by favorite authors';

    public function handle(): int
    {
        $this->info('Sending favorite author notifications...');

        $users = User::has('favoritedAuthors')->get();

        if ($users->isEmpty()) {
            $this->info('No users with favorite authors found');

            return Command::SUCCESS;
        }

        $totalEmailsSent = 0;

        foreach ($users as $user) {
            $newBooks = $this->getNewBooksForUser($user);

            if ($newBooks->isEmpty()) {
                $this->comment("No new books for {$user->name}");
                continue;
            }

            $this->info("Sending {$newBooks->count()} new books to {$user->name} ({$user->email})");

            try {
                Mail::to($user->email)->send(
                    new NewFavoriteBooksNotification(
                        $newBooks->toArray(),
                        $user->name
                    )
                );

                $this->markBooksAsNotified($newBooks->pluck('id')->toArray());

                $totalEmailsSent++;
                $this->line("  ✓ Email sent to {$user->email}");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed to send email to {$user->email}: {$e->getMessage()}");
            }
        }

        $this->info("\nComplete! Sent $totalEmailsSent email(s)");

        return Command::SUCCESS;
    }

    protected function getNewBooksForUser(User $user)
    {
        $favoriteAuthors = $user->favoritedAuthors()
            ->where('notify_email', true)
            ->pluck('author_name')
            ->map(fn ($name) => strtolower(trim($name)))
            ->toArray();

        if (empty($favoriteAuthors)) {
            return collect([]);
        }

        $query = DiscoveredBook::notNotified();

        if (!$this->option('force')) {
            $query->where('discovered_at', '>=', now()->subDay());
        }

        $allBooks = $query->get();

        return $allBooks->filter(function ($book) use ($favoriteAuthors) {
            if (empty($book->author)) {
                return false;
            }

            $bookAuthor = strtolower(trim($book->author));

            foreach ($favoriteAuthors as $favoriteAuthor) {
                if (str_contains($bookAuthor, $favoriteAuthor) || str_contains($favoriteAuthor, $bookAuthor)) {
                    return true;
                }
            }

            return false;
        });
    }

    protected function markBooksAsNotified(array $bookIds): void
    {
        DiscoveredBook::whereIn('id', $bookIds)->update(['notified' => true]);
    }
}
