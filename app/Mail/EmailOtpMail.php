<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailOtpMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $code;

    public string $magicLinkUrl;

    public int $ttlMinutes;

    public ?string $recipientName;

    public bool $isPasswordReset;

    public function __construct(
        string $code,
        string $magicLinkUrl,
        int $ttlMinutes,
        ?string $recipientName = null,
        bool $isPasswordReset = false,
    ) {
        $this->code = $code;
        $this->magicLinkUrl = $magicLinkUrl;
        $this->ttlMinutes = $ttlMinutes;
        $this->recipientName = $recipientName;
        $this->isPasswordReset = $isPasswordReset;
    }

    public function build(): self
    {
        $subject = $this->isPasswordReset ? 'Reset your password' : 'Your sign-in code';

        return $this
            ->subject($subject)
            ->view('emails.auth.otp')
            ->with([
                'code' => $this->code,
                'magicLinkUrl' => $this->magicLinkUrl,
                'ttlMinutes' => $this->ttlMinutes,
                'recipientName' => $this->recipientName,
                'isPasswordReset' => $this->isPasswordReset,
            ]);
    }
}
