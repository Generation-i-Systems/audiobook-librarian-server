<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use Mockery;
use Tests\TestCase;

class BookApiAutocompleteAuthorsTest extends TestCase
{
    private $documentStoreMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\RequireStandardRole::class]);

        $user = new DocumentstoreUser(['id' => 'test-user', 'name' => 'Test User', 'email' => 'test@example.com', 'role' => 'library-user']);

        // Mock the DocumentStoreServiceInterface to return our test user's attributes
        $this->documentStoreMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->documentStoreMock->shouldReceive('getUserById')->with('test-user')->andReturn($user->getRawUser());
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreMock);

        $this->actingAs($user, 'api_test');
        $this->withHeader('Accept', 'application/json');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testReturnsAutocompleteAuthorsResults()
    {
        $this->documentStoreMock->shouldReceive('autocompleteAuthors')
            ->with('Bran', 5)
            ->andReturn(['Brandon Sanderson', 'Bran Smith']);

        $response = $this->getJson('/api/v1/authors/autocomplete?query=Bran&limit=5');

        $response->assertStatus(200)
            ->assertJson([
                'data' => ['Brandon Sanderson', 'Bran Smith'],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testReturnsEmptyArrayForEmptyQuery()
    {
        $this->documentStoreMock->shouldReceive('autocompleteAuthors')
            ->with('', 5)
            ->andReturn([]);

        $response = $this->getJson('/api/v1/authors/autocomplete?query=');
        $response->assertStatus(200)
            ->assertJson(['data' => []]);
    }
}
