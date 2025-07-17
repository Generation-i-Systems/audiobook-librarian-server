<?php

namespace Database\Seeders;

use Google\Cloud\Firestore\Timestamp as FirestoreTimestamp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

        $firestore = app(\App\Contracts\DocumentStoreServiceInterface::class);

        // Generate a unique ID for the user
        $userId = (string) Str::uuid();

        $userData = [
            'id' => $userId,
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            // 'created_at' => new FirestoreTimestamp(new \DateTime()),
            // 'updated_at' => new FirestoreTimestamp(new \DateTime()),
        ];

        // Add the user to Firestore
        $firestore->getClient()->collection('users')->document($userId)->set($userData);

        $this->command->info('Admin user created successfully!');
    }
}
