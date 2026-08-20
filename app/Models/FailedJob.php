<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property \Illuminate\Support\Carbon $failed_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class FailedJob extends Model
{
    public $timestamps = false;

    protected $table = 'failed_jobs';
}
