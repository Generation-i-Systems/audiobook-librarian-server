<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkinCustomization extends Model
{
    use HasFactory;

    protected $fillable = [
        'skin_id',
        'user_id',
        'type',
        'value',
        'file_path',
        'visibility',
    ];

    public function skin(): BelongsTo
    {
        return $this->belongsTo(Skin::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
