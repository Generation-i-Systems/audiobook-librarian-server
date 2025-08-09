<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $name = $this->command->ask('Admin name', 'Admin');
        $username = $this->command->ask('Admin username', 'admin');
        $email = $this->command->ask('Admin email', 'admin@example.com');
        $password = $this->command->secret('Admin password');

        // Validate input (optional, but good practice)
        $validator = Validator::make([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            $this->command->error('Validation failed: ' . $validator->errors()->first());
            return;
        }

        // Create the admin user in MySQL
        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        $this->command->info('Admin user created successfully!');
    }
}
