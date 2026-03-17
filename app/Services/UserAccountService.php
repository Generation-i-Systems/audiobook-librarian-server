<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserAccountService
{
    public function getUserById(mixed $identifier): ?array
    {
        $columns = [
            'id',
            'name',
            'username',
            'email',
            'role',
            'email_verified_at',
            'created_at',
            'updated_at',
        ];

        if (Schema::hasColumn('users', 'photo_url')) {
            $columns[] = 'photo_url';
        }

        if (Schema::hasColumn('users', 'google_id')) {
            $columns[] = 'google_id';
        }

        $user = User::select($columns)->find($identifier);

        if (!$user) {
            return null;
        }

        $result = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        $photoUrl = $user->photo_url ?? $user->photoUrl ?? null;

        if ($photoUrl) {
            $result['photo_url'] = $photoUrl;
        }

        $googleId = $user->google_id ?? $user->googleId ?? null;

        if ($googleId) {
            $result['google_id'] = $googleId;
        }

        return $result;
    }

    public function getUserByCredentials(mixed $credentials): ?array
    {
        if (empty($credentials['password'])) {
            return null;
        }

        $user = null;

        if (!empty($credentials['email'])) {
            $user = User::where('email', $credentials['email'])->first();
        } elseif (!empty($credentials['username'])) {
            $user = User::where('username', $credentials['username'])->first();
        }

        if (!$user) {
            return null;
        }

        if (Hash::check($credentials['password'], $user->getAuthPassword())) {
            return $user->toArray();
        }

        return null;
    }

    public function getUserByRememberToken(mixed $identifier, mixed $token): ?array
    {
        $user = User::where('id', $identifier)->where('remember_token', $token)->first();

        return $user ? $user->toArray() : null;
    }

    public function createUser(array $data): string
    {
        $username = $data['username'] ?? explode('@', $data['email'])[0];
        $originalUsername = $username;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $originalUsername . $counter;
            $counter++;
        }

        $user = User::create([
            'name' => $data['name'],
            'username' => $username,
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'] ?? 'library-user',
            'email_verified_at' => $data['email_verified_at'] ?? null,
            'google_id' => $data['google_id'] ?? null,
            'facebook_id' => $data['facebook_id'] ?? null,
            'apple_id' => $data['apple_id'] ?? null,
        ]);

        return (string) $user->id;
    }

    public function updateUser(string $id, array $data): User
    {
        $user = User::findOrFail($id);
        $user->update($data);

        return $user;
    }

    public function deleteUser(string $id): int
    {
        return User::where('id', $id)->delete();
    }

    public function getUserByEmail(string $email): ?array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return null;
        }

        return $user->makeVisible(['password'])->toArray();
    }

    public function getUserByAppleId(string $appleId): ?array
    {
        $appleId = trim($appleId);

        if ($appleId === '') {
            return null;
        }

        $user = User::where('apple_id', $appleId)->first();

        if (!$user) {
            return null;
        }

        return $user->makeVisible(['password'])->toArray();
    }

    public function getUserByDiscordId(string $discordId): ?array
    {
        $discordId = trim($discordId);

        if ($discordId === '') {
            return null;
        }

        $user = User::where('discord_id', $discordId)->first();

        if (!$user) {
            return null;
        }

        return $user->makeVisible(['password'])->toArray();
    }

    public function userExistsByEmail(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    public function userExistsByUsername(string $username): bool
    {
        return User::where('username', $username)->exists();
    }

    public function validateUserCredentials(mixed $user, array $credentials): bool
    {
        if (!isset($credentials['password'])) {
            return false;
        }

        if (is_array($user)) {
            $user = User::find($user['id'] ?? null);

            if (!$user) {
                return false;
            }
        }

        return Hash::check($credentials['password'], $user->password);
    }

    public function getUserByUsername(string $username): ?array
    {
        $user = User::where('username', $username)->first();

        if (!$user) {
            return null;
        }

        return $user->makeVisible(['password'])->toArray();
    }

    public function getAdminUsers(): array
    {
        return User::whereIn('role', ['admin', 'super-admin'])->get()->toArray();
    }

    public function isAdmin(string $userId): bool
    {
        $user = User::find($userId);

        return $user && in_array($user->role, ['admin', 'super-admin'], true);
    }

    public function updateRememberToken(string $identifier, string $token): void
    {
        $user = User::find($identifier);

        if ($user) {
            $user->setRememberToken($token);
            $user->save();
        }
    }

    public function getPendingAccountRequests(): array
    {
        try {
            return DB::table('account_requests')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService getPendingAccountRequests failed: ' . $e->getMessage());

            return [];
        }
    }

    public function getAccountRequest(string $id): ?array
    {
        try {
            $request = DB::table('account_requests')->where('id', $id)->first();

            return $request ? (array) $request : null;
        } catch (\Exception $e) {
            Log::error('MySqlService getAccountRequest failed: ' . $e->getMessage());

            return null;
        }
    }

    public function approveAccountRequest(string $id): bool
    {
        try {
            DB::beginTransaction();

            $request = DB::table('account_requests')->where('id', $id)->first();

            if (!$request) {
                DB::rollBack();

                return false;
            }

            DB::table('account_requests')
                ->where('id', $id)
                ->update(['status' => 'approved', 'updated_at' => now()]);

            User::create([
                'name' => $request->name ?? '',
                'email' => $request->email ?? '',
                'username' => $request->username ?? '',
                'password' => Hash::make($request->password ?? Str::random(10)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('MySqlService approveAccountRequest failed: ' . $e->getMessage());

            return false;
        }
    }

    public function rejectAccountRequest(string $id): bool
    {
        try {
            $updated = DB::table('account_requests')
                ->where('id', $id)
                ->update([
                    'status' => 'rejected',
                    'updated_at' => now(),
                ]);

            return $updated > 0;
        } catch (\Exception $e) {
            Log::error('MySqlService rejectAccountRequest failed: ' . $e->getMessage());

            return false;
        }
    }
}
