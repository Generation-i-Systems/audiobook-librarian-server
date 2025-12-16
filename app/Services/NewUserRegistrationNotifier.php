<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\NewUserRegistrationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NewUserRegistrationNotifier
{
    private const ADMIN_EMAIL = 'eric.thelin+librarian@gmail.com';

    public function isSpamRegistration(array $userData, ?Request $request = null): bool
    {
        $fields = strtolower(implode(' ', [
            (string) ($userData['name'] ?? ''),
            (string) ($userData['username'] ?? ''),
            (string) ($userData['email'] ?? ''),
        ]));

        $spamKeywords = [
            'viagra',
            'casino',
            'porn',
            'xxx',
            'loan',
            'bitcoin',
            'crypto',
            'forex',
            'escort',
        ];

        foreach ($spamKeywords as $keyword) {
            if ($keyword !== '' && str_contains($fields, $keyword)) {
                return true;
            }
        }

        if (str_contains($fields, 'http://') || str_contains($fields, 'https://') || str_contains($fields, 'www.')) {
            return true;
        }

        if (strlen($fields) > 255) {
            return true;
        }

        if (preg_match('/(.)\\1{6,}/', $fields) === 1) {
            return true;
        }

        return false;
    }

    public function send(array $userData, string $source, ?Request $request = null): void
    {
        if ($this->isSpamRegistration($userData, $request)) {
            Log::info('Spam registration blocked, not sending notification email', [
                'email' => $userData['email'] ?? null,
                'username' => $userData['username'] ?? null,
                'source' => $source,
                'ip' => $request?->ip(),
            ]);

            return;
        }

        $userId = (string) ($userData['id'] ?? '');

        $editUserUrl = $userId !== '' ? route('admin.users.edit', $userId) : route('admin.users.index');
        $verifyUserUrl = $userId !== '' ? route('admin.users.verify', $userId) : null;
        $usersIndexUrl = route('admin.users.index');

        $meta = [
            'source' => $source,
            'ip' => $request?->ip(),
            'userAgent' => $request?->userAgent(),
            'requestedAt' => now(),
        ];

        $mailable = new NewUserRegistrationNotification($userData, $meta, $editUserUrl, $verifyUserUrl, $usersIndexUrl);

        try {
            $sesMailerConfig = config('mail.mailers.ses_smtp');
            $hasSesMailer = is_array($sesMailerConfig) && !empty($sesMailerConfig['host']);

            if ($hasSesMailer) {
                Mail::mailer('ses_smtp')->to(self::ADMIN_EMAIL)->send($mailable);
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send registration email via ses_smtp; falling back to default mailer', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            Mail::to(self::ADMIN_EMAIL)->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Failed to send registration email', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
