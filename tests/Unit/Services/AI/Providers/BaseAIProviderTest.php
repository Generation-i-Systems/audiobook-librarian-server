<?php

namespace Tests\Unit\Services\AI\Providers;

use App\Contracts\AI\AIProviderInterface;
use App\Services\AI\Providers\BaseAIProvider;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BaseAIProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function testCanMakeRequestReturnsTrueInitially(): void
    {
        $provider = $this->createTestProvider();

        $this->assertTrue($provider->canMakeRequest());
    }

    public function testCanMakeRequestReturnsFalseAfterRateLimit(): void
    {
        $provider = $this->createTestProvider([
            'requests_per_minute' => 2,
            'requests_per_day' => null,
        ]);

        // Make requests up to limit
        /** @phpstan-ignore-next-line method.notFound */
        $provider->trackRequestForTest();
        $this->assertTrue($provider->canMakeRequest());

        /** @phpstan-ignore-next-line method.notFound */
        $provider->trackRequestForTest();
        $this->assertFalse($provider->canMakeRequest());
    }

    public function testGetUsageStatsReturnsCorrectStructure(): void
    {
        $provider = $this->createTestProvider();

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('session_cost', $stats);
        $this->assertArrayHasKey('requests_this_minute', $stats);
        $this->assertArrayHasKey('requests_today', $stats);
        $this->assertArrayHasKey('rate_limits', $stats);
        $this->assertArrayHasKey('pricing', $stats);
    }

    public function testParseStructuredResponseHandlesValidJson(): void
    {
        $provider = $this->createTestProvider();

        $json = '{"title": "Test Book", "author": "Test Author"}';
        /** @phpstan-ignore-next-line method.notFound */
        $result = $provider->parseStructuredResponseForTest($json);

        $this->assertIsArray($result);
        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals('Test Author', $result['author']);
    }

    public function testParseStructuredResponseHandlesMarkdownCodeBlocks(): void
    {
        $provider = $this->createTestProvider();

        $json = "```json\n{\"title\": \"Test\"}\n```";
        /** @phpstan-ignore-next-line method.notFound */
        $result = $provider->parseStructuredResponseForTest($json);

        $this->assertIsArray($result);
        $this->assertEquals('Test', $result['title']);
    }

    public function testParseStructuredResponseReturnsNullForInvalidJson(): void
    {
        $provider = $this->createTestProvider();

        /** @phpstan-ignore-next-line method.notFound */
        $result = $provider->parseStructuredResponseForTest('invalid json');

        $this->assertNull($result);
    }

    public function testValidateAgainstSchemaReturnsTrueWhenAllRequiredFieldsPresent(): void
    {
        $provider = $this->createTestProvider();

        $data = ['title' => 'Test', 'author' => 'Author'];
        $schema = ['required' => ['title', 'author']];

        /** @phpstan-ignore-next-line method.notFound */
        $result = $provider->validateAgainstSchemaForTest($data, $schema);

        $this->assertTrue($result);
    }

    public function testValidateAgainstSchemaReturnsFalseWhenRequiredFieldMissing(): void
    {
        $provider = $this->createTestProvider();

        $data = ['title' => 'Test'];
        $schema = ['required' => ['title', 'author']];

        /** @phpstan-ignore-next-line method.notFound */
        $result = $provider->validateAgainstSchemaForTest($data, $schema);

        $this->assertFalse($result);
    }

    protected function createTestProvider(array $rateLimits = null): AIProviderInterface
    {
        return new class ($rateLimits) extends BaseAIProvider {
            public function __construct(?array $rateLimits = null)
            {
                $this->rateLimits = $rateLimits ?? [
                    'requests_per_minute' => 100,
                    'requests_per_day' => 1000,
                ];
                $this->pricing = [
                    'input_per_million' => 0.10,
                    'output_per_million' => 0.40,
                ];
            }

            public function getName(): string
            {
                return 'test';
            }

            public function getModel(): string
            {
                return 'test-model';
            }

            public function transcribe(string $audioPath): string
            {
                return 'test transcription';
            }

            protected function callAPI(string $prompt, array $options = []): array
            {
                return [
                    'success' => true,
                    'content' => 'test response',
                    'metadata' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 20]],
                ];
            }

            protected function callAPIWithSchema(string $prompt, array $schema, array $options = []): array
            {
                return [
                    'success' => true,
                    'content' => '{"result": "test"}',
                    'metadata' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 20]],
                ];
            }

            protected function calculateUsage(array $result): array
            {
                return [
                    'input_tokens' => 10,
                    'output_tokens' => 20,
                    'total_tokens' => 30,
                    'cost' => 0.001,
                ];
            }

            // Expose protected methods for testing
            public function trackRequestForTest(): void
            {
                $this->trackRequest();
            }

            public function parseStructuredResponseForTest(string $content): ?array
            {
                return $this->parseStructuredResponse($content);
            }

            public function validateAgainstSchemaForTest(array $data, array $schema): bool
            {
                return $this->validateAgainstSchema($data, $schema);
            }
        };
    }
}
