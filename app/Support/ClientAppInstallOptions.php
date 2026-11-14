<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where people can install the client apps from, as configured for this server.
 *
 * A store link left empty (or "#") means there is no production release on that
 * store yet. `app.mobile_beta` decides when the beta channels (Google Play test
 * track, TestFlight) are offered: "auto" only for a platform without a store
 * release, "always" next to it as well, "never" not at all.
 *
 * @phpstan-type InstallOption array{
 *     platform: string,
 *     storeUrl: ?string,
 *     storeLabel: string,
 *     betaIntro: string,
 *     betaUrl: ?string,
 *     betaLabel: string,
 *     betaSteps: list<string>
 * }
 */
final class ClientAppInstallOptions
{
    public const BETA_AUTO = 'auto';
    public const BETA_ALWAYS = 'always';
    public const BETA_NEVER = 'never';

    public const TESTFLIGHT_APP_URL = 'https://apps.apple.com/app/testflight/id899247664';

    /**
     * @return list<InstallOption>
     */
    public static function fromConfig(): array
    {
        $betaMode = strtolower(trim((string) config('app.mobile_beta', self::BETA_AUTO)));
        $options = [];

        $androidStoreUrl = self::url(config('app.mobile_android_store_url'));
        $androidBetaUrl = self::url(config('app.mobile_android_beta_url'));
        $android = self::option('Android', $androidStoreUrl, 'Get it on Google Play');
        if ($androidBetaUrl !== null && self::offersBeta($betaMode, $androidStoreUrl)) {
            $android['betaUrl'] = $androidBetaUrl;
            $android['betaLabel'] = 'Join the Android beta';
            $android['betaSteps'] = [
                'Open the link below on your Android phone or tablet, signed in to Google Play with the Google '
                    . 'account the admin of this server added as a tester.',
                'Tap "Become a tester", then follow the link to Google Play and install the app.',
                'If Google Play says the app is not available, ask the admin of this server to add your Google '
                    . 'account to the beta.',
            ];
        }
        $options[] = $android;

        $iosStoreUrl = self::url(config('app.mobile_ios_store_url'));
        $iosBetaUrl = self::url(config('app.mobile_ios_beta_url'));
        $ios = self::option('iPhone and iPad', $iosStoreUrl, 'Download on the App Store');
        if (self::offersBeta($betaMode, $iosStoreUrl)) {
            if ($iosBetaUrl !== null) {
                $ios['betaUrl'] = $iosBetaUrl;
                $ios['betaLabel'] = 'Join the TestFlight beta';
                $ios['betaSteps'] = [
                    'Install Apple\'s TestFlight app from the App Store.',
                    'Open the link below on your iPhone or iPad, tap Accept, then Install.',
                ];
            } else {
                $ios['betaUrl'] = self::TESTFLIGHT_APP_URL;
                $ios['betaLabel'] = 'Get TestFlight';
                $ios['betaSteps'] = [
                    'Install Apple\'s TestFlight app from the App Store with the button below.',
                    'Ask the admin of this server to invite the email address of your Apple Account to the '
                        . 'TestFlight beta.',
                    'Open the invite email from Apple on your iPhone or iPad and tap "View in TestFlight", '
                        . 'then Install.',
                ];
            }
        }
        $options[] = $ios;

        $options[] = self::option(
            'Windows, macOS and Linux',
            self::url(config('app.desktop_download_url')),
            'Download for desktop'
        );

        return array_values(array_filter(
            $options,
            static fn (array $option): bool => $option['storeUrl'] !== null || $option['betaUrl'] !== null
        ));
    }

    /**
     * @return InstallOption
     */
    private static function option(string $platform, ?string $storeUrl, string $storeLabel): array
    {
        return [
            'platform' => $platform,
            'storeUrl' => $storeUrl,
            'storeLabel' => $storeLabel,
            'betaIntro' => $storeUrl === null ? 'The app is not in the store yet. Install the beta:' : 'Want new features before everyone else? Install the beta instead:',
            'betaUrl' => null,
            'betaLabel' => '',
            'betaSteps' => [],
        ];
    }

    private static function offersBeta(string $betaMode, ?string $storeUrl): bool
    {
        return match ($betaMode) {
            self::BETA_ALWAYS => true,
            self::BETA_NEVER => false,
            default => $storeUrl === null,
        };
    }

    private static function url(mixed $value): ?string
    {
        $url = trim((string) $value);

        return $url === '' || $url === '#' ? null : $url;
    }
}
