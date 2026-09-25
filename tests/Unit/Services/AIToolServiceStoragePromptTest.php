<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AI\AIToolService;
use Tests\TestCase;

class AIToolServiceStoragePromptTest extends TestCase
{
    public function testPromptUsesConfiguredBookRoot(): void
    {
        config(['app.book_root' => '/tmp/portable-books']);
        $service = new class () extends AIToolService {
            public function prompt(): string
            {
                return $this->buildSystemPrompt('Find books', []);
            }
        };

        $this->assertStringContainsString('Book files are stored in: /tmp/portable-books', $service->prompt());
    }
}
