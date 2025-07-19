<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use ArrayAccess;

/**
 * Lightweight user class that safely wraps user data for authentication
 * without causing memory issues or circular references.
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
        'email' => null,
        'password' => null,
        'remember_token' => null,
        'is_admin' => false,
    ];

    /**
     * @param array|object $user User data as array or object with arrayable interface
     */
    public function __construct($user)
    {
        if (is_array($user)) {
            $this->userData = array_merge($this->userData, array_intersect_key($user, $this->userData));
        } elseif (is_object($user)) {
            // Safely extract data from object
            $this->userData['id'] = $this->safeGet($user, 'getKey') ?: $this->safeGet($user, 'id');
            $this->userData['email'] = $this->safeGet($user, 'email');
            $this->userData['password'] = $this->safeGet($user, 'getAuthPassword') ?: $this->safeGet($user, 'password');
            $this->userData['remember_token'] = $this->safeGet($user, 'getRememberToken') ?: $this->safeGet($user, 'remember_token');
            $this->userData['is_admin'] = (bool)($this->safeGet($user, 'isAdmin') ?: $this->safeGet($user, 'is_admin') ?: false);
        } else {
            throw new \InvalidArgumentException('User must be an array or an object');
        }
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
        return (bool)($this->userData['is_admin'] ?? false);
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
