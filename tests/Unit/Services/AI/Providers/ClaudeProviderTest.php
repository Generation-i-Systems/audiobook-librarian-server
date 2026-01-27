<?php

namespace Tests\Unit\Services\AI\Providers;

use App\Services\AI\Providers\ClaudeProvider;
use Tests\TestCase;

class ClaudeProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude.api_key' => 'test-key']);
    }

    public function testGetNameReturnsClaude(): void
    {
        $provider = new ClaudeProvider();

        $this->assertEquals('claude', $provider->getName());
    }

    public function testGetModelReturnsConfiguredModel(): void
    {
        $provider = new ClaudeProvider('claude-3-5-sonnet');

        $this->assertEquals('claude-3-5-sonnet', $provider->getModel());
    }

    public function testGetModelDefaultsToHaiku(): void
    {
        $provider = new ClaudeProvider();

        $this->assertEquals('claude-3-5-haiku', $provider->getModel());
    }

    public function testTranscribeReturnsNull(): void
    {
        $provider = new ClaudeProvider();

        $result = $provider->transcribe('/path/to/audio.mp3');

        $this->assertNull($result);
    }

    public function testRateLimitsAreConfiguredForPaidTier(): void
    {
        $provider = new ClaudeProvider();

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('rate_limits', $stats);
        $this->assertEquals(100, $stats['rate_limits']['requests_per_minute']);
        $this->assertNull($stats['rate_limits']['requests_per_day']);
    }

    public function testPricingIsConfiguredForHaiku(): void
    {
        $provider = new ClaudeProvider('claude-3-5-haiku');

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('pricing', $stats);
        $this->assertEquals(0.80, $stats['pricing']['input_per_million']);
        $this->assertEquals(4.00, $stats['pricing']['output_per_million']);
    }

    public function testPricingIsConfiguredForSonnet(): void
    {
        $provider = new ClaudeProvider('claude-3-5-sonnet');

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('pricing', $stats);
        $this->assertEquals(3.00, $stats['pricing']['input_per_million']);
        $this->assertEquals(15.00, $stats['pricing']['output_per_million']);
    }

    public function testPricingIsConfiguredForOpus(): void
    {
        $provider = new ClaudeProvider('claude-4-opus');

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('pricing', $stats);
        $this->assertEquals(15.00, $stats['pricing']['input_per_million']);
        $this->assertEquals(75.00, $stats['pricing']['output_per_million']);
    }

    public function testCanMakeRequestReturnsTrueInitially(): void
    {
        $provider = new ClaudeProvider();

        $this->assertTrue($provider->canMakeRequest());
    }

    public function testGetUsageStatsReturnsCompleteStructure(): void
    {
        $provider = new ClaudeProvider();

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('session_cost', $stats);
        $this->assertArrayHasKey('requests_this_minute', $stats);
        $this->assertArrayHasKey('requests_today', $stats);
        $this->assertArrayHasKey('rate_limits', $stats);
        $this->assertArrayHasKey('pricing', $stats);
    }
}
