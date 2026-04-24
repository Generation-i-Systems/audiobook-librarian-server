<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LibriVoxDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::setDefaultConnection('librivox');

        $this->call(LibriVoxAdminUserSeeder::class);
        $this->call(GenreSeeder::class);
    }
}
