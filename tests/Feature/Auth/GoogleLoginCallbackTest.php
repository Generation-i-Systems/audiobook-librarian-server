<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleLoginCallbackTest extends TestCase
{
    #[Test]
    public function testGoogleCallbackCastsExistingUserIdToStringForUpdateUserCall(): void
    {
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        $this->withoutExceptionHandling();

        $googleUser = new class () {
            public function getEmail(): string
            {
                return 'user@example.com';
            }

            public function getName(): string
            {
                return 'Example User';
            }

            public function getNickname(): null
            {
                return null;
            }

            public function getId(): string
            {
                return 'google-123';
            }

            public function getAvatar(): string
            {
                return 'https://example.com/avatar.png';
            }
        };

        $provider = $this->mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->once()
            ->andReturn($provider);

        $this->mock(DocumentStoreServiceInterface::class, function ($mock) {
            $mock->shouldReceive('getUserByEmail')
                ->once()
                ->with('user@example.com')
                ->andReturn(['id' => 123, 'email' => 'user@example.com']);

            $mock->shouldReceive('updateUser')
                ->once()
                ->with('123', \Mockery::type('array'))
                ->andReturn(true);

            $mock->shouldReceive('updateRememberToken')
                ->zeroOrMoreTimes()
                ->andReturnNull();

            $mock->shouldReceive('getUserByEmail')
                ->once()
                ->with('user@example.com')
                ->andReturn([
                    'id' => 123,
                    'email' => 'user@example.com',
                    'name' => 'Example User',
                    'role' => 'library-user',
                ]);
        });

        $response = $this->get('/login/google/callback');

        $response->assertRedirect('/home');
        $this->assertTrue(Auth::check());
    }
}
