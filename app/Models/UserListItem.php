<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_list_id
 * @property int $book_id
 * @property int $added_at
 * @property-read \App\Models\UserList $userList
 * @property-read \App\Models\Book $book
 */
class UserListItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_list_id', 'book_id', 'added_at'];

    protected $casts = ['added_at' => 'integer'];

    public function userList(): BelongsTo
    {
        return $this->belongsTo(UserList::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
