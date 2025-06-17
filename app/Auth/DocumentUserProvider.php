<?php

namespace App\Auth;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Log;

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
        Log::debug('DocumentUserProvider::rehashPasswordIfRequired called with user=' .
            print_r($user, true) . ', credentials=' . print_r($credentials, true) . ', force=' . $force);
        // Get the plain password from credentials
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

        return $user ? new DocumentstoreUser($user) : null;
    }

    public function retrieveByToken($identifier, $token)
    {
        $user = $this->documentStoreService->getUserByRememberToken($identifier, $token);

        return $user ? new DocumentstoreUser($user) : null;
    }

    public function updateRememberToken(Authenticatable $user, $token)
    {
        // Update the "remember me" token in Firestore
        $this->documentStoreService->updateRememberToken($user->getAuthIdentifier(), $token);
    }

    public function retrieveByCredentials(array $credentials)
    {
        if (empty($credentials)) {
            return null;
        }
        $user = $this->documentStoreService->getUserByCredentials($credentials);

        return $user ? new DocumentstoreUser($user) : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        // Validate user credentials (e.g., password)
        return $this->documentStoreService->validateUserCredentials(
            $user,
            $credentials
        );
    }
}
