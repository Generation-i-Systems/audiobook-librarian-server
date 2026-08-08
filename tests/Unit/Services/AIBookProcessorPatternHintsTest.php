<?php

namespace Tests\Unit\Services;

use App\Services\AIBookProcessor;
use Tests\TestCase;

class AIBookProcessorPatternHintsTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function buildPromptIncludesPatternHintsWhenProvided(): void
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite', false);

        $ref = new \ReflectionClass($processor);
        $method = $ref->getMethod('buildPrompt');
        $method->setAccessible(true);

        $hint = 'The AI previously proposed Title "A" / Series "B" ... user swapped them to Title "B" / Series "A".';

        $prompt = $method->invoke($processor, '/downloads/Some Series/Book B', [], [], null, [$hint]);

        $this->assertStringContainsString("LEARN THIS DIRECTORY'S FOLDER-NAME-TO-FIELDS PARSING PATTERN", $prompt);
        $this->assertStringContainsString($hint, $prompt);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function buildPromptOmitsPatternHintsSectionWhenNoneProvided(): void
    {
        $processor = new AIBookProcessor('gemini-2.5-flash-lite', false);

        $ref = new \ReflectionClass($processor);
        $method = $ref->getMethod('buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($processor, '/downloads/Some Series/Book B', [], [], null, []);

        $this->assertStringNotContainsString("LEARN THIS DIRECTORY'S FOLDER-NAME-TO-FIELDS PARSING PATTERN", $prompt);
    }
}
