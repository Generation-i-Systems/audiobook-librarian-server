<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckTableExistence extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-table-existence {table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks if a specified table exists in the database.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tableName = $this->argument('table');

        if (Schema::hasTable($tableName)) {
            $this->info("Table '{$tableName}' exists.");
        } else {
            $this->error("Table '{$tableName}' does not exist.");
        }
    }
}
