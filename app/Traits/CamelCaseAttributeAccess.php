<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait CamelCaseAttributeAccess
{
    /**
     * Get an attribute from the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        // Convert camelCase to snake_case for underlying attribute access
        $snakeCaseKey = Str::snake($key);

        // If the snake_case key exists as an attribute, return it
        if (array_key_exists($snakeCaseKey, $this->attributes)) {
            return parent::__get($snakeCaseKey);
        }

        // Otherwise, fall back to default Laravel behavior (e.g., accessors, relationships)
        return parent::__get($key);
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function __set($key, $value)
    {
        // Convert camelCase to snake_case for underlying attribute storage
        $snakeCaseKey = Str::snake($key);

        // If the snake_case key exists as a fillable attribute or in the attributes array,
        // set it using the snake_case key.
        if ($this->isFillable($snakeCaseKey) || array_key_exists($snakeCaseKey, $this->attributes)) {
            /** @phpstan-ignore-next-line return.void */
            parent::__set($snakeCaseKey, $value);
            return;
        }

        // Otherwise, fall back to default Laravel behavior (e.g., mutators)
        /** @phpstan-ignore-next-line return.void */
        parent::__set($key, $value);
    }

    /**
     * Determine if an attribute or relation exists on the model.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        return parent::__isset(Str::snake($key)) || parent::__isset($key);
    }

    /**
     * Unset an attribute on the model.
     *
     * @param  string  $key
     * @return void
     */
    public function __unset($key)
    {
        parent::__unset(Str::snake($key));
        parent::__unset($key);
    }

    /**
     * Convert the model's attributes to an array.
     *
     * @return array
     */
    public function toArray()
    {
        $attributes = parent::toArray();

        $camelCaseAttributes = [];
        foreach ($attributes as $key => $value) {
            $camelCaseAttributes[Str::camel($key)] = $value;
        }

        return $camelCaseAttributes;
    }
}
