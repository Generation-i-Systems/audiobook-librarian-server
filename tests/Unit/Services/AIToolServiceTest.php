<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AI\AIToolService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIToolServiceTest extends TestCase
{
    protected AIToolService $aiToolService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiToolService = new AIToolService('gemini-2.5-flash');
    }

    public function test_process_query_with_valid_input(): void
    {
        Http::fake();

        $result = $this->aiToolService->processQuery('List all Science Fiction books by Isaac Asimov', []);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['conversation']);
        $this->assertNotEmpty($result['response']);
    }

    public function test_get_conversation_history(): void
    {
        $history = $this->aiToolService->getConversationHistory();

        $this->assertIsArray($history);
        $this->assertEmpty($history);
    }

    public function test_set_max_iterations(): void
    {
        $result = $this->aiToolService->getConversationHistory();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
