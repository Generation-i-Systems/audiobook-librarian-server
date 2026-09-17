<?php

declare(strict_types=1);

namespace App\Auth;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Resolves authenticated users for the session/token guards as full
 * App\Models\User Eloquent models.
 *
 * Historically this returned the lightweight App\Auth\DocumentstoreUser
 * wrapper (a Firestore-era optimization). That created two different
 * "Auth::user()" shapes depending on how the request was authenticated
 * (session => DocumentstoreUser, API bearer token => Eloquent User),
 * and any code touching Eloquent relations or non-whitelisted attributes
 * crashed or silently read nulls in production while tests using
 * actingAs() passed. Returning the Eloquent model everywhere removes
 * that class of bug.
 */
class DocumentUserProvider implements UserProvider
{
    /**
     * Log detailed authentication state for debugging
     *
     * @return void
     */
    public static function logAuthState()
    {
        $auth = app('auth');
        $user = $auth->user();

        Log::debug('Auth State:', [
            'is_authenticated' => $auth->check(),
            'user_id' => $user ? $user->getAuthIdentifier() : null,
            'user_class' => $user ? get_class($user) : null,
            'guard' => get_class($auth->guard()),
            'session_id' => session()->getId(),
        ]);
    }

    /**
     * Rehash the user's password if necessary. Laravel 11+ requirement.
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): string
    {
        Log::debug('DocumentUserProvider::rehashPasswordIfRequired called', [
            'user_id' => $user->getAuthIdentifier(),
            'user_class' => $user::class,
            'force' => $force,
            'has_password' => isset($credentials['password']),
        ]);

        $plain = $credentials['password'] ?? null;
        $currentHash = $user->getAuthPassword();
        if ($plain && ($force || \Illuminate\Support\Facades\Hash::needsRehash($currentHash))) {
            return \Illuminate\Support\Facades\Hash::make($plain);
        }

        return $currentHash;
    }

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function retrieveById($identifier)
    {
        $user = $this->documentStoreService->getUserById($identifier);

        return $user ? (new User())->newFromBuilder((array) $user) : null;
    }

    public function retrieveByToken($identifier, $token)
    {
        $user = $this->documentStoreService->getUserByRememberToken($identifier, $token);

        return $user ? (new User())->newFromBuilder((array) $user) : null;
    }

    public function updateRememberToken(Authenticatable $user, $token)
    {
        $this->documentStoreService->updateRememberToken((string) $user->getAuthIdentifier(), $token);
    }

    public function retrieveByCredentials(array $credentials)
    {
        if (empty($credentials)) {
            return null;
        }

        if (!empty($credentials['email'])) {
            return User::where('email', $credentials['email'])->first();
        }

        if (!empty($credentials['username'])) {
            return User::where('username', $credentials['username'])->first();
        }

        return null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        if (!isset($credentials['password']) || $credentials['password'] === '') {
            return false;
        }

        return Hash::check($credentials['password'], (string) $user->getAuthPassword());
    }
}
