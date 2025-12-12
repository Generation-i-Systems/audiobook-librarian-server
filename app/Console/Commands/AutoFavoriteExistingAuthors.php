<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\FavoriteAuthor;
use App\Models\User;
use Illuminate\Console\Command;

class AutoFavoriteExistingAuthors extends Command
{
    protected $signature = 'favorites:auto-add-existing
                            {--user= : User ID or email to add favorites for (default: admin user)}';

    protected $description = 'Auto-favorite all authors that already have books in the library';

    public function handle(): int
    {
        $user = $this->determineUser();

        if (!$user) {
            $this->error('No admin user found or specified user not found');

            return Command::FAILURE;
        }

        $this->info("Auto-favoriting authors for user: {$user->name} ({$user->email})");

        $authors = Author::has('books')->get();

        $this->info("Found {$authors->count()} authors with books");

        $added = 0;
        $skipped = 0;

        foreach ($authors as $author) {
            $exists = FavoriteAuthor::where('user_id', $user->id)
                ->where('author_name', $author->name)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            FavoriteAuthor::create([
                'user_id' => $user->id,
                'author_name' => $author->name,
                'notify_email' => true,
            ]);

            $added++;
            $this->line("  ✓ Added: {$author->name}");
        }

        $this->info("\nComplete!");
        $this->info("Added: $added");
        $this->info("Skipped (already favorited): $skipped");

        return Command::SUCCESS;
    }

    protected function determineUser(): ?User
    {
        if ($userOption = $this->option('user')) {
            if (is_numeric($userOption)) {
                return User::find($userOption);
            }

            return User::where('email', $userOption)->first();
        }

        return User::where('role', 'admin')->first();
    }
}
