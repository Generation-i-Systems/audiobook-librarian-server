<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\CamelCaseAttributeAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStatistic extends Model
{
    use CamelCaseAttributeAccess;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'statistic_key',
        'value',
    ];

    protected $casts = [
        'value' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Safely increments a user's statistic value.
     */
    public static function incrementUserStatistic(int $userId, string $statisticKey, int $amount = 1): void
    {
        // Non-destructive operation: increments existing record or creates a new one
        self::firstOrCreate(
            ['user_id' => $userId, 'statistic_key' => $statisticKey],
            ['value' => 0]
        )->increment('value', $amount);
    }

    /**
     * Safely decrements a user's statistic value, preventing negative values.
     */
    public static function decrementUserStatistic(int $userId, string $statisticKey, int $amount = 1): void
    {
        $stat = self::where('user_id', $userId)
            ->where('statistic_key', $statisticKey)
            ->first();

        if ($stat && $stat->value > 0) {
            $stat->decrement('value', $amount);
        }
    }
}
