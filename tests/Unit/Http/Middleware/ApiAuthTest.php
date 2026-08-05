<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\ApiAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function testSanctumTokenAuthRecordsLastUsedAt(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('test-token')->plainTextToken;

        $request = Request::create('/api/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainTextToken,
        ]);

        $middleware = new ApiAuth();
        $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
        $token = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->first();
        $this->assertNotNull($token->last_used_at);
    }

    public function testLegacyApiTokenAuthRecordsLastUsedAt(): void
    {
        $user = User::factory()->create();
        $plainToken = 'legacy-plain-token-value';
        DB::table('api_tokens')->insert([
            'user_id' => (string) $user->id,
            'token' => $plainToken,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainToken,
        ]);

        $middleware = new ApiAuth();
        $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $row = DB::table('api_tokens')->where('token', $plainToken)->first();
        $this->assertNotNull($row->last_used_at);
    }

    public function testSanctumTokenAuthDoesNotRewriteLastUsedAtWithinThrottleWindow(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('test-token')->plainTextToken;

        $recentlyUsed = now()->subMinutes(2);
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->update([
            'last_used_at' => $recentlyUsed,
        ]);

        $request = Request::create('/api/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainTextToken,
        ]);

        (new ApiAuth())->handle($request, fn () => response()->json(['ok' => true]));

        $token = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->first();
        $this->assertSame($recentlyUsed->toDateTimeString(), Carbon::parse($token->last_used_at)->toDateTimeString());
    }

    public function testSanctumTokenAuthRewritesLastUsedAtAfterThrottleWindow(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('test-token')->plainTextToken;

        $staleUsed = now()->subMinutes(10);
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->update([
            'last_used_at' => $staleUsed,
        ]);

        $request = Request::create('/api/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainTextToken,
        ]);

        (new ApiAuth())->handle($request, fn () => response()->json(['ok' => true]));

        $token = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->first();
        $this->assertNotSame($staleUsed->toDateTimeString(), Carbon::parse($token->last_used_at)->toDateTimeString());
    }

    public function testLegacyApiTokenAuthDoesNotRewriteLastUsedAtWithinThrottleWindow(): void
    {
        $user = User::factory()->create();
        $plainToken = 'legacy-plain-token-value';
        $recentlyUsed = now()->subMinutes(2);
        DB::table('api_tokens')->insert([
            'user_id' => (string) $user->id,
            'token' => $plainToken,
            'last_used_at' => $recentlyUsed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/api/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainToken,
        ]);

        (new ApiAuth())->handle($request, fn () => response()->json(['ok' => true]));

        $row = DB::table('api_tokens')->where('token', $plainToken)->first();
        $this->assertSame($recentlyUsed->toDateTimeString(), Carbon::parse($row->last_used_at)->toDateTimeString());
    }

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
