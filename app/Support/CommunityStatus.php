<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether this server offers community features, advertised to clients as
 * `community: {enabled, mode}`. "demo" is a demo install (app.demo_mode): the
 * app shows the screens with a "self-hosted servers only" message.
 */
final class CommunityStatus
{
    public const MODE_FULL = 'full';
    public const MODE_DEMO = 'demo';
    public const MODE_OFF = 'off';

    public static function mode(): string
    {
        if (!(bool) config('community.enabled', true)) {
            return self::MODE_OFF;
        }

        return (bool) config('app.demo_mode', false) ? self::MODE_DEMO : self::MODE_FULL;
    }

    /**
     * @return array{enabled: bool, mode: string}
     */
    public static function toArray(): array
    {
        $mode = self::mode();

        return ['enabled' => $mode === self::MODE_FULL, 'mode' => $mode];
    }
}
