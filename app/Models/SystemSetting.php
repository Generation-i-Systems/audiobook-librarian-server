<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable((new static())->getTable())) {
            return $default;
        }

        $setting = static::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (! Schema::hasTable((new static())->getTable())) {
            return;
        }

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
