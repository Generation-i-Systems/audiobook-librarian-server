<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property string $title
 * @property string $author
 * @property string|null $isbn
 * @property string|null $cover_url
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ClientBook extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;

    protected $fillable = [
        'title',
        'author',
        'isbn',
        'cover_url',
    ];

    public function progress()
    {
        return $this->hasMany(BookProgress::class, 'client_book_id');
    }
}
