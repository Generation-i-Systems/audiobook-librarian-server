<?php

namespace App\Models;

use App\Contracts\Permissible;
use App\Enums\PermissionKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property bool $community_family_only
 * @property bool $community_share_progress
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
 * @property-read \App\Models\Role|null $authRole
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
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
class User extends Authenticatable implements Permissible
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
        'must_change_password',
        'parent_user_id',
        'is_filter_manager',
        'community_family_only',
        'community_share_progress',
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
        'deletion_requested_at' => 'datetime',
        'deletion_scheduled_for' => 'datetime',
        'book_list_preferences' => 'array',
        'password' => 'hashed',
        'is_filter_manager' => 'boolean',
        'community_family_only' => 'boolean',
        'community_share_progress' => 'boolean',
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

    /** The account this user belongs to: their own id, or their parent's id if managed. */
    public function accountRootId(): int
    {
        return $this->parent_user_id ?? $this->id;
    }

    /** @return BelongsTo<User, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    /** @return HasMany<User, $this> */
    public function managedUsers(): HasMany
    {
        return $this->hasMany(User::class, 'parent_user_id');
    }

    /**
     * Whether this user may edit system (account-wide) tag filters for $target's
     * account: a full admin, the account's parent, or a member flagged as a
     * designated filter manager for that same account.
     */
    public function canManageSystemFiltersFor(User $target): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->accountRootId() !== $target->accountRootId()) {
            return false;
        }

        return $this->is_filter_manager || $this->managedUsers()->exists();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user');
    }

    /**
     * The Role row matching this user's users.role key (roles are groups of
     * permissions). Null when the role string has no seeded role row.
     */
    public function authRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'key');
    }

    public function hasPermission(PermissionKey|string $key): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $key = $key instanceof PermissionKey ? $key->value : $key;

        if ($this->permissions()->where('permissions.key', $key)->exists()) {
            return true;
        }

        return $this->authRole?->hasPermission($key) ?? false;
    }

    /**
     * Every effective permission key: admin resolves to all permissions,
     * otherwise the user's role bundle plus any direct per-user grants.
     *
     * @return array<int, string>
     */
    public function permissionKeys(): array
    {
        if ($this->isAdmin()) {
            return array_map(
                fn (PermissionKey $permissionKey) => $permissionKey->value,
                PermissionKey::cases()
            );
        }

        $keys = $this->permissions()->pluck('permissions.key')->all();
        $roleKeys = $this->authRole?->permissionKeys() ?? [];

        return array_values(array_unique(array_merge($keys, $roleKeys)));
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)->withPivot('progress', 'last_listened')->withTimestamps();
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_members')->withTimestamps();
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
