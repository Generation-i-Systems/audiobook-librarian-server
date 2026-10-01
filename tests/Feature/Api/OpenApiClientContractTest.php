<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiSchemaValidator;
use Tests\TestCase;

/**
 * Calls the endpoints the mobile client depends on and validates each real response
 * against the schema docs/openapi.json documents for that status code.
 */
class OpenApiClientContractTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-1';

    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spec = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertMatchesSpec(TestResponse $response, string $method, string $path): void
    {
        $status = (string) $response->getStatusCode();
        $documented = $this->spec['paths'][$path][strtolower($method)]['responses'] ?? [];
        $this->assertArrayHasKey($status, $documented, "{$method} {$path} returned undocumented status {$status}");

        $validator = new OpenApiSchemaValidator($this->spec);
        $schema = $validator->resolve($documented[$status])['content']['application/json']['schema'] ?? null;
        $this->assertNotNull($schema, "{$method} {$path} {$status} documents no JSON schema");
        $this->assertSame([], $validator->validate($response->json(), $schema), "{$method} {$path} {$status}");
    }

    private function user(): User
    {
        return User::factory()->create(['role' => 'library-user', 'password' => Hash::make(self::PASSWORD)]);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('api-token')->plainTextToken];
    }

    public function testLogin(): void
    {
        $user = $this->user();

        $ok = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->assertMatchesSpec($ok, 'POST', '/login');
        $this->assertSame($user->id, $ok->json('id'));

        $this->assertMatchesSpec($this->postJson('/api/v1/login', ['email' => $user->email]), 'POST', '/login');
        $this->assertMatchesSpec(
            $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong-password']),
            'POST',
            '/login'
        );
    }

    public function testAuthPrefixedLoginMatchesTheSameContract(): void
    {
        $user = $this->user();

        $this->assertMatchesSpec(
            $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => self::PASSWORD]),
            'POST',
            '/auth/login'
        );
    }

    public function testRefreshAndLogout(): void
    {
        $user = $this->user();
        $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => self::PASSWORD])->json();

        $refresh = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $login['refreshToken']]);
        $this->assertMatchesSpec($refresh, 'POST', '/auth/refresh');

        $logout = $this->withHeaders(['Authorization' => 'Bearer ' . $login['authToken']])->postJson('/api/v1/auth/logout');
        $this->assertMatchesSpec($logout, 'POST', '/auth/logout');
    }

    public function testRegister(): void
    {
        $ok = $this->postJson('/api/v1/register', [
            'name' => 'New Person',
            'username' => 'newperson',
            'email' => 'newperson@example.com',
            'password' => self::PASSWORD,
        ]);
        $this->assertMatchesSpec($ok, 'POST', '/register');

        $this->assertMatchesSpec($this->postJson('/api/v1/register', ['name' => 'x']), 'POST', '/register');
    }

    public function testForgotPassword(): void
    {
        $this->assertMatchesSpec(
            $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com']),
            'POST',
            '/forgot-password'
        );
        $this->assertMatchesSpec($this->postJson('/api/v1/forgot-password', []), 'POST', '/forgot-password');
    }

    public function testGetAndUpdateUser(): void
    {
        $user = $this->user();

        $this->assertMatchesSpec($this->withHeaders($this->bearer($user))->getJson('/api/v1/user'), 'GET', '/user');

        $update = $this->withHeaders($this->bearer($user))->putJson('/api/v1/user', ['name' => 'Renamed']);
        $this->assertMatchesSpec($update, 'PUT', '/user');
        $this->assertSame([$user->id, 'Renamed'], [$update->json('id'), $update->json('name')]);
        $this->assertMatchesSpec(
            $this->withHeaders($this->bearer($user))->putJson('/api/v1/user', ['email' => 'not-an-email']),
            'PUT',
            '/user'
        );
    }

    public function testUnauthenticatedRequestsGetTheDocumentedUnauthorizedBody(): void
    {
        $response = $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/user');

        $this->assertMatchesSpec($response, 'GET', '/user');
    }
}
