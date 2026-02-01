<?php

namespace Tests\Unit\Services\AI\Providers;

use App\Services\AI\Providers\OpenAIProvider;
use Tests\TestCase;

class OpenAIProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.api_key' => 'test-key']);
    }

    public function testGetNameReturnsOpenai(): void
    {
        $provider = new OpenAIProvider();

        $this->assertEquals('openai', $provider->getName());
    }

    public function testGetModelReturnsConfiguredModel(): void
    {
        $provider = new OpenAIProvider('gpt-4o');

        $this->assertEquals('gpt-4o', $provider->getModel());
    }

    public function testGetModelDefaultsToGpt4oMini(): void
    {
        $provider = new OpenAIProvider();

        $this->assertEquals('gpt-4o-mini', $provider->getModel());
    }

    public function testRateLimitsAreConfiguredForPaidTier(): void
    {
        $provider = new OpenAIProvider();

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('rate_limits', $stats);
        $this->assertEquals(500, $stats['rate_limits']['requests_per_minute']);
        $this->assertNull($stats['rate_limits']['requests_per_day']);
    }

    public function testPricingIsConfiguredForGpt4oMini(): void
    {
        $provider = new OpenAIProvider('gpt-4o-mini');

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('pricing', $stats);
        $this->assertEquals(0.15, $stats['pricing']['input_per_million']);
        $this->assertEquals(0.60, $stats['pricing']['output_per_million']);
    }

    public function testPricingIsConfiguredForGpt4o(): void
    {
        $provider = new OpenAIProvider('gpt-4o');

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('pricing', $stats);
        $this->assertEquals(2.50, $stats['pricing']['input_per_million']);
        $this->assertEquals(10.00, $stats['pricing']['output_per_million']);
    }

    public function testPricingIsConfiguredForGpt4Turbo(): void
    {
        $provider = new OpenAIProvider('gpt-4-turbo');

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('pricing', $stats);
        $this->assertEquals(10.00, $stats['pricing']['input_per_million']);
        $this->assertEquals(30.00, $stats['pricing']['output_per_million']);
    }

    public function testPricingIsConfiguredForGpt35Turbo(): void
    {
        $provider = new OpenAIProvider('gpt-3.5-turbo');

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('pricing', $stats);
        $this->assertEquals(0.50, $stats['pricing']['input_per_million']);
        $this->assertEquals(1.50, $stats['pricing']['output_per_million']);
    }

    public function testCanMakeRequestReturnsTrueInitially(): void
    {
        $provider = new OpenAIProvider();

        $this->assertTrue($provider->canMakeRequest());
    }

    public function testGetUsageStatsReturnsCompleteStructure(): void
    {
        $provider = new OpenAIProvider();

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('session_cost', $stats);
        $this->assertArrayHasKey('requests_this_minute', $stats);
        $this->assertArrayHasKey('requests_today', $stats);
        $this->assertArrayHasKey('rate_limits', $stats);
        $this->assertArrayHasKey('pricing', $stats);
    }
}
