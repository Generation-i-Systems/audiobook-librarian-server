<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seeders = [
            GenreSeeder::class,
            CanonicalBadgeSeeder::class,
            ActionBadgeSeeder::class,
        ];

        if (env('DEMO_MODE', false)) {
            $seeders[] = DemoUsersSeeder::class;
        }

        $this->call($seeders);
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Admin',
        //     'email' => 'admin@localhost',
        // ]);
    }
}
