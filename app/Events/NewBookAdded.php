<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewBookAdded
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The book data array from Firestore
     *
     * @var array
     */
    public $book;


    /**
     * Create a new event instance.
     *
     * @param  array  $book  The book data from Firestore
     * @return void
     */
    public function __construct(array $book)
    {
        $this->book = $book;
    }
}
