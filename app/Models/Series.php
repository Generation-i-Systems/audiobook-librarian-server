<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;

class Series extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use Auditable;

    protected $fillable = ['name'];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_series')
            ->withPivot('series_number')
            ->withTimestamps();
    }
}
