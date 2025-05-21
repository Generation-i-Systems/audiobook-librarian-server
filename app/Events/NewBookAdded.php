<?php

namespace App\Events;

use App\Services\FirestoreService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewBookAdded
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $book;

    public function __construct(Book $book)
    {
        $this->book = $book;
    }
}
