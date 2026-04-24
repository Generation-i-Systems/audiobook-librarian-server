<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class LibriVoxAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'eric.thelin@gmail.com'],
            [
                'name' => 'Eric Thelin',
                'username' => 'ET',
                'email' => 'eric.thelin@gmail.com',
                'password' => null,
                'role' => 'admin',
                'google_id' => '113635581962248140126',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('LibriVox admin user seeded.');
    }
}
