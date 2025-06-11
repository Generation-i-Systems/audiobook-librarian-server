<?php

namespace App\Console\Commands;

use App\Services\FirestoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-admin-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an admin user if one does not exist';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $firestore = new FirestoreService();
        // Check if admin user exists in Firestore
        $adminUsers = $firestore->getClient()->collection('users')->where('role', '=', 'admin')->documents();
        foreach ($adminUsers as $doc) {
            if ($doc->exists()) {
                $this->info('An admin user already exists.');

                return 0;
            }
        }
        $password = Str::random(12);
        $firestore->getClient()->collection('users')->add([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make($password),
            'role' => 'admin',
        ]);
        $this->info('Admin user created!');
        $this->info('Email: admin@example.com');
        $this->info("Password: $password");

        return 0;
    }
}
