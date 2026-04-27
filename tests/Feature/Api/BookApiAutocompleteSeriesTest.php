<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use Mockery;
use Tests\TestCase;

class BookApiAutocompleteSeriesTest extends TestCase
{
    private $documentStoreMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\ApiAuth::class, \App\Http\Middleware\RequireStandardRole::class]);

        $user = new DocumentstoreUser(['id' => 'test-user', 'name' => 'Test User', 'email' => 'test@example.com', 'role' => 'library-user']);

        $this->documentStoreMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->documentStoreMock->shouldReceive('getUserById')->with('test-user')->andReturn($user->getRawUser());
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreMock);

        $this->actingAs($user, 'api_test');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_autocomplete_series_results()
    {
        $this->documentStoreMock->shouldReceive('autocompleteSeries')
            ->with('Super', 5)
            ->andReturn(['Super Powereds', 'Super Heroes']);

        $response = $this->getJson('/api/v1/series/autocomplete?query=Super&limit=5');

        $response->assertStatus(200)
            ->assertJson([
                'data' => ['Super Powereds', 'Super Heroes'],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_empty_array_for_empty_query()
    {
        $this->documentStoreMock->shouldReceive('autocompleteSeries')
            ->with('', 5)
            ->andReturn([]);

        $response = $this->getJson('/api/v1/series/autocomplete?query=');
        $response->assertStatus(200)
            ->assertJson(['data' => []]);
    }
}
