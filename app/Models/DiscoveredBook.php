<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscoveredBook extends Model
{
    protected $fillable = [
        'abb_id',
        'title',
        'author',
        'narrator',
        'category',
        'url',
        'description',
        'cover_url',
        'metadata',
        'discovered_at',
        'notified',
    ];

    protected $casts = [
        'metadata' => 'array',
        'discovered_at' => 'datetime',
        'notified' => 'boolean',
    ];

    public function scopeNotNotified($query)
    {
        return $query->where('notified', false);
    }

    public function scopeByAuthor($query, string $authorName)
    {
        return $query->where('author', 'LIKE', '%' . $authorName . '%');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
