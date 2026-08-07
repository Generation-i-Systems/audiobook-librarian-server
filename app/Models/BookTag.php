<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id  creator/last-editor, not the visibility scope
 * @property int $book_id
 * @property string $scope      system|group|user
 * @property int|null $group_id
 * @property string $owner_key  "system" | "group:{id}" | "user:{id}"
 * @property array<int, string> $tags
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BookTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'book_id',
        'scope',
        'group_id',
        'owner_key',
        'tags',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'book_id' => 'integer',
        'group_id' => 'integer',
        'tags' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public static function ownerKeyFor(string $scope, ?int $groupId, int $userId): string
    {
        return match ($scope) {
            'system' => 'system',
            'group' => 'group:' . $groupId,
            default => 'user:' . $userId,
        };
    }

    protected static function booted(): void
    {
        static::saving(function (BookTag $bookTag): void {
            if (!$bookTag->owner_key) {
                $bookTag->owner_key = self::ownerKeyFor(
                    $bookTag->scope ?? 'user',
                    $bookTag->group_id,
                    $bookTag->user_id
                );
            }
        });
    }
}
