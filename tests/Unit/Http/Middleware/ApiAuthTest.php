<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\ApiAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    public function testDoesNotLogRawAuthorizationHeaderOrTokenFragments(): void
    {
        $log = Log::spy();

        $secretToken = 'secret-token-value-that-should-not-be-logged';
        $request = Request::create('/api/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $secretToken,
        ]);

        $middleware = new ApiAuth();
        $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $log->shouldHaveReceived('debug')->with(
            'ApiAuth middleware start',
            Mockery::on(fn (array $context): bool => $context === [
                'uri' => '/api/v1/me',
                'method' => 'GET',
                'ip' => '127.0.0.1',
                'has_auth_header' => true,
            ])
        );

        $log->shouldHaveReceived('info')->with(
            'Token details for debugging',
            Mockery::on(function (array $context) use ($secretToken): bool {
                $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return ! str_contains($encodedContext, $secretToken)
                    && ! array_key_exists('raw_auth_header', $context)
                    && ! array_key_exists('token_starts_with', $context)
                    && ! array_key_exists('token_ends_with', $context);
            })
        );
    }
}
