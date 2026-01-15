<?php

namespace Tests\Unit\Services\AI;

use App\Contracts\AI\AIResponse;
use PHPUnit\Framework\TestCase;

class AIResponseTest extends TestCase
{
    public function testSuccessResponse(): void
    {
        $response = AIResponse::success('Test content', ['tokens' => 100]);

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->isFailure());
        $this->assertEquals('Test content', $response->getContent());
        $this->assertNull($response->getData());
        $this->assertNull($response->getError());
        $this->assertEquals(['tokens' => 100], $response->getUsage());
    }

    public function testSuccessWithDataResponse(): void
    {
        $data = ['title' => 'Test Book', 'author' => 'Test Author'];
        $response = AIResponse::successWithData($data, ['tokens' => 150]);

        $this->assertTrue($response->isSuccess());
        $this->assertEquals($data, $response->getData());
        $this->assertNull($response->getContent());
    }

    public function testFailureResponse(): void
    {
        $response = AIResponse::failure('API error', ['attempt' => 1]);

        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->isFailure());
        $this->assertEquals('API error', $response->getError());
        $this->assertNull($response->getContent());
        $this->assertNull($response->getData());
    }

    public function testGetCostReturnsZeroWhenNotSet(): void
    {
        $response = AIResponse::success('content');

        $this->assertEquals(0.0, $response->getCost());
    }

    public function testGetCostFromUsage(): void
    {
        $response = AIResponse::success('content', ['cost' => 0.0042]);

        $this->assertEquals(0.0042, $response->getCost());
    }
}
