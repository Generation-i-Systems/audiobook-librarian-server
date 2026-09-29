<?php

declare(strict_types=1);

namespace App\Models\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $owner_user_id
 * @property string $route_key
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int $response_status
 * @property string $response_body
 * @property array<string, string>|null $response_headers
 * @property \Illuminate\Support\Carbon $expires_at
 */
class ImportIdempotencyKey extends Model
{
    protected $table = 'import_idempotency_keys';

    protected $fillable = [
        'owner_user_id',
        'route_key',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_body',
        'response_headers',
        'expires_at',
    ];

    protected $casts = [
        'owner_user_id' => 'integer',
        'response_status' => 'integer',
        'response_headers' => 'array',
        'expires_at' => 'datetime',
    ];
}
