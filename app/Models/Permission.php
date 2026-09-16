<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $key
 * @property string $label
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Permission extends Model
{
    protected $fillable = [
        'key',
        'label',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'permission_user');
    }
}
