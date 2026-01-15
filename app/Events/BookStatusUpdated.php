<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use App\Models\Book;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookStatusUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public User $user,
        public Book $book,
        public string $status,
        public ?string $previousStatus = null
    ) {
    }
}
