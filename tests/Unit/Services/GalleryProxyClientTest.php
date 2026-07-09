<?php

namespace Tests\Unit\Services;

use App\Auth\DocumentstoreUser;
use App\Exceptions\GalleryProxyUnavailableException;
use App\Models\User;
use App\Services\GalleryProxyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GalleryProxyClientTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Auth::user() on this app can return either a DocumentstoreUser
     * (session/Sanctum paths) or a plain Eloquent User (the legacy
     * api_tokens fallback in ApiAuth) depending on which guard authenticated
     * the request — this was caught live when proxying a real write through
     * the legacy token path threw a TypeError. Both must work.
     */
    public function test_post_as_user_builds_a_valid_trust_header_for_an_eloquent_user(): void
    {
        config([
            'services.gallery_www.base_url' => 'http://gallery-www.test',
            'services.gallery_www.trust_secret' => 'test-shared-secret',
        ]);

        Http::fake(['gallery-www.test/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['role' => 'user']);

        app(GalleryProxyClient::class)->postAsUser('/api/v1/skins/1/rate', ['rating' => 5], $user);

        Http::assertSent(function ($request) use ($user) {
            $header = $request->header('X-Gallery-Trust')[0] ?? null;
            if (! $header) {
                return false;
            }

            [$encodedPayload, $hmac] = explode('.', $header, 2);
            $payload = base64_decode($encodedPayload, true);
            $expectedHmac = hash_hmac('sha256', $payload, 'test-shared-secret');

            if (! hash_equals($expectedHmac, $hmac)) {
                return false;
            }

            [$userId, $email] = explode('|', $payload);

            return $userId === (string) $user->id && $email === $user->email;
        });
    }

    public function test_post_as_user_sends_a_valid_hmac_signed_trust_header(): void
    {
        config([
            'services.gallery_www.base_url' => 'http://gallery-www.test',
            'services.gallery_www.trust_secret' => 'test-shared-secret',
        ]);

        Http::fake(['gallery-www.test/*' => Http::response(['ok' => true], 200)]);

        $user = new DocumentstoreUser(['id' => 42, 'email' => 'user@example.com', 'role' => 'admin']);

        app(GalleryProxyClient::class)->postAsUser('/api/v1/skins/1/rate', ['rating' => 5], $user);

        Http::assertSent(function ($request) {
            $header = $request->header('X-Gallery-Trust')[0] ?? null;
            if (! $header) {
                return false;
            }

            [$encodedPayload, $hmac] = explode('.', $header, 2);
            $payload = base64_decode($encodedPayload, true);
            $expectedHmac = hash_hmac('sha256', $payload, 'test-shared-secret');

            if (! hash_equals($expectedHmac, $hmac)) {
                return false;
            }

            [$userId, $email, $role, $timestamp, $nonce] = explode('|', $payload);

            return $userId === '42'
                && $email === 'user@example.com'
                && $role === 'admin'
                && abs(time() - (int) $timestamp) < 5
                && strlen($nonce) > 0;
        });
    }

    public function test_get_does_not_attach_a_trust_header(): void
    {
        config(['services.gallery_www.base_url' => 'http://gallery-www.test']);

        Http::fake(['gallery-www.test/*' => Http::response(['ok' => true], 200)]);

        app(GalleryProxyClient::class)->get('/api/v1/skins');

        Http::assertSent(function ($request) {
            return $request->header('X-Gallery-Trust') === [];
        });
    }

    /**
     * Confirmed live: an unreachable www previously bubbled up as a raw
     * ConnectionException, producing an uncaught 500 instead of a clean
     * error. GalleryProxyClient must translate this so the app-wide handler
     * in bootstrap/app.php can render a 502.
     */
    public function test_a_connection_failure_is_translated_into_a_gallery_unavailable_exception(): void
    {
        config(['services.gallery_www.base_url' => 'http://127.0.0.1:1']);

        $this->expectException(GalleryProxyUnavailableException::class);

        app(GalleryProxyClient::class)->get('/api/v1/skins');
    }
}
