<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $string_id
 * @property string $entity_type
 * @property int|null $entity_ref_id
 * @property string $entity_value
 * @property string $entity_label
 * @property int $created_at
 * @property-read \App\Models\User $user
 */
class BlockedEntity extends Model
{
    public $timestamps = false;

    public const TYPE_BOOK = 'BOOK';
    public const TYPE_AUTHOR = 'AUTHOR';
    public const TYPE_SERIES = 'SERIES';
    public const TYPE_TAG = 'TAG';
    public const TYPES = [self::TYPE_BOOK, self::TYPE_AUTHOR, self::TYPE_SERIES, self::TYPE_TAG];

    protected $fillable = [
        'user_id', 'string_id', 'entity_type', 'entity_ref_id', 'entity_value', 'entity_label', 'created_at',
    ];

    protected $casts = [
        'entity_ref_id' => 'integer',
        'created_at' => 'integer',
    ];

    /** Route-model bind on the client-generated uuid, not the internal auto-increment id. */
    public function getRouteKeyName(): string
    {
        return 'string_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
