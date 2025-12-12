<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewUserRegistrationNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public array $user;

    public array $meta;

    public string $editUserUrl;

    public ?string $verifyUserUrl;

    public string $usersIndexUrl;

    public function __construct(array $user, array $meta, string $editUserUrl, ?string $verifyUserUrl, string $usersIndexUrl)
    {
        $this->user = $user;
        $this->meta = $meta;
        $this->editUserUrl = $editUserUrl;
        $this->verifyUserUrl = $verifyUserUrl;
        $this->usersIndexUrl = $usersIndexUrl;
    }

    public function build(): self
    {
        $subject = 'New user registration: ' . ($this->user['email'] ?? 'unknown');

        return $this
            ->subject($subject)
            ->view('emails.users.new-registration');
    }
}
