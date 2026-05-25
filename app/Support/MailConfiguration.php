<?php

declare(strict_types=1);

namespace App\Support;

class MailConfiguration
{
    public static function isMailConfigured(): bool
    {
        $default = config('mail.default');

        if (in_array($default, ['log', 'array'], true)) {
            return false;
        }

        $mailer = config('mail.mailers.' . $default);
        if (!is_array($mailer)) {
            return false;
        }

        $transport = $mailer['transport'] ?? '';

        if (in_array($transport, ['log', 'array'], true)) {
            return false;
        }

        if ($transport === 'smtp') {
            $host = $mailer['host'] ?? '';
            return !empty($host);
        }

        return true;
    }
}
