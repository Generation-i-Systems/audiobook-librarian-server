<?php

namespace App\Console\Commands;

use App\Models\Narrator;
use Illuminate\Console\Command;

class UpdateNarratorNormalizedNames extends Command
{
    protected $signature = 'narrators:normalize-names';
    protected $description = 'Update the normalized_name field for all narrators';

    public function handle()
    {
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
