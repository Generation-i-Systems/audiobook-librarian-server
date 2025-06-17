<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

class DocumentstoreUser implements Authenticatable
{
    protected $user;

    public function __construct(array $user)
    {
        $this->user = $user;
    }

    public function getAuthIdentifierName()
    {
        return 'id'; // or whatever your Documentstore user ID field is
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
