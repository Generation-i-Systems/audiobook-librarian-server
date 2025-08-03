<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, CamelCaseAttributeAccess, Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'photo_url',
        'password',
        'role',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)->withPivot('progress', 'last_listened')->withTimestamps();
    }

    public function queuedBooks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'user_book_queues')->withPivot('order')->orderBy('order');
    }

    public function getPhotoUrlAttribute($value)
    {
        if ($value) {
            return $value;
        }

        $lastCompletedBook = $this->books()
            ->wherePivot('progress', '>=', 100)
            ->orderByPivot('last_listened', 'desc')
            ->first();

        if ($lastCompletedBook && $lastCompletedBook->cover_url) {
            return $lastCompletedBook->cover_url;
        }

        return null;
    }
}