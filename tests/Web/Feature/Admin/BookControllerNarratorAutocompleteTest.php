<?php

namespace Tests\Web\Feature\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Middleware\CheckAdminRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(\App\Http\Controllers\Admin\BookController::class)]
class BookControllerNarratorAutocompleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set a valid app key for encryption
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        $this->withoutMiddleware(CheckAdminRole::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test narrator autocomplete functionality.
     *
     * @return void
     */
    public function test_narrator_autocomplete()
    {
        // Create mock admin user that implements Authenticatable
        /** @var \Illuminate\Contracts\Auth\Authenticatable|\Mockery\MockInterface $user */
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('test-user-id');
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('hashed-password');
        $user->shouldReceive('getRememberToken')->andReturn('remember-token');
        $user->shouldReceive('setRememberToken')->andReturnNull();
        $user->shouldReceive('getRememberTokenName')->andReturn('remember_token');
        $user->shouldReceive('hasPermissionTo')->with('admin.books.*')->andReturn(true);

        // Mock the document store service
        $mockDocumentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockDocumentStoreService->shouldReceive('searchNarratorsByName')
            ->once()
            ->with('Test')
            ->andReturn(['Test Narrator 1', 'Test Narrator 2']); // Return array of narrators

        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocumentStoreService);

        // Login as admin user
        $this->actingAs($user);

        // Make request to narrator autocomplete endpoint
        $response = $this->getJson(route('admin.books.autocomplete.narrators', ['term' => 'Test']));

        // Assert response structure and content
        $response->assertStatus(200)
            ->assertJson(['Test Narrator 1', 'Test Narrator 2']);
    }

    /**
     * Test narrator autocomplete with empty term.
     *
     * @return void
     */
    public function test_narrator_autocomplete_with_empty_term()
    {
        // Create mock admin user that implements Authenticatable
        /** @var \Illuminate\Contracts\Auth\Authenticatable|\Mockery\MockInterface $user */
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('test-user-id');
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('hashed-password');
        $user->shouldReceive('getRememberToken')->andReturn('remember-token');
        $user->shouldReceive('setRememberToken')->andReturnNull();
        $user->shouldReceive('getRememberTokenName')->andReturn('remember_token');
        $user->shouldReceive('hasPermissionTo')->with('admin.books.*')->andReturn(true);

        // Mock the document store service
        $mockDocumentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockDocumentStoreService->shouldReceive('searchNarratorsByName')
            ->never();

        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocumentStoreService);

        // Login as admin user
        $this->actingAs($user);

        // Make request to narrator autocomplete endpoint with empty term
        $response = $this->getJson(route('admin.books.autocomplete.narrators', ['term' => '']));

        // Assert empty response
        $response->assertStatus(200)
            ->assertJson([]);
    }
}
