<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\DocumentUserProvider;
use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class DocumentUserProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): DocumentUserProvider
    {
        return new DocumentUserProvider(app(DocumentStoreServiceInterface::class));
    }

    public function testRehashPasswordLoggingDoesNotIncludeCredentials(): void
    {
        $log = Log::spy();

        $user = new User([
            'id' => 123,
            'email' => 'user@example.com',
            'password' => Hash::make('existing-password'),
        ]);

        $provider = $this->provider();
        $provider->rehashPasswordIfRequired($user, ['password' => 'plain-secret'], false);

        $log->shouldHaveReceived('debug')->with(
            'DocumentUserProvider::rehashPasswordIfRequired called',
            Mockery::on(function (array $context): bool {
                $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return ! str_contains($encodedContext, 'plain-secret')
                    && ! array_key_exists('credentials', $context)
                    && $context['has_password'] === true;
            })
        );
    }

    public function testRetrieveByIdReturnsEloquentUserModel(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);

        $retrieved = $this->provider()->retrieveById($user->id);

        $this->assertInstanceOf(User::class, $retrieved);
        $this->assertEquals($user->id, $retrieved->id);
        $this->assertEquals($user->email, $retrieved->email);
    }

    public function testRetrieveByIdExcludesSoftDeletedUsers(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertNull($this->provider()->retrieveById($user->id));
    }

    public function testRetrieveByTokenReturnsMatchingEloquentUser(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => 'plain-token-value'])->save();

        $retrieved = $this->provider()->retrieveByToken($user->id, 'plain-token-value');
        $this->assertInstanceOf(User::class, $retrieved);

        $this->assertNull($this->provider()->retrieveByToken($user->id, 'wrong-token'));
    }

    public function testRetrieveByCredentialsFindsUserByEmailOrUsername(): void
    {
        $user = User::factory()->create(['username' => 'dedicated-user', 'email' => 'dedicated@example.com']);

        $byEmail = $this->provider()->retrieveByCredentials(['email' => 'dedicated@example.com']);
        $byUsername = $this->provider()->retrieveByCredentials(['username' => 'dedicated-user']);

        $this->assertInstanceOf(User::class, $byEmail);
        $this->assertInstanceOf(User::class, $byUsername);
        $this->assertEquals($user->id, $byEmail->id);
        $this->assertEquals($user->id, $byUsername->id);

        $this->assertNull($this->provider()->retrieveByCredentials([]));
        $this->assertNull($this->provider()->retrieveByCredentials(['email' => 'nobody@example.com']));
    }

    public function testValidateCredentialsChecksTheHashedPassword(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->provider()->validateCredentials($user, ['password' => 'password']));
        $this->assertFalse($this->provider()->validateCredentials($user, ['password' => 'wrong-password']));
        $this->assertFalse($this->provider()->validateCredentials($user, []));
    }

    public function testUpdateRememberTokenPersistsToken(): void
    {
        $user = User::factory()->create();

        $provider = new DocumentUserProvider(app(DocumentStoreServiceInterface::class));
        $provider->updateRememberToken($user, 'fresh-remember-token');

        $this->assertEquals('fresh-remember-token', $user->fresh()->remember_token);
    }
}
