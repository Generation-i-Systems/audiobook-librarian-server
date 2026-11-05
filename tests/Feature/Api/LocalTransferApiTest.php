<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\DataProvider;

class LocalTransferApiTest extends ApiTestCase
{
    private const FIXTURE_CERT = 'tests/fixtures/local-tls/cert.pem';

    // openssl x509 -in cert.pem -pubkey -noout | openssl pkey -pubin -outform der
    //   | openssl dgst -sha256 -binary | base64
    private const FIXTURE_PIN = '1iMLGFhHVeYreZqZcQHesAL+J6y6IzEK+t8K7YxXtL8=';

    private function configure(string $urls, ?string $cert = self::FIXTURE_CERT): void
    {
        config([
            'local_transfer.urls' => $urls,
            'local_transfer.cert_path' => $cert === null ? null : base_path($cert),
        ]);
    }

    public function testRouteListsTheLanAddressesAndTheCertificateKeyPin(): void
    {
        $this->configure('https://192.168.1.20:8443/, https://nas.lan:8443');

        $this->getJson('/api/v1/local-transfer')
            ->assertOk()
            ->assertExactJson([
                'localTransfer' => [
                    'urls' => ['https://192.168.1.20:8443', 'https://nas.lan:8443'],
                    'spkiSha256' => self::FIXTURE_PIN,
                ],
            ]);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function unusableSetups(): array
    {
        return [
            'no addresses' => ['', self::FIXTURE_CERT],
            'only plain http' => ['http://192.168.1.20:8080', self::FIXTURE_CERT],
            'not a url' => ['192.168.1.20:8443', self::FIXTURE_CERT],
            'no certificate' => ['https://192.168.1.20:8443', null],
            'missing certificate' => ['https://192.168.1.20:8443', 'tests/fixtures/local-tls/missing.pem'],
            'not a certificate' => ['https://192.168.1.20:8443', 'tests/fixtures/cover.jpg'],
        ];
    }

    #[DataProvider('unusableSetups')]
    public function testRouteIsNullWhenTheListenerIsNotUsable(string $urls, ?string $cert): void
    {
        $this->configure($urls, $cert);

        $this->getJson('/api/v1/local-transfer')
            ->assertOk()
            ->assertExactJson(['localTransfer' => null]);
    }

    public function testPlainHttpAddressesAreLeftOut(): void
    {
        $this->configure('http://192.168.1.20:8080,https://192.168.1.20:8443');

        $this->getJson('/api/v1/local-transfer')
            ->assertOk()
            ->assertJsonPath('localTransfer.urls', ['https://192.168.1.20:8443']);
    }

    public function testRouteRequiresSignIn(): void
    {
        $this->configure('https://192.168.1.20:8443');
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => ''])
            ->getJson('/api/v1/local-transfer')
            ->assertUnauthorized();
    }

    public function testCapabilityIsAdvertisedOnlyWhenTheListenerIsUsable(): void
    {
        $this->configure('');
        $this->getJson('/api/v1/health/capabilities')
            ->assertOk()
            ->assertJsonMissing(['LOCAL_TRANSFER']);

        $this->configure('https://192.168.1.20:8443');
        $capabilities = $this->getJson('/api/v1/health/capabilities')->json('capabilities');
        $this->assertContains('LOCAL_TRANSFER', $capabilities);
    }

    public function testCapabilitiesNeverRevealTheLanAddress(): void
    {
        $this->configure('https://192.168.1.20:8443');

        $body = $this->getJson('/api/v1/health/capabilities')->getContent();

        $this->assertStringNotContainsString('192.168.1.20', (string) $body);
    }
}
