<?php

namespace Tests\Api\Unit\Controllers;

use App\Http\Controllers\Api\UserApiController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;
use Mockery;

class UserApiControllerTest extends TestCase
{
    protected UserApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new UserApiController();
    }

    /**
     * Test that the me method returns user name and email.
     */
    public function testMeMethodReturnsUserNameAndEmail(): void
    {
        // Create a simple user object
        $user = (object) [
            'name' => 'Jane Smith',
            'email' => 'jane.smith@example.com',
        ];

        // Create a mock request
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')
            ->once()
            ->andReturn($user);

        // Call the me method
        $response = $this->controller->me($request);

        // Assert the response is a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);

        // Assert the response status is 200
        $this->assertEquals(200, $response->getStatusCode());

        // Assert the response data contains only name and email
        $responseData = $response->getData(true);
        $this->assertEquals([
            'name' => 'Jane Smith',
            'email' => 'jane.smith@example.com',
        ], $responseData);
    }

    /**
     * Test that the me method handles null name gracefully.
     */
    public function testMeMethodHandlesNullName(): void
    {
        // Create a simple user object with null name
        $user = (object) [
            'name' => null,
            'email' => 'no.name@example.com',
        ];

        // Create a mock request
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')
            ->once()
            ->andReturn($user);

        // Call the me method
        $response = $this->controller->me($request);

        // Assert the response is a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);

        // Assert the response status is 200
        $this->assertEquals(200, $response->getStatusCode());

        // Assert the response data contains null name and correct email
        $responseData = $response->getData(true);
        $this->assertEquals([
            'name' => null,
            'email' => 'no.name@example.com',
        ], $responseData);
    }

    /**
     * Test that the me method handles empty email gracefully.
     */
    public function testMeMethodHandlesEmptyEmail(): void
    {
        // Create a simple user object with empty email
        $user = (object) [
            'name' => 'Test User',
            'email' => '',
        ];

        // Create a mock request
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')
            ->once()
            ->andReturn($user);

        // Call the me method
        $response = $this->controller->me($request);

        // Assert the response is a JsonResponse
        $this->assertInstanceOf(JsonResponse::class, $response);

        // Assert the response status is 200
        $this->assertEquals(200, $response->getStatusCode());

        // Assert the response data contains name and empty email
        $responseData = $response->getData(true);
        $this->assertEquals([
            'name' => 'Test User',
            'email' => '',
        ], $responseData);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
