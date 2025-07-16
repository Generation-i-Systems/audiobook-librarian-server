<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Services\MongoService;
use Mockery;
use App\Auth\DocumentstoreUser;

class BookApiAutocompleteAuthorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $user = new DocumentstoreUser(['id' => 'test-user', 'name' => 'Test User', 'email' => 'test@example.com']);
        $this->actingAs($user, 'api_test');
    }

    /** @test */
    public function it_returns_autocomplete_authors_results()
    {
        $mock = Mockery::mock(MongoService::class);
        $mock->shouldReceive('autocompleteAuthors')
            ->with('Bran', 5)
            ->andReturn(['Brandon Sanderson', 'Bran Smith']);
        $this->app->instance(MongoService::class, $mock);

        $response = $this->getJson('/api/v1/authors/autocomplete?query=Bran&limit=5');

        $response->assertStatus(200)
            ->assertJson([
                'data' => ['Brandon Sanderson', 'Bran Smith']
            ]);
    }

    /** @test */
    public function it_returns_empty_array_for_empty_query()
    {
        $response = $this->getJson('/api/v1/authors/autocomplete?query=');
        $response->assertStatus(200)
            ->assertJson(['data' => []]);
    }
}
