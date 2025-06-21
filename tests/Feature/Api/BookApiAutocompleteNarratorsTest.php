<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Services\MongoService;
use Mockery;

class BookApiAutocompleteNarratorsTest extends TestCase
{
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
                'data' => ['Michael Kramer', 'Kramer Smith']
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
