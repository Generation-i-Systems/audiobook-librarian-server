<?php

namespace App\Auth;

use App\Services\FirestoreService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Log;

class FirestoreUserProvider implements UserProvider
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
            'session_data' => session()->all(),
        ]);
    }

    /**
     * Rehash the user's password if necessary. Laravel 11+ requirement.
     *
     * @param  string  $password
     */
    /**
     * Rehash the user's password if necessary. Laravel 11+ requirement.
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): string
    {
        Log::debug('FirestoreUserProvider::rehashPasswordIfRequired called with user=' .
            print_r($user, true) . ', credentials=' . print_r($credentials, true) . ', force=' . $force);
        // Get the plain password from credentials
        $plain = $credentials['password'] ?? null;
        $currentHash = $user->getAuthPassword();
        if ($plain && ($force || \Illuminate\Support\Facades\Hash::needsRehash($currentHash))) {
            return \Illuminate\Support\Facades\Hash::make($plain);
        }

        return $currentHash;
    }

    protected $firestore;

    public function __construct()
    {
        $this->firestore = new FirestoreService();
    }

    public function retrieveById($identifier)
    {
        $user = $this->firestore->getUserById($identifier);

        return $user ? new FirestoreUser($user) : null;
    }

    public function retrieveByToken($identifier, $token)
    {
        $user = $this->firestore->getUserByRememberToken($identifier, $token);

        return $user ? new FirestoreUser($user) : null;
    }

    public function updateRememberToken(Authenticatable $user, $token)
    {
        // Update the "remember me" token in Firestore
        $this->firestore->updateRememberToken($user->getAuthIdentifier(), $token);
    }

    public function retrieveByCredentials(array $credentials)
    {
        if (empty($credentials)) {
            return null;
        }
        $user = $this->firestore->getUserByCredentials($credentials);

        return $user ? new FirestoreUser($user) : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        // Validate user credentials (e.g., password)
        return $this->firestore->validateUserCredentials(
            $user,
            $credentials
        );
    }
}
