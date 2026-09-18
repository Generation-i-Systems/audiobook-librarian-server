<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\PermissionGroup;
use App\Enums\PermissionKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named group of permissions assigned to users through the users.role column.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 */
class Role extends Model implements PermissionGroup
{
    /**
     * The permission bundle implied by ordinary library membership roles
     * (`user`, `library-user`, `hybrid-user`). Everything beyond this set
     * (user administration, infra/tooling access) stays with the admin roles
     * or explicit per-user grants.
     */
    public const STANDARD_USER_PERMISSIONS = [
        PermissionKey::MANAGE_BOOKS,
        PermissionKey::MANAGE_AUTHORS,
        PermissionKey::MANAGE_GENRES,
        PermissionKey::MANAGE_SERIES,
        PermissionKey::MANAGE_TAGS,
        PermissionKey::MANAGE_BADGES,
    ];

    /**
     * Canonical role key => label for every value users.role may hold.
     *
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        'user' => 'User',
        'library-user' => 'Library User (Local Books)',
        'librivox-user' => 'LibriVox User (LibriVox Books)',
        'hybrid-user' => 'Hybrid User (Local + LibriVox)',
        'admin' => 'Admin',
        'super-admin' => 'Super Admin',
        'unverified' => 'Unverified',
        'disabled' => 'Disabled',
    ];

    /**
     * Canonical role key => implied permission set. `null` means every
     * permission (admin roles); an empty array means none.
     *
     * @var array<string, array<int, PermissionKey>|null>
     */
    public const ROLE_PERMISSIONS = [
        'user' => self::STANDARD_USER_PERMISSIONS,
        'library-user' => self::STANDARD_USER_PERMISSIONS,
        // LibriVox subscribers are pure listeners; the LibriVox catalog is
        // managed through the admin section, not by per-user grants.
        'librivox-user' => [],
        'hybrid-user' => self::STANDARD_USER_PERMISSIONS,
        'admin' => null,
        'super-admin' => null,
        'unverified' => [],
        'disabled' => [],
    ];

    protected $fillable = [
        'key',
        'label',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function hasPermission(PermissionKey|string $key): bool
    {
        $key = $key instanceof PermissionKey ? $key->value : $key;

        return $this->permissions()->where('permissions.key', $key)->exists();
    }

    /**
     * @return array<int, string>
     */
    public function permissionKeys(): array
    {
        return $this->permissions()->pluck('permissions.key')->all();
    }

    /**
     * Role key => label for roles assignable from the admin user forms.
     * `disabled` is applied through account workflows, not chosen directly.
     *
     * @return array<string, string>
     */
    public static function assignable(): array
    {
        return array_diff_key(self::ROLE_LABELS, array_flip(['disabled']));
    }

    /**
     * Roles a pending (`unverified`) account may be verified into.
     *
     * @return array<string, string>
     */
    public static function verifiable(): array
    {
        return array_diff_key(self::ROLE_LABELS, array_flip(['unverified', 'disabled']));
    }

    /**
     * Every role key the application accepts for users.role values.
     *
     * @return array<int, string>
     */
    public static function keyList(): array
    {
        return array_keys(self::ROLE_PERMISSIONS);
    }
}
