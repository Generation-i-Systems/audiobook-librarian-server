<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use ArrayAccess;

/**
 * Lightweight user class that safely wraps user data for authentication
 * without causing memory issues or circular references.
 *
 * @property string|null $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $password
 * @property string|null $remember_token
 * @property string $role
 * @property-read bool $is_admin
 */
class DocumentstoreUser implements Authenticatable
{
    /**
     * User data as a simple array
     *
     * @var array
     */
    protected $userData = [
        'id' => null,
        'name' => null,
        'email' => null,
        'password' => null,
        'remember_token' => null,
        'role' => 'library-user', // Default role
    ];

    /**
     * @param array $user User data as array
     */
    public function __construct(array $user)
    {
        $this->userData['id'] = $user['id'] ?? null;
        $this->userData['name'] = $user['name'] ?? null;
        $this->userData['email'] = $user['email'] ?? null;
        $this->userData['password'] = $user['password'] ?? null;
        $this->userData['remember_token'] = $user['remember_token'] ?? null;
        $this->userData['role'] = $user['role'] ?? 'library-user';
    }

    public function getAuthIdentifierName()
    {
        return 'id'; // or whatever your Documentstore user ID field is
    }

    /**
     * Safely get a property or method value from an object
     *
     * @param object $object
     * @param string $key
     * @return mixed
     */
    protected function safeGet($object, $key)
    {
        try {
            // Try method call
            if (is_callable([$object, $key])) {
                return $object->$key();
            }

            // Try direct property access
            if (property_exists($object, $key)) {
                return $object->$key;
            }

            // Try getAttribute for Eloquent models
            if (method_exists($object, 'getAttribute')) {
                return $object->getAttribute($key);
            }

            // Try array access if object implements ArrayAccess
            if ($object instanceof ArrayAccess && $object->offsetExists($key)) {
                return $object[$key];
            }
        } catch (\Exception $e) {
            // Silently fail to prevent memory leaks from error handling
            return null;
        }

        return null;
    }

    /**
     * Get the unique identifier for the user.
     *
     * @return mixed
     */
    public function getAuthIdentifier()
    {
        return $this->userData['id'] ?? null;
    }

    /**
     * Get the password for the user.
     *
     * @return string|null
     */
    public function getAuthPassword()
    {
        return $this->userData['password'] ?? null;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    /**
     * Get the token value for the "remember me" session.
     *
     * @return string|null
     */
    public function getRememberToken()
    {
        return $this->userData['remember_token'] ?? null;
    }

    /**
     * Set the token value for the "remember me" session.
     *
     * @param  string  $value
     * @return void
     */
    public function setRememberToken($value)
    {
        $this->userData['remember_token'] = $value;
    }

    /**
     * Determine if an attribute exists.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        return array_key_exists($key, $this->userData);
    }

    /**
     * Dynamically access the user's attributes.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        // Special case for is_admin to maintain backward compatibility
        if ($key === 'is_admin') {
            return $this->isAdmin();
        }

        // Only allow access to whitelisted properties
        if (array_key_exists($key, $this->userData)) {
            return $this->userData[$key];
        }

        return null;
    }

    /**
     * Determine if the user has admin privileges.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        $role = $this->userData['role'] ?? 'library-user';

        return in_array($role, ['admin', 'super-admin'], true);
    }

    /**
     * Get the column name for the "remember me" token.
     *
     * @return string
     */
    public function getRememberTokenName()
    {
        return 'remember_token';
    }

    /**
     * Get the raw user data as an array.
     *
     * @return array
     */
    public function getRawUser()
    {
        return $this->userData;
    }
}
