<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\BookContribution;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookContributionSubmittedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly BookContribution $contribution)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Book edit awaiting approval: ' . $this->contribution->book->title)
            ->view('emails.books.contribution-submitted');
    }
}
