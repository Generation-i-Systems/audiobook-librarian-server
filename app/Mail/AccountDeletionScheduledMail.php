<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountDeletionScheduledMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $cancellationUrl,
        public readonly ?string $recipientName,
        public readonly string $scheduledFor,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Your account deletion is scheduled')
            ->view('emails.auth.account-deletion-scheduled');
    }
}
