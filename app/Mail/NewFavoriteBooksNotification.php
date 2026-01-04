<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewFavoriteBooksNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public array $books,
        public string $userName
    ) {
    }

    public function envelope(): Envelope
    {
        $count = count($this->books);
        $subject = $count === 1 ? 'New Audiobook by Your Favorite Author' : "New Audiobooks by Your Favorite Authors ($count)";

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-favorite-books',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
