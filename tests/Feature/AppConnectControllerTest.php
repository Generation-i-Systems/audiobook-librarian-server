<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AppConnectControllerTest extends TestCase
{
    public function testRedirectorShowsSelfHostedApiUrl(): void
    {
        $response = $this->get('/app/connect/server?apiUrl=' . rawurlencode('https://library.example.test/api/v1'));

        $response->assertOk();
        $response->assertSee('https://library.example.test/api/v1');
        $response->assertCookie('ablibrarian_connect_api_url', 'https://library.example.test/api/v1');
        $response->assertSee('ablibrarian://connect/server?apiUrl=https%3A%2F%2Flibrary.example.test%2Fapi%2Fv1', false);
        $response->assertSee('ablibrarian-library://connect/server?apiUrl=https%3A%2F%2Flibrary.example.test%2Fapi%2Fv1', false);
    }

    public function testLoginPageIncludesServerConnectQrData(): void
    {
        $response = $this->get('https://self-hosted.example.test/login');

        $response->assertOk();
        $response->assertSee('Connect the mobile app');
        $response->assertSee('https://self-hosted.example.test/api/v1');
        $response->assertSee('/app/connect/server?apiUrl=https%3A%2F%2Fself-hosted.example.test%2Fapi%2Fv1', false);
        $response->assertDontSee('cdn.jsdelivr.net/npm/qrcode');
    }
}
