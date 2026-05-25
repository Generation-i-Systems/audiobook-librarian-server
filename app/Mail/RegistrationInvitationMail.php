<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegistrationInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $email;

    public ?string $recipientName;

    public string $registerUrl;

    public function __construct(string $email, ?string $recipientName = null)
    {
        $this->email = $email;
        $this->recipientName = $recipientName;
        $this->registerUrl = url('/register');
    }

    public function build(): self
    {
        return $this
            ->subject('Sign up for ' . config('app.name'))
            ->view('emails.auth.registration-invitation')
            ->with([
                'email' => $this->email,
                'recipientName' => $this->recipientName,
                'registerUrl' => $this->registerUrl,
            ]);
    }
}
