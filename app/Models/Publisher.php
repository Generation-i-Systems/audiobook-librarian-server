<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Publisher extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'website',
        'logo',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically generate slug from name when creating a new publisher
        static::creating(function ($publisher) {
            if (empty($publisher->slug)) {
                $publisher->slug = Str::slug($publisher->name);
            }
        });

        // Update slug when name changes
        static::updating(function ($publisher) {
            if ($publisher->isDirty('name')) {
                $publisher->slug = Str::slug($publisher->name);
            }
        });
    }

    /**
     * Get the books published by this publisher.
     */
    public function books()
    {
        return $this->hasMany(Book::class);
    }

    /**
     * Scope a query to only include active publishers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return 'slug';
    }
}
