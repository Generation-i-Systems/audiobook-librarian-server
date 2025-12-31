<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewBookAdded
{
    use Dispatchable;
    use SerializesModels;

    public array $book;

    public function __construct(array $book)
    {
        $this->book = $book;
    }
}
