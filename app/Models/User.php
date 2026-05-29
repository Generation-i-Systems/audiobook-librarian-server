<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property string|null $photo_url
 * @property string $password
 * @property string $role
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property bool $is_admin
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserBookStatus> $bookStatuses
 * @property-read int|null $book_statuses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Book> $books
 * @property-read int|null $books_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BookTag> $bookTags
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserBookStatus> $queuedBooks
 * @property-read int|null $queued_books_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserBadge> $badges
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BookProgress> $progress
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Review> $reviews
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserRecommendation> $recommendationsReceived
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsAdmin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhotoUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTrashed()
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use CamelCaseAttributeAccess;
    use Auditable;
    use SoftDeletes;

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
        'google_id',
        'facebook_id',
        'apple_id',
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

    public function getIsAdminAttribute(): bool
    {
        return $this->isAdmin();
    }

    public function isAdmin(): bool
    {
        $role = $this->role ?? 'user';

        return in_array($role, ['admin', 'super-admin'], true);
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)->withPivot('progress', 'last_listened')->withTimestamps();
    }

    public function bookStatuses(): HasMany
    {
        return $this->hasMany(UserBookStatus::class);
    }

    public function queuedBooks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->bookStatuses()->where('status', 'queue')->orderBy('order');
    }


    public function favoritedAuthors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'user_author_favorites')->withTimestamps();
    }

    public function favoritedSeries(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'user_series_favorites')->withTimestamps();
    }

    public function badges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(BookProgress::class);
    }

    public function recommendationsSent(): HasMany
    {
        return $this->hasMany(UserRecommendation::class, 'sender_id');
    }

    public function recommendationsReceived(): HasMany
    {
        return $this->hasMany(UserRecommendation::class, 'recipient_id');
    }

    public function bookTags(): HasMany
    {
        return $this->hasMany(BookTag::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(\App\Models\Review::class);
    }

    /**
     * Get the user's photo URL with fallback to last completed book's cover
     *
     * @param mixed $value
     * @return string|null
     */
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
