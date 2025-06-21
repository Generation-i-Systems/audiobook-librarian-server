<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Services\MongoService;
use Mockery;

class BookApiAutocompleteSeriesTest extends TestCase
{
    /** @test */
    public function it_returns_autocomplete_series_results()
    {
        $mock = Mockery::mock(MongoService::class);
        $mock->shouldReceive('autocompleteSeries')
            ->with('Super', 5)
            ->andReturn(['Super Powereds', 'Super Heroes']);
        $this->app->instance(MongoService::class, $mock);

        $response = $this->getJson('/api/v1/series/autocomplete?query=Super&limit=5');

        $response->assertStatus(200)
            ->assertJson([
                'data' => ['Super Powereds', 'Super Heroes']
            ]);
    }

    /** @test */
    public function it_returns_empty_array_for_empty_query()
    {
        $response = $this->getJson('/api/v1/series/autocomplete?query=');
        $response->assertStatus(200)
            ->assertJson(['data' => []]);
    }
}
