<?php

namespace App\Events;

use App\Models\Book;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewBookAdded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $book;

    public function __construct(Book $book)
    {
        $this->book = $book;
    }
}
