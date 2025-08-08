<?php

namespace App\Console\Commands;

use App\Models\Narrator;
use Illuminate\Console\Command;

class UpdateNarratorNormalizedNames extends Command
{
    protected $signature = 'narrators:normalize-names {--no-backup : Skip automatic database backup}';
    protected $description = 'Update the normalized_name field for all narrators (creates a database backup by default)';

    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before normalizing narrator names...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        $this->info('Starting to normalize narrator names...');
        $count = 0;

        Narrator::chunk(100, function ($narrators) use (&$count) {
            foreach ($narrators as $narrator) {
                $normalizedName = Narrator::normalizeName($narrator->name);
                if ($narrator->normalized_name !== $normalizedName) {
                    $narrator->update(['normalized_name' => $normalizedName]);
                    $count++;
                }
            }
        });

        $this->info("Updated normalized names for {$count} narrators.");
        return Command::SUCCESS;
    }
}
