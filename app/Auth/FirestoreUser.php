<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

class FirestoreUser implements Authenticatable
{
    protected $user;

    public function __construct(array $user)
    {
        \Log::debug('FirestoreUser::__construct', ['user' => $user]);
        $this->user = $user;
    }

    public function getAuthIdentifierName()
    {
        return 'id'; // or whatever your Firestore user ID field is
    }

    public function getAuthIdentifier()
    {
        return $this->user['id'] ?? null;
    }

    public function getAuthPassword()
    {
        return $this->user['password'] ?? null;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getRememberToken()
    {
        return $this->user['remember_token'] ?? null;
    }

    public function setRememberToken($value)
    {
        $this->user['remember_token'] = $value;
    }

    // Allow property-style access (e.g., Auth::user()->name)
    public function __get($key)
    {
        return $this->user[$key] ?? null;
    }

    /*
    // Ensure user is serializable for session storage
    public function __serialize(): array
    {
        return ['user' => $this->user];
    }

    public function __unserialize(array $data): void
    {
        $this->user = $data['user'];
    }
    */

    public function getRememberTokenName()
    {
        return 'remember_token';
    }

    // Optionally, expose the raw user array
    public function getRawUser()
    {
        return $this->user;
    }
}
