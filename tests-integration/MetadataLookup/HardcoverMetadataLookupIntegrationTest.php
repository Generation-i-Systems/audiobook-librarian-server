<?php

namespace Tests\Integration\MetadataLookup;

use App\Auth\DocumentstoreUser;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HardcoverMetadataLookupIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        $userData = [
            'id' => 'test-admin-user',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'is_admin' => true,
            'permissions' => ['admin.books.*'],
        ];

        $user = new DocumentstoreUser($userData);
        Auth::login($user);
        $this->actingAs($user);

        $this->withoutMiddleware(\App\Http\Middleware\CheckAdminRole::class);

        $token = config('hardcover.api_token');
        if (empty($token)) {
            $this->markTestSkipped('HARDCOVER_API_TOKEN is not configured; skipping real Hardcover integration test.');
        }
    }

    #[Test]
    public function testHardcoverSearchReturnsResults(): void
    {
        $response = $this->getJson('/admin/books/search?source=hardcover&title=Dune&author=Frank+Herbert&limit=5');

        $response->assertStatus(200);

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        $first = $data[0];
        $this->assertIsArray($first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('author', $first);
        $this->assertIsArray($first['author']);
        $this->assertNotEmpty($first['author']);
        $this->assertArrayHasKey('apiId', $first);
        $this->assertArrayHasKey('source', $first);
        $this->assertEquals('Hardcover', $first['source']);
    }

    #[Test]
    public function testHardcoverDetailsLookupByApiIdWorks(): void
    {
        $searchResponse = $this->getJson('/admin/books/search?source=hardcover&title=Dune&author=Frank+Herbert&limit=3');
        $searchResponse->assertStatus(200);

        $results = json_decode($searchResponse->getContent(), true);
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);

        $apiId = $results[0]['apiId'] ?? null;
        $this->assertNotEmpty($apiId);

        $detailsResponse = $this->getJson('/admin/books/search?source=hardcover&api_id=' . urlencode((string) $apiId));
        $detailsResponse->assertStatus(200);

        $details = json_decode($detailsResponse->getContent(), true);
        $this->assertIsArray($details);
        $this->assertNotEmpty($details);

        $this->assertEquals($apiId, $details[0]['apiId'] ?? null);
        $this->assertArrayHasKey('title', $details[0]);
        $this->assertArrayHasKey('author', $details[0]);
    }
}
