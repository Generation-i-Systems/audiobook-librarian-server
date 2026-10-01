<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('api-token')->plainTextToken];
    }

    public function testPutUserUpdatesNameUsernameAndEmailAndReturnsTheUser(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $payload = ['name' => 'New Name', 'username' => 'new.name', 'email' => 'new.name@example.com'];

        $response = $this->withHeaders($this->bearer($user))->putJson('/api/v1/user', $payload);

        $response->assertOk()->assertJson(['id' => $user->id] + $payload);
        $this->assertSame($payload, $user->fresh()->only(['name', 'username', 'email']));
        $response->assertJsonMissing(['password' => $user->password]);
    }

    public function testPutUserAllowsPartialUpdates(): void
    {
        $user = User::factory()->create(['role' => 'library-user', 'name' => 'Old', 'username' => 'keepme']);

        $this->withHeaders($this->bearer($user))->putJson('/api/v1/user', ['name' => 'Only Name'])->assertOk();

        $this->assertSame(['Only Name', 'keepme'], [$user->fresh()->name, $user->fresh()->username]);
    }

    public function testPutUserIgnoresFieldsOutsideTheEditableSet(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $password = $user->password;

        $this->withHeaders($this->bearer($user))->putJson('/api/v1/user', [
            'name' => 'Name',
            'role' => 'admin',
            'password' => Hash::make('hacked'),
            'email_verified_at' => null,
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(['library-user', $password], [$fresh->role, $fresh->password]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'empty name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'empty username' => [['username' => ''], 'username'],
            'no fields' => [[], 'name'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function testPutUserRejectsInvalidPayloadsWith422(array $payload, string $field): void
    {
        $user = User::factory()->create(['role' => 'library-user']);

        $this->withHeaders($this->bearer($user))->putJson('/api/v1/user', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public function testPutUserRejectsAnEmailOrUsernameAlreadyUsedByAnotherUser(): void
    {
        $other = User::factory()->create(['role' => 'library-user']);
        $user = User::factory()->create(['role' => 'library-user']);

        $this->withHeaders($this->bearer($user))
            ->putJson('/api/v1/user', ['email' => $other->email, 'username' => $other->username])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'username']);
    }

    public function testPutUserAllowsKeepingYourOwnEmailAndUsername(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);

        $this->withHeaders($this->bearer($user))
            ->putJson('/api/v1/user', ['email' => $user->email, 'username' => $user->username, 'name' => 'Same'])
            ->assertOk();
    }

    public function testPutUserRequiresAuthentication(): void
    {
        $this->withHeaders(['Authorization' => ''])->putJson('/api/v1/user', ['name' => 'x'])->assertStatus(401);
    }

    public function testLoginReturnsAnIntegerId(): void
    {
        $user = User::factory()->create(['role' => 'library-user', 'password' => Hash::make('secret-pass-1')]);

        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret-pass-1']);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('id'));
    }
}
