<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $email
 * @property string $code_hash
 * @property string $magic_token_hash
 * @property bool $allow_signup
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class EmailOtp extends Model
{
    use HasFactory;

    protected $table = 'email_otps';

    protected $fillable = [
        'email',
        'code_hash',
        'magic_token_hash',
        'allow_signup',
        'type',
        'attempts',
        'expires_at',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'allow_signup' => 'boolean',
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public const MAX_ATTEMPTS = 5;
    public const TTL_MINUTES = 10;

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isLockedOut(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function isRedeemable(): bool
    {
        return !$this->isUsed() && !$this->isExpired() && !$this->isLockedOut();
    }
}
