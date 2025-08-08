<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;

class Genre extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use Auditable;

    protected $fillable = ['name'];

    public function books()
    {
        return $this->belongsToMany(Book::class);
    }
}
