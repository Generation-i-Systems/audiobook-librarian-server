<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Auth\DocumentstoreUser;
use App\Services\MongoService;
use Mockery;
use Tests\TestCase;

class BookApiAutocompleteNarratorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $user = new DocumentstoreUser(['id' => 'test-user', 'name' => 'Test User', 'email' => 'test@example.com']);
        $this->actingAs($user, 'api_test');
    }

    /** @test */
    public function it_returns_autocomplete_narrators_results()
    {
        $mock = Mockery::mock(MongoService::class);
        $mock->shouldReceive('autocompleteNarrators')
            ->with('Kramer', 5)
            ->andReturn(['Michael Kramer', 'Kramer Smith']);
        $this->app->instance(MongoService::class, $mock);

        $response = $this->getJson('/api/v1/narrators/autocomplete?query=Kramer&limit=5');

        $response->assertStatus(200)
            ->assertJson([
                'data' => ['Michael Kramer', 'Kramer Smith'],
            ]);
    }

    /** @test */
    public function it_returns_empty_array_for_empty_query()
    {
        $response = $this->getJson('/api/v1/narrators/autocomplete?query=');
        $response->assertStatus(200)
            ->assertJson(['data' => []]);
    }
}
