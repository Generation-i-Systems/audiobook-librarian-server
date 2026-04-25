<?php

declare(strict_types=1);

namespace Tests\Api\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequireLibraryRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'library_profiles.profiles.librivox.hosts' => ['librivox.test'],
            'library_profiles.profiles.hybrid.hosts' => ['hybrid.test'],
        ]);
    }

    private function makeUser(string $role): array
    {
        $user = User::factory()->create(['role' => $role]);
        $token = $user->createToken('test')->plainTextToken;
        return [$user, ['Authorization' => 'Bearer ' . $token]];
    }

    private function getMe(array $headers, string $host = 'localhost'): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withHeaders($headers)
            ->getJson("http://{$host}/api/v1/me");
    }

    // All library roles are allowed regardless of host
    public function testLibraryUserCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('library-user');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    public function testLibrivoxUserCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('librivox-user');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    public function testHybridUserCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('hybrid-user');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    public function testAdminCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('admin');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    public function testSuperAdminCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('super-admin');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    // Disallowed roles are blocked regardless of host
    public function testUnverifiedUserIsBlocked(): void
    {
        [, $headers] = $this->makeUser('unverified');
        $this->getMe($headers)->assertStatus(403);
    }

    public function testUserRoleIsBlocked(): void
    {
        [, $headers] = $this->makeUser('user');
        $this->getMe($headers)->assertStatus(403);
    }

    public function testUnauthenticatedRequestIsBlocked(): void
    {
        $this->getJson('http://localhost/api/v1/me')->assertStatus(401);
    }
}
