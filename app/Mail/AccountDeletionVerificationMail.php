<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountDeletionVerificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $verificationCode,
        public readonly ?string $recipientName,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Confirm your account deletion request')
            ->view('emails.auth.account-deletion-verification');
    }
}
