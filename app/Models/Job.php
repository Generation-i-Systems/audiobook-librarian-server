<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\CamelCaseAttributeAccess;

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
