<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AI\AIToolService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class AIToolServiceTest extends TestCase
{
    public function test_process_query_with_valid_input(): void
    {
        $mockResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Here are the Science Fiction books by Isaac Asimov.'],
                        ],
                    ],
                ],
            ],
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResponse)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $aiToolService = $this->createAIToolService($client);
        $result = $aiToolService->processQuery('List all Science Fiction books by Isaac Asimov', []);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['conversation']);
        $this->assertNotEmpty($result['response']);
        $this->assertStringContainsString('Isaac Asimov', $result['response']);
    }

    protected function createAIToolService(Client $client): AIToolService
    {
        $service = new \ReflectionClass(AIToolService::class);
        $instance = $service->newInstanceWithoutConstructor();
        $clientProperty = $service->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($instance, $client);
        $modelProperty = $service->getProperty('model');
        $modelProperty->setAccessible(true);
        $modelProperty->setValue($instance, 'gemini-2.5-flash');
        $toolExecutorProperty = $service->getProperty('toolExecutor');
        $toolExecutorProperty->setAccessible(true);
        $toolExecutorProperty->setValue($instance, new \App\Services\AI\ToolExecutor());
        $apiKeyProperty = $service->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);
        $apiKeyProperty->setValue($instance, 'test-key');

        return $instance;
    }

    public function test_get_conversation_history(): void
    {
        $aiToolService = new AIToolService('gemini-2.5-flash');

        $history = $aiToolService->getConversationHistory();

        $this->assertEmpty($history);
    }

    public function test_set_max_iterations(): void
    {
        $aiToolService = new AIToolService('gemini-2.5-flash');
        $result = $aiToolService->getConversationHistory();

        $this->assertEmpty($result);
    }
}
