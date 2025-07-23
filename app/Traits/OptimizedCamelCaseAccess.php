<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * Optimized version of CamelCaseAttributeAccess that caches conversions 
 * and avoids expensive string operations on every toArray() call
 */
trait OptimizedCamelCaseAccess
{
    /**
     * Static cache for snake_case to camelCase conversions
     */
    protected static array $camelCaseCache = [];
    
    /**
     * Static cache for camelCase to snake_case conversions  
     */
    protected static array $snakeCaseCache = [];

    /**
     * Get an attribute from the model.
     */
    public function __get($key)
    {
        // Use cached conversion or compute once
        $snakeCaseKey = $this->getCachedSnakeCase($key);

        // If the snake_case key exists as an attribute, return it
        if (array_key_exists($snakeCaseKey, $this->attributes)) {
            return parent::__get($snakeCaseKey);
        }

        // Otherwise, fall back to default Laravel behavior
        return parent::__get($key);
    }

    /**
     * Set a given attribute on the model.
     */
    public function __set($key, $value)
    {
        $snakeCaseKey = $this->getCachedSnakeCase($key);

        if ($this->isFillable($snakeCaseKey) || array_key_exists($snakeCaseKey, $this->attributes)) {
            return parent::__set($snakeCaseKey, $value);
        }

        return parent::__set($key, $value);
    }

    /**
     * Determine if an attribute or relation exists on the model.
     */
    public function __isset($key)
    {
        $snakeCaseKey = $this->getCachedSnakeCase($key);
        return parent::__isset($snakeCaseKey) || parent::__isset($key);
    }

    /**
     * Unset an attribute on the model.
     */
    public function __unset($key)
    {
        $snakeCaseKey = $this->getCachedSnakeCase($key);
        parent::__unset($snakeCaseKey);
        parent::__unset($key);
    }

    /**
     * Convert the model's attributes to an array.
     * OPTIMIZED: Pre-build conversion map to avoid repeated Str::camel() calls
     */
    public function toArray()
    {
        $attributes = parent::toArray();
        
        // Build conversion map once for this model's attributes
        static $conversionMap = null;
        if ($conversionMap === null) {
            $conversionMap = [];
            foreach (array_keys($attributes) as $key) {
                $conversionMap[$key] = $this->getCachedCamelCase($key);
            }
        }

        // Apply conversions using cached map
        $camelCaseAttributes = [];
        foreach ($attributes as $key => $value) {
            $camelKey = $conversionMap[$key] ?? $this->getCachedCamelCase($key);
            $camelCaseAttributes[$camelKey] = $value;
        }

        return $camelCaseAttributes;
    }
    
    /**
     * Get cached snake_case conversion
     */
    protected function getCachedSnakeCase(string $key): string
    {
        if (!isset(static::$snakeCaseCache[$key])) {
            static::$snakeCaseCache[$key] = Str::snake($key);
            
            // Prevent cache from growing too large
            if (count(static::$snakeCaseCache) > 1000) {
                static::$snakeCaseCache = array_slice(static::$snakeCaseCache, -500, null, true);
            }
        }
        
        return static::$snakeCaseCache[$key];
    }
    
    /**
     * Get cached camelCase conversion
     */
    protected function getCachedCamelCase(string $key): string
    {
        if (!isset(static::$camelCaseCache[$key])) {
            static::$camelCaseCache[$key] = Str::camel($key);
            
            // Prevent cache from growing too large
            if (count(static::$camelCaseCache) > 1000) {
                static::$camelCaseCache = array_slice(static::$camelCaseCache, -500, null, true);
            }
        }
        
        return static::$camelCaseCache[$key];
    }
}