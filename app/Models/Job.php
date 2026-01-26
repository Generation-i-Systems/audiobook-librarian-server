<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property string $type
 * @property string $status
 * @property array $payload
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Job whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Job extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;

    protected $fillable = [
        'type',
        'status',
        'payload',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
