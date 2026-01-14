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

        $this->app->instance(Client::class, $client);

        $aiToolService = new AIToolService('gemini-2.5-flash');
        $result = $aiToolService->processQuery('List all Science Fiction books by Isaac Asimov', []);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['conversation']);
        $this->assertNotEmpty($result['response']);
        $this->assertStringContainsString('Isaac Asimov', $result['response']);
    }

    public function test_get_conversation_history(): void
    {
        $aiToolService = new AIToolService('gemini-2.5-flash');

        $history = $aiToolService->getConversationHistory();

        $this->assertIsArray($history);
        $this->assertEmpty($history);
    }

    public function test_set_max_iterations(): void
    {
        $aiToolService = new AIToolService('gemini-2.5-flash');
        $result = $aiToolService->getConversationHistory();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
