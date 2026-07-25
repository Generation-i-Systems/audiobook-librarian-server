<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'              => 'Admin',
                'username'          => 'admin',
                'password'          => Hash::make('admin'),
                'email_verified_at' => now(),
                'role'              => 'admin',
                'is_admin'          => false,
            ]
        );

        User::updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'name'              => 'User',
                'username'          => 'user',
                'password'          => Hash::make('user'),
                'email_verified_at' => now(),
                'role'              => 'user',
                'is_admin'          => false,
            ]
        );

        $this->command->info('Demo users seeded: admin/admin and user/user');
    }
}
