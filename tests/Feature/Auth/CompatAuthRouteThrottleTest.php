<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompatAuthRouteThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('127.0.0.1|POST|api/v1/auth/login');
    }

    #[Test]
    public function testCompatAuthLoginRouteIsThrottledLikePrimaryRoute(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);
        }

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);

        $response->assertStatus(429);
    }
}
