<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ClientAppInstallOptions;
use Tests\TestCase;

class ClientAppInstallOptionsTest extends TestCase
{
    private function configure(array $overrides): void
    {
        config(array_merge([
            'app.mobile_android_store_url' => '',
            'app.mobile_ios_store_url' => '',
            'app.mobile_android_beta_url' => 'https://play.google.com/apps/testing/com.ablibrarian.library',
            'app.mobile_ios_beta_url' => '',
            'app.desktop_download_url' => '',
            'app.mobile_beta' => 'auto',
        ], $overrides));
    }

    /** @return array<string, array<string, mixed>> */
    private function byPlatform(): array
    {
        $options = [];
        foreach (ClientAppInstallOptions::fromConfig() as $option) {
            $options[$option['platform']] = $option;
        }

        return $options;
    }

    public function test_offers_beta_channels_when_no_store_release_exists(): void
    {
        $this->configure([]);

        $options = $this->byPlatform();

        $this->assertSame(['Android', 'iPhone and iPad'], array_keys($options));
        $this->assertNull($options['Android']['storeUrl']);
        $this->assertSame('https://play.google.com/apps/testing/com.ablibrarian.library', $options['Android']['betaUrl']);
        $this->assertNotEmpty($options['Android']['betaSteps']);
        $this->assertNull($options['iPhone and iPad']['storeUrl']);
        $this->assertSame(ClientAppInstallOptions::TESTFLIGHT_APP_URL, $options['iPhone and iPad']['betaUrl']);
        $this->assertStringContainsString('invite', implode(' ', $options['iPhone and iPad']['betaSteps']));
    }

    public function test_auto_mode_hides_beta_when_store_release_exists(): void
    {
        $this->configure([
            'app.mobile_android_store_url' => 'https://play.google.com/store/apps/details?id=com.ablibrarian.library',
        ]);

        $android = $this->byPlatform()['Android'];

        $this->assertSame('https://play.google.com/store/apps/details?id=com.ablibrarian.library', $android['storeUrl']);
        $this->assertNull($android['betaUrl']);
        $this->assertSame([], $android['betaSteps']);
    }

    public function test_always_mode_offers_beta_next_to_store_release(): void
    {
        $this->configure([
            'app.mobile_android_store_url' => 'https://play.google.com/store/apps/details?id=com.ablibrarian.library',
            'app.mobile_ios_beta_url' => 'https://testflight.apple.com/join/AbCdEf',
            'app.mobile_beta' => 'always',
        ]);

        $options = $this->byPlatform();

        $this->assertNotNull($options['Android']['storeUrl']);
        $this->assertNotNull($options['Android']['betaUrl']);
        $this->assertSame('https://testflight.apple.com/join/AbCdEf', $options['iPhone and iPad']['betaUrl']);
        $this->assertStringNotContainsString('invite', implode(' ', $options['iPhone and iPad']['betaSteps']));
    }

    public function test_never_mode_leaves_out_platforms_without_store_release(): void
    {
        $this->configure([
            'app.mobile_ios_store_url' => 'https://apps.apple.com/app/id123',
            'app.mobile_beta' => 'never',
        ]);

        $options = $this->byPlatform();

        $this->assertSame(['iPhone and iPad'], array_keys($options));
        $this->assertNull($options['iPhone and iPad']['betaUrl']);
    }

    public function test_hash_placeholder_counts_as_no_store_release(): void
    {
        $this->configure(['app.mobile_ios_store_url' => '#']);

        $this->assertNull($this->byPlatform()['iPhone and iPad']['storeUrl']);
    }

    public function test_desktop_download_is_listed_only_when_configured(): void
    {
        $this->configure(['app.desktop_download_url' => 'https://example.com/releases/latest']);

        $desktop = $this->byPlatform()['Windows, macOS and Linux'];

        $this->assertSame('https://example.com/releases/latest', $desktop['storeUrl']);
        $this->assertNull($desktop['betaUrl']);
    }

    public function test_android_beta_can_be_turned_off_by_clearing_its_url(): void
    {
        $this->configure(['app.mobile_android_beta_url' => '']);

        $this->assertArrayNotHasKey('Android', $this->byPlatform());
    }
}
