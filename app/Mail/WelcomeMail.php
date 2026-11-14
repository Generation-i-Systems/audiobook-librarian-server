<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent the first time a user account becomes usable — a fresh admin-created
 * account, or a self-service signup that an admin just verified. Unlike
 * EmailOtpMail (used for every subsequent sign-in), this also links to the
 * client apps (store or beta, per ClientAppInstallOptions) and to the existing
 * "connect this device to the server" flow.
 */
class WelcomeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $code,
        public string $magicLinkUrl,
        public int $ttlMinutes,
        public ?string $recipientName,
        public string $connectUrl,
        public string $serverAddress,
        /** @var list<array<string, mixed>> see ClientAppInstallOptions::fromConfig() */
        public array $installOptions,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject('Welcome to ' . config('app.name', 'Librarian'))
            ->view('emails.auth.welcome')
            ->with([
                'code' => $this->code,
                'magicLinkUrl' => $this->magicLinkUrl,
                'ttlMinutes' => $this->ttlMinutes,
                'recipientName' => $this->recipientName,
                'connectUrl' => $this->connectUrl,
                'serverAddress' => $this->serverAddress,
                'installOptions' => $this->installOptions,
            ]);
    }
}
